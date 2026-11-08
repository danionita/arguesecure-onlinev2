<?php

namespace App\Domain\Accounts;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Mail\EmailVerification;
use App\Domain\Mail\MailTokens;
use App\Models\InstanceSetting;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-registration (off by default). A request creates only a pending row and
 * mails a link; the account exists once the link is followed. The answer to a
 * request never says whether the address already has an account.
 */
final class SelfRegistration
{
    /** Prefix of the mail token's reference, so a reference from another feature is never mistaken for ours. */
    private const REFERENCE = 'registration:';

    /** Mails to one address: this many an hour, this many a day. */
    private const PER_HOUR = 3;

    private const PER_DAY = 10;

    /** On, mail working, and password sign-in not switched off for everyone. */
    public static function enabled(): bool
    {
        return InstanceSetting::singleton()->registration_enabled
            && EmailVerification::available()
            && ! InstanceSettings::ssoOnly();
    }

    /** @return list<string> lower-case, exact domains; empty means any */
    public static function domains(): array
    {
        $raw = (string) InstanceSetting::singleton()->registration_domains;

        return array_values(array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', $raw))));
    }

    public static function domainAllowed(string $email): bool
    {
        $domains = self::domains();

        return $domains === [] || in_array(strtolower(substr(strrchr($email, '@') ?: '', 1)), $domains, true);
    }

    public static function dailyCap(): int
    {
        return (int) InstanceSetting::singleton()->registration_daily_cap;
    }

    /** Accounts made by following a link since the start of the UTC day; pending requests do not count. */
    public static function completedToday(): int
    {
        return User::query()->where('self_registered_at', '>=', now()->utc()->startOfDay())->count();
    }

    public static function capReached(): bool
    {
        return self::completedToday() >= self::dailyCap();
    }

    /**
     * @throws ValidationException when the domain is not allowed or the day's cap is used
     */
    public static function request(string $email): void
    {
        $email = strtolower(trim($email));

        if (! self::domainAllowed($email)) {
            throw ValidationException::withMessages(['email' => 'Registration is open to these domains only: '.implode(', ', self::domains()).'.']);
        }

        if (self::capReached()) {
            throw ValidationException::withMessages(['email' => 'New registrations are closed for today. Try again tomorrow.']);
        }

        // Counted before anything that differs between a new and an existing
        // address.
        $key = sha1($email);
        if (RateLimiter::tooManyAttempts('register-hour:'.$key, self::PER_HOUR) || RateLimiter::tooManyAttempts('register-day:'.$key, self::PER_DAY)) {
            return;
        }
        RateLimiter::hit('register-hour:'.$key, 3600);
        RateLimiter::hit('register-day:'.$key, 86400);

        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return;
        }

        $row = PendingRegistration::query()->firstOrNew(['email' => $email]);
        $row->save();
        $row->touch();

        // A fixed greeting: the requester is a stranger to the address's
        // owner, and nothing they typed may appear in a mail from this server.
        EmailVerification::send($email, 'there', null, self::REFERENCE.$row->id);
    }

    /**
     * Called when the emailed link is confirmed. Idempotent.
     *
     * Creates the account with a password nobody knows, then sends the person
     * who just proved the mailbox to the page where they choose one, so the
     * only password that ever works is the owner's. Where the link was no
     * longer good it leaves a message in the session for the confirmation page.
     */
    public static function complete(string $email, string $reference): void
    {
        if (! str_starts_with($reference, self::REFERENCE)) {
            return;
        }

        $row = PendingRegistration::query()->find((int) substr($reference, strlen(self::REFERENCE)));

        if ($row === null || strcasecmp($row->email, $email) !== 0) {
            return;
        }

        // The rules are checked again now: a link mailed yesterday must not
        // outlive the switch, the domain list or the day's cap.
        if (! self::enabled() || ! self::domainAllowed($row->email)) {
            $row->delete();
            session()->put('registration.refused', 'Registration is closed right now.');

            return;
        }

        if (self::capReached()) {
            session()->put('registration.refused', 'New registrations are closed for today. Try again tomorrow.');

            return;
        }

        try {
            $user = self::createFrom($row);
        } catch (QueryException) {
            // Another request made the account first (a single sign-on for the
            // same address, say); the token is already spent, so end quietly.
            $row->delete();

            return;
        }

        if ($user === null) {
            return;
        }

        SecurityLog::record(SecurityEvent::AccountSelfRegistered, null, ['type' => 'user', 'id' => $user->id, 'email' => $user->email], ['domain' => substr(strrchr($user->email, '@') ?: '', 1)]);

        $token = MailTokens::issue(MailTokens::INVITE, $user->email, $user, new \DateInterval('PT1H'));
        session()->put('registration.next', route('invitation.show', $token));
    }

    private static function createFrom(PendingRegistration $row): ?User
    {
        return DB::transaction(function () use ($row): ?User {
            $row->delete();

            if (User::query()->whereRaw('lower(email) = ?', [strtolower($row->email)])->exists()) {
                return null;
            }

            $user = new User;
            $user->name = mb_substr(Str::before($row->email, '@'), 0, 255);
            $user->email = strtolower($row->email);
            // Random and never shown; the person chooses their own next.
            $user->password = Str::random(64);
            $user->is_admin = false;
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->self_registered_at = now();
            $user->save();

            return $user;
        });
    }

    /** Pending requests older than the link's lifetime can no longer be confirmed. */
    public static function prune(): int
    {
        return PendingRegistration::query()->where('updated_at', '<', now()->subHours(EmailVerification::LINK_HOURS))->delete();
    }

    /** A verified single sign-on for this address wins over a request still waiting. */
    public static function discardFor(string $email): void
    {
        PendingRegistration::query()->where('email', strtolower($email))->delete();
    }
}
