<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\PasswordPolicy;
use App\Domain\Accounts\SessionRegistry;
use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Mail\Invitations;
use App\Domain\Mail\MailTokens;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page an invitation's link opens: choose a password, once.
 *
 * Showing the page spends nothing; only the form does. The token is the whole
 * credential, so the account must still be active and never used, and a spent,
 * expired, replaced or unknown token all look the same.
 */
class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $row = MailTokens::peek(MailTokens::INVITE, $token);

        return Inertia::render('Auth/SetPassword', [
            'valid' => $row !== null && $this->account($row->user_id) !== null,
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        $user = DB::transaction(function () use ($token, $validated): ?User {
            $row = MailTokens::consume(MailTokens::INVITE, $token);
            $user = $row === null ? null : $this->account($row->user_id);

            if ($user === null) {
                return null;
            }

            $user->password = Hash::make($validated['password']);
            $user->email_verified_at ??= now();
            $user->must_change_password = false;
            $user->save();

            return $user;
        });

        if ($user === null) {
            return redirect()->route('invitation.show', $token);
        }

        // Whatever else was signed in as this account, before it had a password
        // of its own, is not wanted any more.
        SessionRegistry::revokeAllExcept($user->id);

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        SecurityLog::record(SecurityEvent::InvitationUsed, $user, ['type' => 'user', 'id' => $user->id, 'email' => $user->email], []);

        return redirect()->route('dashboard');
    }

    /** The account a token may still act on: invitable, so active, never signed in and allowed a password. */
    private function account(?int $userId): ?User
    {
        $user = $userId === null ? null : User::query()->find($userId);

        // Also refused if sign-in became SSO-only since the invitation went
        // out: a password the person cannot use is no way in.
        return $user !== null && Invitations::whyNot($user) === null ? $user : null;
    }
}
