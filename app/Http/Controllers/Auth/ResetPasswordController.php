<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\PasswordPolicy;
use App\Domain\Accounts\SessionRegistry;
use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Mail\MailTokens;
use App\Domain\Mail\PasswordResets;
use App\Http\Controllers\Controller;
use App\Models\MailToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page a reset link opens. Showing it spends nothing; the form does. It
 * sets the password and ends every session of the account, and does not sign
 * anyone in: the person signs in as usual, with their second factor if they
 * have one.
 */
class ResetPasswordController extends Controller
{
    public function show(string $token): Response
    {
        abort_unless(PasswordResets::available(), 404);

        $row = MailTokens::peek(MailTokens::RESET, $token);

        return Inertia::render('Auth/ResetPassword', [
            'valid' => $row !== null && $this->account($row->user_id) !== null,
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        abort_unless(PasswordResets::available(), 404);

        // A dead link is the first thing to say, before any password rule.
        if (MailTokens::peek(MailTokens::RESET, $token) === null) {
            return redirect()->route('password.reset', $token);
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        $user = DB::transaction(function () use ($token, $validated): ?User {
            $row = MailTokens::consume(MailTokens::RESET, $token);
            $user = $row === null ? null : $this->account($row->user_id);

            if ($user === null) {
                return null;
            }

            $user->password = Hash::make($validated['password']);
            // The mailbox was proven by following the link.
            $user->email_verified_at ??= now();
            $user->must_change_password = false;
            $user->save();

            // Inside the transaction: a failure here undoes the reset, so the
            // link is not spent with old sessions left alive.
            SessionRegistry::revokeAllExcept($user->id);
            // Any other unused link (invitation, verification) predates this
            // credential and must not outlive it.
            MailToken::query()->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);

            return $user;
        });

        if ($user === null) {
            return redirect()->route('password.reset', $token);
        }

        SecurityLog::record(SecurityEvent::PasswordResetByEmail, null, ['type' => 'user', 'id' => $user->id, 'email' => $user->email], []);

        return redirect()->route('login')->with('status', 'Your password is changed. Sign in with it.');
    }

    private function account(?int $userId): ?User
    {
        $user = $userId === null ? null : User::query()->find($userId);

        return PasswordResets::eligible($user) ? $user : null;
    }
}
