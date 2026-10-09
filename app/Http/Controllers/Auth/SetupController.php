<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupRequest;
use App\Models\User;
use Database\Seeders\SampleContentSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * First-run setup (PLAN.md Phase 3 item 2). Replaces the legacy hardcoded
 * `admin` / `XDARSEC` bootstrap entirely — this is the only place in the
 * rewrite a credential is ever created without an existing admin acting.
 *
 * Reachability is guarded on *any* row existing in `users`, not on any
 * *active* row: deactivating every account must not reopen this screen,
 * per the package brief. That is deliberately a stronger check than
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

        // W27: the earliest moment the bundled templates and catalogue
        // libraries have an owner to attach to. Before this, a fresh
        // deployment reached the dashboard with none: the entrypoint seeds
        // the system scales but deliberately not content, and
        // TemplateAssessmentSeeder needs an existing admin (never a seeded
        // credential — CLAUDE.md). Reference content only; the demo web
        // shop still needs a deliberate `db:seed`.
        //
        // Failure here must not cost the administrator their account —
        // the user is already committed and this is convenience content —
        // so it is logged and swallowed rather than allowed to 500 a
        // one-time screen that cannot be revisited.
        try {
            (new SampleContentSeeder)->seedReferenceContent($user);
        } catch (Throwable $e) {
            Log::warning('First-run template seeding failed; the instance is usable without it.', [
                'exception' => $e,
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
