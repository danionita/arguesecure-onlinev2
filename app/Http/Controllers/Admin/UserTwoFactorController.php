<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\TwoFactor\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An admin removes another account's second factor — a lost phone (W116,
 * D78). The account signs in with its password again and enrols afresh.
 * Behind the `admin` middleware on the route group, and checked here too.
 */
class UserTwoFactorController extends Controller
{
    public function destroy(Request $request, User $user, TwoFactor $twoFactor): RedirectResponse
    {
        $admin = $request->user();

        abort_unless($admin?->is_admin, 403);

        $twoFactor->removeByAdmin(
            $user,
            $admin,
            // An admin removing their own keeps the page they are on.
            $admin->is($user) ? $request->session()->getId() : null,
        );

        return back();
    }
}
