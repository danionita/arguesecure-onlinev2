<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Mail\MailTokens;
use App\Events\EmailAddressVerified;
use App\Http\Controllers\Controller;
use App\Models\MailToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page an address-verification link opens. The GET shows a button and
 * spends nothing; the POST spends the token, so a mail scanner that fetches
 * the link does not use it up.
 *
 * For a link bound to an existing account it records the proof on that
 * account. For one bound to a `reference` it announces the proof
 * (EmailAddressVerified) and the sender decides what it unlocks; this class
 * creates no account.
 */
class EmailVerificationController extends Controller
{
    public function show(string $token): Response
    {
        return Inertia::render('Auth/VerifyEmail', [
            'valid' => $this->usable(MailTokens::peek(MailTokens::VERIFY, $token)),
            'token' => $token,
        ]);
    }

    public function store(string $token): RedirectResponse
    {
        // Checked before spending: a link for an address the account no longer
        // has must not vouch for the new one.
        if (! $this->usable(MailTokens::peek(MailTokens::VERIFY, $token))) {
            return redirect()->route('verification.show', $token);
        }

        $row = MailTokens::consume(MailTokens::VERIFY, $token);

        if ($row === null) {
            return redirect()->route('verification.show', $token);
        }

        $user = $row->user_id === null ? null : User::query()->find($row->user_id);

        if ($user !== null && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        SecurityLog::record(SecurityEvent::EmailVerified, $user, $user === null ? null : ['type' => 'user', 'id' => $user->id, 'email' => $user->email], []);

        if ($row->reference !== null) {
            EmailAddressVerified::dispatch($row->email, $row->reference);

            // A listener may send the person on (a new account's page to
            // choose a password) or say why nothing happened.
            if (($next = session()->pull('registration.next')) !== null) {
                return redirect($next);
            }

            if (($refused = session()->pull('registration.refused')) !== null) {
                return redirect()->route('login')->with('status', $refused);
            }
        }

        return redirect()->route('login')->with('status', 'Your address is confirmed.');
    }

    /** A good token whose bound account, if any, still has the address it was sent to. */
    private function usable(?MailToken $row): bool
    {
        if ($row === null) {
            return false;
        }

        if ($row->user_id === null) {
            return true;
        }

        $user = User::query()->find($row->user_id);

        return $user !== null && strcasecmp($user->email, $row->email) === 0;
    }
}
