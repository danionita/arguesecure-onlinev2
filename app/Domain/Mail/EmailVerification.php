<?php

namespace App\Domain\Mail;

use App\Jobs\SendTemplatedMail;
use App\Models\User;

/**
 * Proves that someone owns an address, by mailing a one-time link.
 *
 * The caller binds the link to something of its own through `$reference` (a
 * pending registration's id, say), or to an existing account through `$user`.
 * Following the link is a page with a button, and the button spends the token,
 * because mail scanners fetch links and a fetch must not spend it. Spending it
 * for an existing account sets `email_verified_at`; for a reference it fires
 * App\Events\EmailAddressVerified, which the caller listens for. This class
 * creates no account.
 */
final class EmailVerification
{
    public const LINK_HOURS = 24;

    public static function available(): bool
    {
        return MailSettings::enabled();
    }

    /** Mails the link. False (nothing sent) if mail is off. */
    public static function send(string $email, string $name, ?User $user = null, ?string $reference = null): bool
    {
        if (! self::available()) {
            return false;
        }

        $token = MailTokens::issue(MailTokens::VERIFY, $email, $user, new \DateInterval('PT'.self::LINK_HOURS.'H'), $reference);

        SendTemplatedMail::dispatch('verify-email', $email, [
            'name' => $name,
            'verify_link' => route('verification.show', $token),
            'expires_hours' => (string) self::LINK_HOURS,
        ]);

        return true;
    }
}
