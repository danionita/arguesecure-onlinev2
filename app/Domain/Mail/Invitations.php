<?php

namespace App\Domain\Mail;

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Jobs\SendTemplatedMail;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The invitation to a new account and its reminders. The email carries a
 * one-time link to choose a password and never a password.
 *
 * Does nothing unless mail is on (MailSettings::enabled()), so an instance
 * without a relay keeps the temporary-password flow.
 */
final class Invitations
{
    public const LINK_DAYS = 7;

    /** Days after the invitation at which a reminder goes out, in order; then it stops. */
    public const REMINDER_DAYS = [3, 7];

    /** Why an invitation cannot go to this account, or null if it can. */
    public static function whyNot(User $user): ?string
    {
        if (! MailSettings::enabled()) {
            return 'Email is not on. Set it up and send a test message on the Email tab.';
        }

        if (! $user->is_active) {
            return 'The account is not active.';
        }

        if ($user->last_login_at !== null) {
            return 'The account has already been used.';
        }

        // Under "SSO only" a non-admin cannot sign in with a password, so a
        // link to choose one would lead nowhere.
        if (InstanceSettings::ssoOnly() && ! $user->is_admin) {
            return 'Sign-in is SSO only, so this person cannot use a password.';
        }

        return null;
    }

    /**
     * Mails the invitation, new or again. Starts the reminder clock afresh:
     * the reminders count from the latest invitation. False if it cannot go.
     */
    public static function send(User $user, ?User $by): bool
    {
        if (self::whyNot($user) !== null) {
            return false;
        }

        self::dispatch('invitation', $user);

        $user->invited_at = now();
        $user->invitation_reminders_sent = 0;
        $user->save();

        SecurityLog::record(SecurityEvent::AccountInvited, $by, ['type' => 'user', 'id' => $user->id, 'email' => $user->email], []);

        return true;
    }

    /**
     * Mails every reminder that is due. Returns how many.
     */
    public static function sendDueReminders(): int
    {
        if (! MailSettings::enabled()) {
            return 0;
        }

        $sent = 0;

        User::query()
            ->whereNotNull('invited_at')
            ->whereNull('last_login_at')
            ->where('is_active', true)
            ->where('invitation_reminders_sent', '<', count(self::REMINDER_DAYS))
            ->get()
            ->each(function (User $user) use (&$sent): void {
                // SSO-only was switched on after the invitation: a link to
                // choose a password would lead nowhere.
                if (self::whyNot($user) !== null) {
                    return;
                }

                $due = Carbon::parse($user->invited_at)->addDays(self::REMINDER_DAYS[$user->invitation_reminders_sent]);

                if ($due->isFuture()) {
                    return;
                }

                // Claim the slot first, in one conditional UPDATE, so two
                // overlapping runs cannot both mail the same reminder.
                $claimed = User::query()
                    ->whereKey($user->id)
                    ->where('invitation_reminders_sent', $user->invitation_reminders_sent)
                    ->update(['invitation_reminders_sent' => $user->invitation_reminders_sent + 1]);

                if ($claimed !== 1) {
                    return;
                }

                self::dispatch('reminder', $user);
                $sent++;

                SecurityLog::record(SecurityEvent::ReminderSent, null, ['type' => 'user', 'id' => $user->id, 'email' => $user->email], []);
            });

        return $sent;
    }

    /** An active account that has never been used. */
    public static function pending(User $user): bool
    {
        return $user->is_active && $user->last_login_at === null;
    }

    private static function dispatch(string $kind, User $user): void
    {
        $token = MailTokens::issue(MailTokens::INVITE, $user->email, $user, new \DateInterval('P'.self::LINK_DAYS.'D'));

        SendTemplatedMail::dispatch($kind, $user->email, [
            'name' => $user->name,
            'set_password_link' => route('invitation.show', $token),
            'expires_days' => (string) self::LINK_DAYS,
        ]);
    }
}
