<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\MailTemplates;
use App\Domain\Mail\NotificationMail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMailRequest;
use App\Models\InstanceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin Email tab: the SMTP server, the sender, and a test message that
 * goes to the signed-in admin's own address and to nobody else.
 *
 * Mail is off by default and the application works completely without it.
 * It counts as on (MailSettings::enabled()) only after a test message has been
 * delivered with the settings now in force.
 */
class MailController extends Controller
{
    private const FIELDS = ['host', 'port', 'encryption', 'username', 'from_address', 'from_name'];

    public function edit(): Response
    {
        return Inertia::render('Admin/Mail', [
            'mail' => [
                'host' => $this->field('host', MailSettings::host()),
                'port' => $this->field('port', MailSettings::source('port') === MailSettings::SOURCE_NONE ? '' : (string) MailSettings::port()),
                'encryption' => $this->field('encryption', MailSettings::encryption()),
                'username' => $this->field('username', MailSettings::username()),
                'from_address' => $this->field('from_address', MailSettings::fromAddress()),
                'from_name' => $this->field('from_name', MailSettings::source('from_name') === MailSettings::SOURCE_NONE ? '' : MailSettings::fromName()),
                // Never the password itself: only whether one is set, and where.
                'password' => ['set' => MailSettings::password() !== '', 'source' => MailSettings::source('password')],
            ],
            'templates' => collect(MailTemplates::kinds())->map(fn (array $d, string $kind) => [
                'kind' => $kind,
                'label' => $d['label'],
                'placeholders' => $d['placeholders'],
                'footer' => $d['footer'],
                'defaults' => ['subject' => $d['subject'], 'body' => $d['body']],
            ] + MailTemplates::wording($kind))->values()->all(),
            'limits' => ['subject' => MailTemplates::SUBJECT_MAX, 'body' => MailTemplates::BODY_MAX],
            'notifications' => collect(NotificationMail::KINDS)->map(fn (array $d, string $kind) => [
                'kind' => $kind,
                'label' => $d['label'],
                'on' => (bool) InstanceSetting::singleton()->{$d['column']},
            ])->values()->all(),
            'notificationTitles' => NotificationMail::titlesAllowed(),
            'configured' => MailSettings::configured(),
            'enabled' => MailSettings::enabled(),
        ]);
    }

    /** Which notification kinds may be mailed, and whether titles may appear. */
    public function notifications(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kinds' => ['required', 'array'],
            // Every kind, always: a missing one must not mean "switch it off".
            'kinds.mention' => ['required', 'boolean'],
            'kinds.assignment' => ['required', 'boolean'],
            'kinds.summary' => ['required', 'boolean'],
            'titles' => ['required', 'boolean'],
        ]);

        $row = InstanceSetting::singleton();
        $changed = [];

        foreach (NotificationMail::KINDS as $kind => $definition) {
            $value = (bool) ($validated['kinds'][$kind] ?? false);

            if ($row->{$definition['column']} !== $value) {
                $changed[] = $definition['column'];
            }
            $row->{$definition['column']} = $value;
        }

        if ($row->mail_notify_titles !== (bool) $validated['titles']) {
            $changed[] = 'mail_notify_titles';
        }
        $row->mail_notify_titles = (bool) $validated['titles'];
        $row->save();

        if ($changed !== []) {
            SecurityLog::record(SecurityEvent::MailNotificationsUpdated, $request->user(), null, ['settings' => ['changed' => $changed]]);
        }

        return redirect()->route('admin.mail.edit');
    }

    public function update(UpdateMailRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $changed = [];

        DB::transaction(function () use ($validated, &$changed) {
            $row = InstanceSetting::singleton();

            foreach (self::FIELDS as $field) {
                $column = "mail_{$field}";

                // Environment wins; a key the request leaves out is left alone.
                if (MailSettings::source($field) === MailSettings::SOURCE_ENVIRONMENT || ! array_key_exists($column, $validated)) {
                    continue;
                }

                $value = trim((string) ($validated[$column] ?? ''));
                $value = $value === '' ? null : ($field === 'port' ? (int) $value : $value);

                // Names are case-insensitive; the local part of an address is not.
                if ($field === 'host') {
                    $value = $value === null ? null : strtolower((string) $value);
                } elseif ($field === 'from_address' && $value !== null) {
                    $at = strrpos((string) $value, '@');
                    $value = substr((string) $value, 0, $at + 1).strtolower(substr((string) $value, $at + 1));
                }

                if ($row->{$column} !== $value) {
                    $changed[] = $column;
                }
                $row->{$column} = $value;
            }

            if (MailSettings::source('password') !== MailSettings::SOURCE_ENVIRONMENT) {
                $password = (string) ($validated['mail_password'] ?? '');

                if ($password !== '') {
                    $row->mail_password = $password;
                    $changed[] = 'mail_password';
                } elseif ((bool) ($validated['clear_password'] ?? false) && $row->getRawOriginal('mail_password') !== null) {
                    $row->mail_password = null;
                    $changed[] = 'mail_password';
                }
            }

            $row->save();
        });

        // Field names only: no value, least of all the password, is logged.
        // Whether the tested settings still match is decided by the
        // fingerprint, so a change here switches mail off without touching it.
        if ($changed !== []) {
            SecurityLog::record(SecurityEvent::MailSettingsUpdated, $request->user(), null, ['settings' => ['changed' => $changed]]);
        }

        return redirect()->route('admin.mail.edit');
    }

    /**
     * Sends one message to the signed-in admin's own address. On success the
     * settings in force are remembered as tested.
     */
    public function test(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! MailSettings::configured()) {
            return response()->json(['ok' => false, 'message' => 'Save a server and a sender address first.']);
        }

        // Taken before sending, so a save made while the send is in flight
        // cannot be vouched for by a message it never governed.
        $fingerprint = MailSettings::fingerprint();
        MailSettings::apply();

        try {
            Mail::mailer('smtp')->raw(
                'This is a test message from '.config('app.name').'. If you can read it, the mail settings work.',
                fn ($message) => $message->to($admin->email)->subject(config('app.name').' test message'),
            );
        } catch (\Throwable $e) {
            // The class only. The transport's own words can say whether a port
            // is open and what is listening on it, which this endpoint must
            // not hand to the page, and they can quote the credentials.
            SecurityLog::record(SecurityEvent::MailTestFailed, $admin, null, ['reason' => class_basename($e)]);

            return response()->json(['ok' => false, 'message' => 'The message could not be sent. Check the server, port, encryption, username, password and sender address.']);
        }

        $row = InstanceSetting::singleton();
        $row->mail_tested_fingerprint = $fingerprint;
        $row->save();

        SecurityLog::record(SecurityEvent::MailTestSent, $admin, null, []);

        return response()->json(['ok' => true, 'message' => 'A test message was sent to '.$admin->email.'. Mail is on.']);
    }

    /**
     * @return array{value: string, source: string}
     */
    private function field(string $field, string $value): array
    {
        return ['value' => $value, 'source' => MailSettings::source($field)];
    }
}
