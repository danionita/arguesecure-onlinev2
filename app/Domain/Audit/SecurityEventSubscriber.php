<?php

namespace App\Domain\Audit;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records the security events that happen in code this package does not own
 * (security.md §11), from events the framework and the models already fire,
 * so no controller needs a log line of its own:
 *
 *  - sign-in, a failed or refused one, a lockout and sign-out, from Laravel's
 *    auth events;
 *  - account changes — created, renamed, made or unmade admin, deactivated,
 *    a password changed or reset, deleted — from the User model's events,
 *    which every path fires (the admin area, the CSV import, first-run setup,
 *    the profile page);
 *  - an assessment made public or private, from the Assessment model's;
 *  - every 403 and every export, from the request lifecycle;
 *  - whatever another package reports through SecurityEventOccurred.
 *
 * Registered in AppServiceProvider.
 */
class SecurityEventSubscriber
{
    /**
     * The export routes (routes/web.php, W74) and the format each produces.
     * Who took a copy of an assessment is what an auditor asks first.
     */
    private const EXPORT_ROUTES = [
        'assessments.report.export-html' => 'html',
        'assessments.report.defences-csv' => 'csv',
        'assessments.report.export-json' => 'json',
        'assessments.report.export-excel' => 'xlsx',
        'assessments.report.export-word' => 'docx',
    ];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'onLogin']);
        $events->listen(Failed::class, [self::class, 'onFailed']);
        $events->listen(Lockout::class, [self::class, 'onLockout']);
        $events->listen(Logout::class, [self::class, 'onLogout']);
        $events->listen(RequestHandled::class, [self::class, 'onRequestHandled']);
        $events->listen('eloquent.created: '.User::class, [self::class, 'onUserCreated']);
        $events->listen('eloquent.updated: '.User::class, [self::class, 'onUserUpdated']);
        $events->listen('eloquent.deleted: '.User::class, [self::class, 'onUserDeleted']);
        $events->listen('eloquent.updated: '.Assessment::class, [self::class, 'onAssessmentUpdated']);
        $events->listen(SecurityEventOccurred::class, [self::class, 'onSecurityEvent']);
    }

    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User || self::signInParkedForSecondFactor()) {
            return;
        }

        // AuthenticatedSessionController signs a deactivated account in and
        // straight out again, answering as for a wrong password; the log says
        // what happened.
        if (! $event->user->is_active) {
            SecurityLog::record(SecurityEvent::LoginFailed, null, SecurityLog::user($event->user), ['reason' => 'inactive']);

            return;
        }

        SecurityLog::record(SecurityEvent::LoginSucceeded, $event->user, SecurityLog::user($event->user));
    }

    public function onFailed(Failed $event): void
    {
        $target = $event->user instanceof User
            ? SecurityLog::user($event->user)
            : self::attemptedAccount($event->credentials['email'] ?? null);

        SecurityLog::record(SecurityEvent::LoginFailed, null, $target, ['reason' => 'credentials']);
    }

    public function onLockout(Lockout $event): void
    {
        SecurityLog::record(SecurityEvent::Lockout, null, self::attemptedAccount($event->request->input('email')));
    }

    public function onLogout(Logout $event): void
    {
        // The second half of a refused sign-in (onLogin), not a sign-out.
        if (! $event->user instanceof User || ! $event->user->is_active || self::signInParkedForSecondFactor()) {
            return;
        }

        SecurityLog::record(SecurityEvent::Logout, $event->user, SecurityLog::user($event->user));
    }

    public function onRequestHandled(RequestHandled $event): void
    {
        $request = $event->request;
        $status = $event->response->getStatusCode();
        /** @var User|null $user */
        $user = $request->user();

        if ($status === 403) {
            SecurityLog::record(SecurityEvent::AccessDenied, $user, self::routeAssessment($request), [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
            ]);

            return;
        }

        $format = self::EXPORT_ROUTES[$request->route()?->getName() ?? ''] ?? null;
        $assessment = $request->route('assessment');

        if ($format === null || $status !== 200 || ! $assessment instanceof Assessment) {
            return;
        }

        SecurityLog::record(SecurityEvent::AssessmentExported, $user, SecurityLog::assessment($assessment), [
            'format' => $format,
            'access' => self::accessFor($request, $user, $assessment),
        ]);
    }

    public function onUserCreated(User $user): void
    {
        SecurityLog::record(SecurityEvent::AccountCreated, self::actor(), SecurityLog::user($user), [
            'is_admin' => (bool) $user->is_admin,
        ]);
    }

    public function onUserUpdated(User $user): void
    {
        $actor = self::actor();
        $target = SecurityLog::user($user);

        // An account changing its own password, or an admin setting it. With
        // no signed-in actor it is not a deliberate change: Laravel re-hashes
        // a password at sign-in when the stored hash's cost is out of date,
        // before the account is authenticated, and that must not read as a
        // reset. (A reset from a console, with nobody signed in, is therefore
        // not logged; there is no console path in the product.)
        if ($actor !== null && $user->wasChanged('password')) {
            SecurityLog::record($actor->id === $user->id ? SecurityEvent::PasswordChanged : SecurityEvent::PasswordReset, $actor, $target);
        }

        if ($user->wasChanged('is_admin')) {
            SecurityLog::record($user->is_admin ? SecurityEvent::AdminGranted : SecurityEvent::AdminRevoked, $actor, $target);
        }

        if ($user->wasChanged('is_active')) {
            SecurityLog::record($user->is_active ? SecurityEvent::AccountReactivated : SecurityEvent::AccountDeactivated, $actor, $target);
        }

        $fields = array_values(array_filter(['name', 'email'], fn (string $field): bool => $user->wasChanged($field)));

        if ($fields !== []) {
            $details = ['fields' => $fields];

            if (in_array('email', $fields, true)) {
                $details['previous_email'] = $user->getOriginal('email');
            }

            SecurityLog::record(SecurityEvent::AccountUpdated, $actor, $target, $details);
        }
    }

    public function onUserDeleted(User $user): void
    {
        SecurityLog::record(SecurityEvent::AccountDeleted, self::actor(), SecurityLog::user($user));
    }

    public function onAssessmentUpdated(Assessment $assessment): void
    {
        if (! $assessment->wasChanged('is_public')) {
            return;
        }

        SecurityLog::record(SecurityEvent::PublicAccessChanged, self::actor(), SecurityLog::assessment($assessment), [
            'is_public' => (bool) $assessment->is_public,
        ]);
    }

    public function onSecurityEvent(SecurityEventOccurred $event): void
    {
        SecurityLog::record($event->event, $event->actor, $event->target, $event->details);
    }

    /**
     * The password step of a two-factor account fires Login and then Logout
     * while the sign-in waits for the code (W116 parks it under the session
     * key `two_factor.pending`, PendingLogin::SESSION_KEY). Neither is a
     * sign-in or a sign-out: the real Login comes after the challenge. The key
     * is the whole test, so this holds with or without W116's classes.
     */
    private static function signInParkedForSecondFactor(): bool
    {
        return request()->hasSession() && request()->session()->has('two_factor.pending');
    }

    private static function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The account a sign-in named, which may not exist: by email only, never
     * by a guessed id. Truncated, because the field is whatever was typed.
     *
     * @return array{type: string, id: null, email: string}|null
     */
    private static function attemptedAccount(mixed $email): ?array
    {
        if (! is_string($email) || $email === '') {
            return null;
        }

        return ['type' => 'user', 'id' => null, 'email' => Str::limit(Str::lower($email), 255, '')];
    }

    /** @return array{type: string, id: string}|null */
    private static function routeAssessment(Request $request): ?array
    {
        $assessment = $request->route('assessment');

        return $assessment instanceof Assessment ? SecurityLog::assessment($assessment) : null;
    }

    /**
     * How a reader without an account came to see it: through a share link
     * (the session flag ShareController sets) or because it is public.
     */
    private static function accessFor(Request $request, ?User $user, Assessment $assessment): string
    {
        if ($user !== null) {
            return 'account';
        }

        return $request->hasSession() && $request->session()->has("share_token.{$assessment->id}")
            ? 'share_link'
            : 'public';
    }
}
