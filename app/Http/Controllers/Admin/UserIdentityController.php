<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\SessionRegistry;
use App\Domain\Identity\Oidc\Events\SsoIdentityUnlinked;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An admin unlinks an account from the identity provider (W117, D76): the
 * next provider sign-in links by verified email again, or is refused. The
 * account's sessions end, as when MFA is removed. Behind the `admin`
 * middleware on the route group, and checked here too.
 */
class UserIdentityController extends Controller
{
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        abort_unless($admin?->is_admin, 403);

        if ($user->identities()->delete() > 0) {
            SessionRegistry::revokeAllExcept($user->id, $admin->is($user) ? $request->session()->getId() : null);

            event(new SsoIdentityUnlinked($user, $admin));
        }

        return back();
    }
}
