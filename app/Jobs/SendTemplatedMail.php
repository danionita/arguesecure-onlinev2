<?php

namespace App\Jobs;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\MailTemplates;
use App\Mail\TemplatedMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one message of a kind in MailTemplates, from the queue, so a slow or
 * broken relay never fails the request that wanted it (a failed send is
 * logged and retried, and signing in does not depend on it).
 *
 * Wording and SMTP settings are read when the job runs, not when it was
 * queued: a worker lives for an hour and must use what an administrator saved
 * since. The values carry one-time links, so the job is encrypted: neither
 * Redis nor a row left in `failed_jobs` holds a usable token.
 */
class SendTemplatedMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /** @param array<string, string> $values */
    public function __construct(public readonly string $kind, public readonly string $email, public readonly array $values) {}

    public function handle(): void
    {
        // Mail was switched off, or its settings changed, since this was queued.
        if (! MailSettings::enabled()) {
            return;
        }

        $message = MailTemplates::render($this->kind, $this->values + ['app_name' => (string) config('app.name')]);

        MailSettings::apply();

        Mail::mailer('smtp')->to($this->email)->send(new TemplatedMail($message['subject'], $message['text'], $message['html'], $message['unsubscribe']));
    }

    public function failed(\Throwable $e): void
    {
        // The kind, the recipient's domain and the exception class: never the
        // address, the body or the link.
        SecurityLog::record(SecurityEvent::MailFailed, null, null, [
            'kind' => $this->kind,
            'domain' => substr(strrchr($this->email, '@') ?: '@', 1),
            'reason' => class_basename($e),
        ]);
    }
}
