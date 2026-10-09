<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\SessionRegistry;
use App\Domain\Accounts\TemporaryPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestroyUserRequest;
use App\Http\Requests\Admin\ResetPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin users table (ui.md §10). Gated by the 'admin' middleware on
 * the route group (routes/web.php), not repeated here — but every
 * FormRequest's authorize() also checks is_admin, since a route
 * misconfiguration should not be the only thing standing between a
 * non-admin and this controller (security.md §3.1 spirit, applied here).
 */
class UserController extends Controller
{
    /** Whitelisted sort columns — never take a column name from request input directly (security.md §7). */
    private const SORTABLE_COLUMNS = ['name', 'email', 'is_admin', 'is_active', 'last_login_at', 'created_at'];

    public function index(Request $request): Response
    {
        $sort = $request->string('sort', 'name')->toString();
        $sort = in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'name';
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
        $search = $request->string('search')->toString();

        $users = User::query()
            ->withCount('ownedAssessments')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy($sort, $direction)
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => ['search' => $search, 'sort' => $sort, 'direction' => $direction],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $password = $validated['password'] ?? TemporaryPassword::generate();

        $user = new User;
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->password = Hash::make($password);
        $user->is_admin = $validated['is_admin'];
        $user->is_active = true;
        $user->save();

        return redirect()->route('admin.users.index')->with(
            'generatedPassword',
            isset($validated['password']) ? null : $password,
        );
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Edit', [
            'targetUser' => $user->only(['id', 'name', 'email', 'is_admin', 'is_active']),
            'ownedAssessmentCount' => $user->ownedAssessments()->count(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();
        $wasActive = (bool) $user->is_active;
        $isActive = $validated['is_active'];

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->is_admin = $validated['is_admin'];
        $user->is_active = $isActive;
        $user->save();

        // Deactivating here must be at least as strong as the 'active'
        // middleware: don't wait for the user's next request to notice,
        // revoke their sessions immediately (security.md §4).
        if ($wasActive && ! $isActive) {
            SessionRegistry::revokeAllExcept($user->id);
        }

        return redirect()->route('admin.users.index');
    }

    public function resetPassword(ResetPasswordRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();
        $password = $validated['password'] ?? TemporaryPassword::generate();

        $user->password = Hash::make($password);
        // security.md §4: "Admin password reset ... forces a change on
        // next login." Set alongside the password in the same save() so
        // User::booted()'s saving hook (which clears this flag whenever
        // the password changes) sees must_change_password as
        // simultaneously dirty and leaves the explicit true in place.
        $user->must_change_password = true;
        $user->save();

        // A password reset should not leave the user's other sessions
        // alive on the old credential (security.md §4: "Changing a
        // password requires the current one ... Both invalidate other
        // sessions" — an admin reset is the equivalent action taken on
        // the user's behalf).
        SessionRegistry::revokeAllExcept($user->id);

        return redirect()->route('admin.users.index')->with(
            'generatedPassword',
            isset($validated['password']) ? null : $password,
        );
    }

    public function destroy(DestroyUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($user, $validated) {
            if (isset($validated['transfer_to_user_id'])) {
                $user->ownedAssessments()->update(['owner_user_id' => $validated['transfer_to_user_id']]);
            }

            SessionRegistry::revokeAllExcept($user->id);
            $user->delete();
        });

        return redirect()->route('admin.users.index');
    }
}
