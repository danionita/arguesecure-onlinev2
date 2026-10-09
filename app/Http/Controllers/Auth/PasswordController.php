<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\PasswordPolicy;
use App\Domain\Accounts\SessionRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    /**
     * Update the user's password. Requires the current password
     * (security.md §4) and invalidates every other session, so a stolen
     * session cannot outlive the credential change.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        SessionRegistry::revokeAllExcept($request->user()->id, $request->session()->getId());

        return back();
    }
}
