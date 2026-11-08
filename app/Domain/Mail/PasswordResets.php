<?php

namespace App\Domain\Mail;

use App\Domain\Accounts\InstanceSettings;
use App\Jobs\SendTemplatedMail;
use App\Models\MailToken;
use App\Models\User;

/**
 * "Forgot password" by email. Available only while mail is on.
 *
 * The caller answers the same way whether or not an account exists, so this
 * returns nothing the page could leak. The link is single use and short
 * lived; following it sets a new password and signs the person out
 * everywhere, but does not sign them in, so the usual sign-in, second factor
 * included, is still what lets them in.
 */
final class PasswordResets
{
    public const LINK_MINUTES = 60;

    public static function available(): bool
    {
        return MailSettings::enabled();
    }

    /** Whether this account may be sent a link. Deactivated accounts, SSO-only non-admins and accounts with an unused invitation may not. */
    public static function eligible(?User $user): bool
    {
        return $user !== null
            && $user->is_active
            // An unused invitation is the way in for this account; a reset
            // must not bypass it.
            && ! ($user->invited_at !== null && $user->last_login_at === null)
            && ! (InstanceSettings::ssoOnly() && ! $user->is_admin);
    }

    /** A link sent in the last 10 minutes is still in the inbox; a new request does not replace it. */
    public const KEEP_MINUTES = 10;

    public static function send(User $user): void
    {
        $recent = MailToken::query()->where('purpose', MailTokens::RESET)->where('user_id', $user->id)
            ->whereNull('used_at')->where('expires_at', '>', now())
            ->where('created_at', '>', now()->subMinutes(self::KEEP_MINUTES))->exists();

        // So nobody can keep voiding a link its owner has not yet used.
        if ($recent) {
            return;
        }

        $token = MailTokens::issue(MailTokens::RESET, $user->email, $user, new \DateInterval('PT'.self::LINK_MINUTES.'M'));

        SendTemplatedMail::dispatch('password-reset', $user->email, [
            'name' => $user->name,
            'reset_link' => route('password.reset', $token),
            'expires_minutes' => (string) self::LINK_MINUTES,
        ]);
    }
}
