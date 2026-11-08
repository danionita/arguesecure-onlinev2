<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupRequest;
use App\Jobs\SeedReferenceContent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * First-run setup (security.md §4). Replaces the legacy hardcoded
 * `admin` / `XDARSEC` bootstrap entirely — this is the only place in the
 * rewrite a credential is ever created without an existing admin acting.
 *
 * Reachability is guarded on *any* row existing in `users`, not on any
 * *active* row: deactivating every account must not reopen this screen,
 * by design. That is deliberately a stronger check than
 * "no admin exists" — a single deactivated non-admin user still closes
 * setup for good, which is correct: the intended recovery path from that
 * state is a database operation by whoever runs the deployment, not a
 * second unauthenticated bootstrap.
 */
class SetupController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (User::query()->count() > 0) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/Setup');
    }

    public function store(SetupRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated): User {
            // Re-check inside the transaction: SetupRequest::authorize()
            // already checked, but two browser tabs racing the guest
            // screen could both pass that check before either commits.
            if (User::query()->lockForUpdate()->count() > 0) {
                abort(403, 'Setup has already been completed.');
            }

            $user = new User;
            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->password = Hash::make($validated['password']);
            $user->is_admin = true;
            $user->is_active = true;
            $user->last_login_at = now();
            $user->save();

            return $user;
        });

        // The earliest moment the bundled templates and catalogue
        // libraries have an owner to attach to. Before this, a fresh
        // deployment reached the dashboard with none: the entrypoint seeds
        // the system scales but deliberately not content, and
        // TemplateAssessmentSeeder needs an existing admin (never a seeded
        // credential). Reference content only; the demo web shop still
        // needs a deliberate `db:seed`.
        //
        // Queued, not run here: it takes seconds to minutes depending on the
        // database's disk, and this is a request the person is watching. See
        // the job for the failure rule.
        // A queue that is down must not cost the administrator their
        // sign-in: the account is committed and this screen is closed now.
        try {
            SeedReferenceContent::dispatch($user->id);
        } catch (Throwable $e) {
            report($e);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
