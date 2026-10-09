<?php

use App\Domain\History\Commands\EdgeResolver;
use App\Domain\History\UndoService;
use App\Domain\Structure\LinkService;
use App\Domain\Structure\ReorderService;
use App\Http\Controllers\Admin\AssistantController;
use App\Http\Controllers\Admin\DefaultsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserImportController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentHistoryController;
use App\Http\Controllers\AssessmentLifecycleController;
use App\Http\Controllers\AssessmentSettingsController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AssistantGenerationController;
use App\Http\Controllers\AssistantHelpController;
use App\Http\Controllers\AssistantStatusController;
use App\Http\Controllers\AssistantSuggestionController;
use App\Http\Controllers\AssistantSummaryController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\LockController;
use App\Http\Controllers\NodeController;
use App\Http\Controllers\NodeRevisionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScaleCalibrationController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\SnapshotController;
use App\Http\Controllers\SummaryNarrativeController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UndoController;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
    ]);
});

// W26: the dashboard was Breeze's placeholder page, which mentioned no
// assessments and linked to nothing — so the application's home screen
// after login was a dead end. It now renders the assessment list, which is
// what a user needs on arrival and the only entry point to creating one.
//
// Kept as its own route rather than a redirect to `assessments.index`
// because `dashboard` is where Laravel's own auth scaffolding sends people
// after login, the sidebar's home link points at it, and several tests assert it
// answers 200 — a redirect would change all three for no benefit.
// W42: `/dashboard` is a dashboard now, not a second rendering of the
// assessment list. It answers the four questions someone has on arrival —
// what is assigned to me, who mentioned me, what do I own, what have I been
// invited to — where the list answers only the last two.
Route::get('/dashboard', [AssessmentLifecycleController::class, 'dashboard'])
    ->middleware(['auth', 'active'])
    ->name('dashboard');

// 'active' rejects a deactivated user on every request in this group
// (security.md §4), not only at login — see EnsureUserIsActive.
//
// security.md §9's "everything else authenticated: 300/min per user"
// default. The node-mutation sub-group below carries its own tighter
// `mutations` limiter, which stacks with this one — and because both are
// *named*, they are genuinely independent buckets, so the 120 binds first
// and the 300 here is the fallback for the rest of this group (profile,
// admin, sharing).
//
// Named, not `throttle:300,1`, and that is not cosmetic: an anonymous
// throttle keys on sha1(user id) alone, so every one in the application
// shares a single counter per user and nesting two of them counts each
// request twice. See App\Providers\RateLimitServiceProvider.
Route::middleware(['auth', 'active', 'throttle:authenticated'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // security.md §9 rates "email change" at 5/min per user, and this is
    // the route that changes an email — the group's 300/min default does
    // not satisfy it. Tighter than the rest of the profile group on
    // purpose: an attacker on a stolen session changing the address is how
    // an account takeover is made permanent.
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:credentials')
        ->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::delete('/profile/sessions/{sessionId}', [ProfileController::class, 'revokeSession'])
        ->name('profile.sessions.destroy');

    // /admin is additionally gated on is_admin (PLAN.md Phase 3 item 3).
    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::redirect('/', '/admin/users');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/import', [UserImportController::class, 'create'])->name('users.import.create');
        Route::post('users/import/preview', [UserImportController::class, 'preview'])->name('users.import.preview');
        Route::post('users/import/commit', [UserImportController::class, 'commit'])->name('users.import.commit');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

        // 2.5.1: the assistant's switches, on a tab of their own.
        Route::get('assistant', [AssistantController::class, 'edit'])->name('assistant.edit');
        Route::put('assistant', [AssistantController::class, 'update'])->name('assistant.update');

        // W87, D47 — the Defaults tab. Separate from Instance settings
        // because they answer different questions: that one is about the
        // deployment, this one about the content a new assessment starts
        // with. Inside the same `admin` middleware group, so it is gated on
        // `is_admin` exactly as every route above it is.
        Route::get('defaults', [DefaultsController::class, 'edit'])->name('defaults.edit');
        Route::put('defaults/currency', [DefaultsController::class, 'updateCurrency'])->name('defaults.currency');
        // `{kind}` is checked against ScaleCalibrator::kinds() in the
        // controller before it reaches anything that could use it to pick a
        // scale, so a nonsense kind 404s rather than reaching a column name.
        Route::put('defaults/scales/{kind}', [DefaultsController::class, 'update'])->name('defaults.update');
    });

    // W9 sharing (PLAN.md Phase 3 item 5). Every route below is fronted by
    // a FormRequest whose authorize() calls AssessmentPolicy — see
    // app/Http/Requests/Sharing/**. {collaborator}'s implicit binding is
    // NOT scoped to {assessment} via Route::scopeBindings(): that
    // mechanism guesses a relation name on the parent model from the
    // child segment's name (it would look for Assessment::collaborators()),
    // and Assessment (app/Models/**, outside this package's ownership)
    // names that relation members() instead. Each FormRequest below and
    // CollaboratorService::assertBelongsTo() therefore make the
    // cross-assessment check explicitly (security.md §3.3 IDOR test).
    Route::prefix('assessments/{assessment:uuid}/share')->name('share.')->group(function () {
        Route::get('/', [ShareController::class, 'index'])->name('index');
        Route::get('users', [ShareController::class, 'searchUsers'])
            ->middleware('throttle:search')->name('users.search');
        Route::post('collaborators', [ShareController::class, 'storeCollaborator'])->name('collaborators.store');
        Route::patch('collaborators/{collaborator}', [ShareController::class, 'updateCollaborator'])->name('collaborators.update');
        Route::delete('collaborators/{collaborator}', [ShareController::class, 'destroyCollaborator'])->name('collaborators.destroy');
        Route::post('transfer', [ShareController::class, 'transferOwnership'])->name('transfer');
        Route::patch('public', [ShareController::class, 'updatePublic'])->name('public.update');
        Route::post('token', [ShareController::class, 'storeToken'])->name('token.store');
        Route::delete('token', [ShareController::class, 'destroyToken'])->name('token.destroy');
    });

    // W12 tree editor mutations (PLAN.md Phase 5, ui.md §3.3-§3.5). Kept
    // inside this `auth` group deliberately, unlike the read route below:
    // AssessmentPolicy already denies a guest every ability here (`update`
    // and `delete` both require a real $user — is_public/share-token only
    // ever grant `view`), so requiring auth up front turns that into a
    // clean 401 for an anonymous caller instead of a policy-driven 403,
    // matching the convention the W9 sharing routes above already set.
    // `{assessment:uuid}`/`{node:uuid}` bind by uuid, not id — the stable,
    // clone/snapshot-safe client-facing identity (schema.md §3.4) — and
    // NodeController/its FormRequests additionally assert the node belongs
    // to the route's assessment (security.md §3.3 IDOR pattern), since
    // Route::scopeBindings() would guess a relation name Node doesn't have.
    //
    // security.md §9: "Node and link mutations: 120/min per user" — the
    // outer group's `authenticated` default is not enough for this
    // sub-group specifically, so it gets its own, tighter limiter, in its
    // own bucket (RateLimitServiceProvider).
    // W40's suggestion endpoint. Deliberately outside the `throttle:mutations`
    // group below: it writes nothing, and a type-ahead field fires a request
    // per keystroke, so putting it in the mutation bucket would let a user
    // typing "multi-factor authentication" exhaust the budget for the edits
    // they are typing it in order to make. `throttle:search` is the limiter
    // security.md §9 already names for exactly this ("Search: 60/min"), and
    // it is the bucket the collaborator search already uses.
    Route::get('assessments/{assessment:uuid}/nodes/search', [NodeController::class, 'search'])
        ->middleware('throttle:search')
        ->name('assessments.nodes.search');

    Route::prefix('assessments/{assessment:uuid}')->name('assessments.')->middleware('throttle:mutations')->group(function () {
        Route::post('nodes', [NodeController::class, 'store'])->name('nodes.store');
        Route::patch('nodes/{node:uuid}', [NodeController::class, 'update'])->name('nodes.update');
        Route::delete('nodes/{node:uuid}', [NodeController::class, 'destroy'])->name('nodes.destroy');
    });
});

// ---------------------------------------------------------------------
// W73 — the two routes the sidebar needed (ui.md §2 and §8, D33).
//
// **Placed here rather than appended, and this is the one thing about this
// block that is not free choice.** `assessments.show` immediately below
// binds `assessments/{assessment:uuid}` with no pattern constraint, so a
// literal `assessments/shared` appended at the foot of this file would be
// matched by it first, bound as uuid `"shared"`, and 404 on the model
// lookup. The alternatives were constraining that route — an edit to a
// route W73 does not own — or giving D33's page a path it does not
// specify. Nothing existing is edited, moved or reordered by inserting
// here; only these two lines are new.
//
// `throttle:authenticated` — security.md §9's "everything else
// authenticated: 300/min per user". Asserted by value, never as "it has a
// throttle": a group whose limiter merely starts with `throttle` is not a
// guard (CLAUDE.md).
//
// W73 also added `assessments.new`, a POST that created an untitled
// assessment straight from the sidebar. It was removed at integration: it put
// two buttons reading "New assessment" on the list page doing two different
// things, one asking for a title and one not. The sidebar now links to
// `assessments.index` with `?new=1` and opens the creation dialog that page
// already owns, so there is one creation flow and one write path
// (`assessments.store`) rather than two that could drift.
// ---------------------------------------------------------------------
Route::middleware(['auth', 'active', 'throttle:authenticated'])->group(function () {
    Route::get('assessments/shared', [DashboardController::class, 'shared'])
        ->name('assessments.shared');
});

// The tree editor's read route (ui.md §3). Deliberately outside the `auth`
// group: `is_public` and a valid share-link session flag both grant `view`
// without an account (schema.md, security.md §3.1), matching why
// ShareController::resolveToken above is also unauthenticated.
// ShowAssessmentRequest still runs AssessmentPolicy::view() for every
// caller, logged in or not — this route is not itself the authorization
// boundary, the policy is (CLAUDE.md "every route touching an assessment
// goes through AssessmentPolicy. No exceptions").
Route::middleware('active')->get('assessments/{assessment:uuid}', [AssessmentController::class, 'show'])
    ->name('assessments.show');

// The read-only share-link landing point (security.md §3.2). Deliberately
// outside the auth group — presenting a valid token must work for someone
// with no account at all. Rate-limited per security.md §9: this is the
// token's brute-force surface.
Route::get('/s/{token}', [ShareController::class, 'resolveToken'])
    ->middleware('throttle:share-token')->name('share.resolve');

require __DIR__.'/auth.php';

// W13 node modal — Links tab edge mutations (ui.md §7, PLAN.md W13).
// Appended as its own group rather than merged into the W12
// `assessments/{assessment:uuid}` group above: this package's file
// ownership is "routes/web.php — append only... do not edit or reorder
// any existing route or group", and another package appends concurrently,
// so touching that group risks silently dropping its middleware in a
// hand-merge. Same `auth`+`active` middleware and `{assessment:uuid}`
// binding convention as that group, for the same reason documented there —
// AssessmentPolicy denies a guest every ability these need (creating,
// editing or removing an edge all require `update`).
//
// security.md §9: "Node and link mutations: 120/min per user" — this
// entire group is link mutations, so the group-level throttle replaces the
// 300/min default rather than stacking with it.
Route::middleware(['auth', 'active', 'throttle:mutations'])
    ->prefix('assessments/{assessment:uuid}')
    ->name('assessments.')
    ->group(function () {
        Route::post('risk-attack-links', [LinkController::class, 'storeRiskAttack'])
            ->name('risk-attack-links.store');
        Route::delete('risk-attack-links', [LinkController::class, 'destroyRiskAttack'])
            ->name('risk-attack-links.destroy');

        Route::post('attack-defence-links', [LinkController::class, 'storeAttackDefence'])
            ->name('attack-defence-links.store');
        Route::patch('attack-defence-links', [LinkController::class, 'updateAttackDefence'])
            ->name('attack-defence-links.update');
        Route::delete('attack-defence-links', [LinkController::class, 'destroyAttackDefence'])
            ->name('attack-defence-links.destroy');
    });

// ---------------------------------------------------------------------
// W14: keyboard, drag-and-drop, undo (ui.md §3.6, §4; schema.md §3.10).
// Appended, not merged into the W12 group above, per this package's file
// ownership: routes/web.php is append-only and editing an existing group
// is out of scope. Fully-qualified class names throughout — no new `use`
// import is added at the top of the file, keeping this block the only
// diff.
//
// Undo/redo/peek are fronted by app/Http/Requests/History/** (this
// package's own FormRequests, gating on AssessmentPolicy::update via
// Gate — any editor, not only the operation's author).
//
// The reorder/move endpoints below exist only because W12's merged tree
// editor core does not yet expose any HTTP endpoint for re-sequencing or
// re-parenting an edge — NodeController only has store/update/destroy,
// and UpdateNodeRequest's rules() do not accept `position` for any node
// type. That gap sits in app/Http/Controllers/NodeController.php and
// app/Http/Requests/Assessment/**, both outside this package's ownership
// (only app/Http/Controllers/UndoController.php is), so it cannot be
// closed by editing those files. Implementing it as closures here —
// authorize, validate, then delegate the actual mutation to
// App\Domain\Structure\ReorderService / LinkService (owned by W6, used
// but never edited) — is the narrowest way to make drag-and-drop actually
// persist without creating a second controller file this package does
// not own. See the W14 completion report for the full gap writeup,
// including that NodeController's store()/destroy() still do not call
// History\UndoService::record(), so create/delete are not yet undoable
// end-to-end from the UI (only through this route's own reorder/move
// actions, which do record).
//
// security.md §9: "Node and link mutations: 120/min per user" — reorder
// and move are node/edge mutations exactly like NodeController's, so this
// group carries the same tighter limiter rather than the 300/min default.
// ---------------------------------------------------------------------
Route::middleware(['auth', 'active', 'throttle:mutations'])->prefix('assessments/{assessment:uuid}')->name('assessments.')->group(function () {
    Route::get('undo', [UndoController::class, 'show'])->name('undo.show');
    Route::post('undo', [UndoController::class, 'undo'])->name('undo.store');
    Route::post('redo', [UndoController::class, 'redo'])->name('redo.store');

    // Same-parent sibling reorder (drag within one list). `scope` selects
    // which of the three position homes is being resequenced (schema.md
    // "ordering lives on the edge"); `order` is the complete new sibling
    // order as an array of uuids, matching ReorderService's own
    // full-resequence contract.
    Route::post('reorder', function (
        Request $request,
        Assessment $assessment,
        ReorderService $reorder,
        UndoService $undo,
    ) {
        if (! $request->user()?->can('update', $assessment)) {
            abort(403);
        }

        $data = $request->validate([
            'scope' => ['required', Rule::in(['nodes', 'risk_attack', 'attack_defence'])],
            'parent_uuid' => ['required_unless:scope,nodes', 'string'],
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['string'],
        ]);

        $scope = $data['scope'];
        $order = $data['order'];

        if ($scope === 'nodes') {
            $rows = Node::whereIn('uuid', $order)->get()->keyBy('uuid');
            if ($rows->count() !== count($order)) {
                return response()->json(['message' => 'A node in this reorder no longer exists.'], 422);
            }
            $entries = [];
            foreach ($order as $uuid) {
                $entries[] = ['key' => ['node_uuid' => $uuid], 'position' => $rows[$uuid]->position];
            }
            // W25: was `$rows->only($order)->sortBy(...)->pluck('id')`, which
            // always produced an empty array — so this endpoint answered 200
            // and reordered nothing at all.
            //
            // `$rows` is an Eloquent collection, and
            // Illuminate\Database\Eloquent\Collection overrides only() to
            // select models *by primary key* rather than by array key. Keyed
            // by uuid it still answers has($uuid) === true, which is what
            // makes the bug so quiet: every reasonable spot-check of the
            // collection looks right, and only the result of only() is empty.
            //
            // Mapping $order directly is also clearer about the contract:
            // $order IS the new sequence, so there is nothing to sort.
            $reorder->reorderNodes(array_map(fn ($uuid) => $rows[$uuid]->id, $order));
            $undo->record($assessment, $request->user(), 'node.move', 'Reordered nodes', [
                'action' => 'reorder_positions', 'scope' => 'nodes', 'entries' => $entries,
            ]);

            return response()->json(['ok' => true]);
        }

        $parentUuid = (string) $data['parent_uuid'];
        $meta = EdgeResolver::meta($scope);
        $parent = EdgeResolver::findNode($parentUuid);
        if ($parent === null) {
            return response()->json(['message' => 'The parent of this reorder no longer exists.'], 422);
        }

        $childRows = Node::whereIn('uuid', $order)->get()->keyBy('uuid');
        if ($childRows->count() !== count($order)) {
            return response()->json(['message' => 'A node in this reorder no longer exists.'], 422);
        }

        $modelClass = $meta['model'];
        $existing = $modelClass::query()->where($meta['parentColumn'], $parent->id)->get()->keyBy($meta['childColumn']);

        $entries = [];
        foreach ($order as $uuid) {
            $childId = $childRows[$uuid]->id;
            $row = $existing[$childId] ?? null;
            if ($row === null) {
                return response()->json(['message' => 'A link in this reorder no longer exists.'], 422);
            }
            $entries[] = [
                'key' => ['parent_uuid' => $parentUuid, 'child_uuid' => $uuid],
                'position' => $row->getAttribute('position'),
            ];
        }

        $childIdsInOrder = $order === [] ? [] : array_map(fn ($uuid) => $childRows[$uuid]->id, $order);

        if ($scope === 'risk_attack') {
            $reorder->reorderAttacksUnderRisk($parent->id, $childIdsInOrder);
        } else {
            $reorder->reorderDefencesUnderAttack($parent->id, $childIdsInOrder);
        }

        $undo->record($assessment, $request->user(), 'node.move', 'Reordered '.($scope === 'risk_attack' ? 'attacks' : 'defences'), [
            'action' => 'reorder_positions', 'scope' => $scope, 'entries' => $entries,
        ]);

        return response()->json(['ok' => true]);
    })->name('reorder');

    // Cross-parent re-parent (drag onto a different parent). Only the
    // dragged occurrence's own edge moves — a shared node's other
    // occurrences live on separate edge rows untouched by this (ui.md
    // §3.1). `edge` fixes which endpoint role is which: for risk_attack,
    // parent = risk and child = attack; for attack_defence, parent =
    // attack and child = defence.
    Route::post('move', function (
        Request $request,
        Assessment $assessment,
        LinkService $links,
        UndoService $undo,
    ) {
        if (! $request->user()?->can('update', $assessment)) {
            abort(403);
        }

        $data = $request->validate([
            'edge' => ['required', Rule::in(['risk_attack', 'attack_defence'])],
            'from_parent_uuid' => ['required', 'string'],
            'to_parent_uuid' => ['required', 'string'],
            'child_uuid' => ['required', 'string'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $edge = $data['edge'];
        $meta = EdgeResolver::meta($edge);
        $fromParent = EdgeResolver::findNode($data['from_parent_uuid']);
        $toParent = EdgeResolver::findNode($data['to_parent_uuid']);
        $child = EdgeResolver::findNode($data['child_uuid']);

        if ($fromParent === null || $toParent === null || $child === null) {
            return response()->json(['message' => 'An endpoint of this move no longer exists.'], 422);
        }

        $modelClass = $meta['model'];
        $currentRow = $modelClass::query()
            ->where($meta['parentColumn'], $fromParent->id)
            ->where($meta['childColumn'], $child->id)
            ->first();

        if ($currentRow === null) {
            return response()->json(['message' => 'This node is not currently linked there.'], 422);
        }

        $capturedPosition = (int) $currentRow->getAttribute('position');
        $extra = $edge === 'attack_defence' ? [
            'strength_mode' => $currentRow->getAttribute('strength_mode'),
            'strength_fraction' => $currentRow->getAttribute('strength_fraction'),
            'strength_level_id' => $currentRow->getAttribute('strength_level_id'),
            'reduction_target' => $currentRow->getAttribute('reduction_target'),
            'rationale' => $currentRow->getAttribute('rationale'),
        ] : [];

        $position = $data['position'] ?? null;

        if ($edge === 'risk_attack') {
            /** @var Risk $fromParent */
            /** @var Risk $toParent */
            /** @var Attack $child */
            $links->unlinkRiskFromAttack($fromParent, $child);
            $links->linkRiskToAttack($toParent, $child, $position);
        } else {
            /** @var Attack $fromParent */
            /** @var Attack $toParent */
            /** @var Defence $child */
            $links->unlinkAttackFromDefence($fromParent, $child);
            $newLink = $links->linkAttackToDefence($toParent, $child, $position, $extra['reduction_target']);
            $newLink->forceFill([
                'strength_mode' => $extra['strength_mode'],
                'strength_fraction' => $extra['strength_fraction'],
                'strength_level_id' => $extra['strength_level_id'],
                'rationale' => $extra['rationale'],
            ])->save();
        }

        $undo->record($assessment, $request->user(), 'node.move', "Moved '{$child->title}'", [
            'action' => 'reparent_edge',
            'edge' => $edge,
            'parent_uuid' => $data['from_parent_uuid'],
            'current_parent_uuid' => $data['to_parent_uuid'],
            'child_uuid' => $data['child_uuid'],
            'position' => $capturedPosition,
            'extra' => $extra,
        ]);

        return response()->json(['ok' => true]);
    })->name('move');
});

// ---------------------------------------------------------------------
// W16 — Real-time collaboration (PLAN.md Phase 6, docs/specs/security.md
// §8). Appended as its own block for the same reason the W13 block above
// documents its own append: this package's ownership is "routes/web.php —
// append only... do not edit or reorder existing routes or groups", and
// other packages append concurrently.
//
// POST /broadcasting/auth itself is registered in bootstrap/app.php
// (->withBroadcasting()), not here — see that file's docblock for why it
// could not be left to withRouting()'s own `channels:` shortcut.

// Field-level locking (PLAN.md Phase 6 item 3, security.md). No table, no
// polling — LockController talks to Redis directly
// (app/Domain/Locking/LockManager.php). `{assessment:uuid}`/`{node:uuid}`
// match the W12/W13 groups' binding convention above; `{field}` is
// constrained to the same charset LockController's own allow-list uses, so
// an obviously-invalid value 404s at the routing layer rather than reaching
// the controller. `auth`+`active` up front, same reasoning as the W12 node-
// mutation group: AssessmentPolicy never grants `update` to a guest, so
// this just turns that into a 401 instead of a 403.
// security.md §9 default: "everything else authenticated: 300/min per
// user" — field locks are writes but not node/link mutations proper.
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/locks')
    ->name('assessments.locks.')
    ->group(function () {
        Route::post('{node:uuid}/{field}', [LockController::class, 'store'])
            ->where('field', '[a-z_]+')
            ->name('store');
        Route::patch('{node:uuid}/{field}', [LockController::class, 'update'])
            ->where('field', '[a-z_]+')
            ->name('update');
        Route::delete('{node:uuid}/{field}', [LockController::class, 'destroy'])
            ->where('field', '[a-z_]+')
            ->name('destroy');
    });

// ---------------------------------------------------------------------
// W17 — reports (PLAN.md Phase 7, docs/specs/ui.md §9). Appended as its own
// block for the same reason every other package's block above documents
// its own append: this package's file ownership is "routes/web.php —
// append only... never edit or reorder existing lines", and other packages
// append here too.
//
// Deliberately outside the `auth` group and using the same `active`
// middleware + `{assessment:uuid}` binding convention as the W12 read
// route (assessments.show) just above `require __DIR__.'/auth.php'`: the
// report is exactly as visible as the tree it reports on — a public
// assessment or a valid share-link session both grant `view` with no
// account (schema.md, security.md §3.1) — so gating this behind `auth`
// would be a narrower authorization surface than AssessmentPolicy itself
// grants. ReportController::authorizeView() calls
// Gate::forUser($request->user())->denies('view', $assessment) for the
// same reason ShowAssessmentRequest uses Gate::forUser() rather than
// `$request->user()?->can(...)` — a null/guest user must still reach
// AssessmentPolicy::view()'s is_public/share-token branches instead of
// short-circuiting to false first. `throttle:reports` matches
// security.md §9's "Report generation: 10/min per user" row.
// ---------------------------------------------------------------------
Route::middleware(['active', 'throttle:reports'])
    ->prefix('assessments/{assessment:uuid}/report')
    ->name('assessments.report.')
    ->group(function () {
        Route::get('/', [ReportController::class, 'show'])->name('show');

        // W74 / D34 — the five formats behind the one export menu. Every one
        // of them is a read of the same assembled report and carries the same
        // `throttle:reports` and `active` middleware as the page, because an
        // export is a report generation: exempting them would put the
        // expensive path outside the limit that exists for it
        // (security.md §9, "Report generation: 10/min per user").
        //
        // `defences.csv` and `export.json` keep their paths and their route
        // names. The CSV is the whole report now rather than the ranked
        // defence list, but the URL is in e2e coverage this package does not
        // own and in whatever has bookmarked it since W17.
        //
        // PDF is deliberately not a route here. It is the browser's own print
        // pipeline (D6, not reopened) — no headless-Chromium sidecar, and
        // `dompdf` stays gone.
        Route::get('export.html', [ReportController::class, 'exportHtml'])->name('export-html');
        Route::get('defences.csv', [ReportController::class, 'defencesCsv'])->name('defences-csv');
        Route::get('export.json', [ReportController::class, 'exportJson'])->name('export-json');
        Route::get('export.xlsx', [ReportController::class, 'exportExcel'])->name('export-excel');
        Route::get('export.docx', [ReportController::class, 'exportWord'])->name('export-word');
    });

// ---------------------------------------------------------------------
// W19 — snapshots (PLAN.md Phase 8 item 3, schema.md §3.13). Appended as
// its own block per this package's file ownership: "routes/web.php —
// APPEND ONLY, in a single clearly-marked block at the very end. Never
// edit or reorder an existing line."
//
// `{assessment:uuid}` matches every other package's binding convention
// above; `{snapshot}` binds by numeric id — Snapshot carries no uuid
// column (schema.md §3.13) — and is deliberately NOT declared
// `{snapshot:uuid}` nor scoped via Route::scopeBindings() (Assessment has
// no singular `snapshot()` relation for that mechanism to guess), so
// SnapshotController re-asserts the cross-assessment ownership itself,
// same IDOR pattern as the W12 node routes above.
//
// Read routes (index/show/diff) sit outside the `auth` group and use only
// the `active` middleware, exactly like the W17 report routes just above:
// a snapshot is exactly as visible as the tree it was taken from, and
// `view` covers is_public and a valid share-link session with no account.
// `store`/`destroy` require `update` (CLAUDE.md's W19 brief: "Creating and
// deleting a snapshot needs `update`; viewing needs `view`") and sit inside
// the `auth`+`active` group so an anonymous caller gets a clean 401 rather
// than a policy-driven 403, matching the W12 mutation group's convention.
// ---------------------------------------------------------------------
Route::middleware('active')
    ->prefix('assessments/{assessment:uuid}/snapshots')
    ->name('assessments.snapshots.')
    ->group(function () {
        Route::get('/', [SnapshotController::class, 'index'])->name('index');
        Route::get('diff', [SnapshotController::class, 'diff'])->name('diff');
        Route::get('{snapshot}', [SnapshotController::class, 'show'])->name('show');
    });

// security.md §9 default: "everything else authenticated: 300/min per
// user".
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/snapshots')
    ->name('assessments.snapshots.')
    ->group(function () {
        Route::post('/', [SnapshotController::class, 'store'])->name('store');
        Route::delete('{snapshot}', [SnapshotController::class, 'destroy'])->name('destroy');
    });

// ---------------------------------------------------------------------
// W18 — comments + history (PLAN.md Phase 8 items 1-2, schema.md §3.8-3.9,
// ui.md §7). Appended as its own block per this package's file ownership:
// "routes/web.php — APPEND ONLY, in a single clearly-marked block at the
// very end. Never edit or reorder an existing line." `use
// App\Http\Controllers\CommentController;` was inserted into the existing
// alphabetised `use` block at the top, matching how every prior package
// (W15/W17/W19's own controller imports) already did the same — the
// established precedent for "append only" has always meant "do not touch
// any other package's route registrations or their meaning", not
// "literally the last byte of the file".
//
// `{assessment:uuid}`/`{node:uuid}`/`{comment}` follow the same binding
// convention as every group above: `{comment}` implicitly scopes to the
// route's assessment via Laravel's guessed `Assessment::comments()`
// relation (added by W6 — see app/Models/Assessment.php), the identical
// IDOR-safe pattern NodeControllerTest documents for `{node:uuid}`.
//
// Read routes (listing comments, the unresolved-count map, listing a
// node's revision history) sit outside `auth` and use only `active`,
// matching the W17/W19 read-route convention: `view` is granted by
// is_public and a valid share-link session with no account, so gating
// behind `auth` would be a narrower surface than AssessmentPolicy itself
// grants. Every mutating comment route needs the assessment's `comment`
// ability (schema.md §3.7's whole reason the `commenter` role exists:
// "commenter costs nothing to add now" — it can discuss but never
// `update`); AssessmentPolicy::comment() requires a non-null $user for
// every branch (unlike `view`, is_public/token grant nothing here), so
// these sit inside `auth`+`active` for a clean 401 rather than a
// policy-driven 403, the same reasoning the W12/W16 mutation groups give.
//
// --- The node_revisions / "revert this field" gap — closed by W18b ---
// The original W18 package found no writer of `node_revisions` anywhere in
// the codebase (App\Domain\History\RevisionRecorder did not exist, and
// NodeController::update() saved a node's fields without recording
// anything) and, per CLAUDE.md's "stop and report — do not silently
// diverge", implemented the list/revert endpoints as route closures rather
// than reach into app/Domain/History/** or add a controller — paths it did
// not own. It also left tests/Feature/History/NodeRevisionRevertTest.php
// seeding node_revisions rows directly, exactly as any future writer would
// leave them, so the list/group/revert mechanics were provably correct
// independent of who populated the table.
//
// W18b (this package) is that follow-up: it built
// App\Domain\History\RevisionRecorder (wired into NodeObserver::updated(),
// which now writes a node_revisions row for every ordinary field save —
// see that class's own docblock for why create/delete are deliberately
// excluded), and moved these two closures into
// App\Http\Controllers\NodeRevisionController plus
// App\Domain\History\RevisionReverter (CLAUDE.md: "Business logic goes in
// app/Domain/, not in controllers and never in views... Route closures are
// worse than either"). NodeRevisionRevertTest's seeded-row tests are
// unchanged and still pass — this only changes who else also writes rows,
// not the list/revert contract they were written against.
// ---------------------------------------------------------------------
Route::middleware('active')
    ->prefix('assessments/{assessment:uuid}/nodes/{node:uuid}/comments')
    ->name('assessments.comments.')
    ->group(function () {
        Route::get('/', [CommentController::class, 'index'])->name('index');
    });

Route::middleware('active')
    ->prefix('assessments/{assessment:uuid}/comments')
    ->name('assessments.comments.')
    ->group(function () {
        Route::get('unresolved-counts', [CommentController::class, 'unresolvedCounts'])->name('unresolved-counts');
    });

// security.md §9 default: "everything else authenticated: 300/min per
// user".
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/nodes/{node:uuid}/comments')
    ->name('assessments.comments.')
    ->group(function () {
        Route::post('/', [CommentController::class, 'store'])->name('store');
    });

// security.md §9 default: "everything else authenticated: 300/min per
// user".
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/comments')
    ->name('assessments.comments.')
    ->group(function () {
        Route::patch('{comment}', [CommentController::class, 'update'])->name('update');
        Route::delete('{comment}', [CommentController::class, 'destroy'])->name('destroy');
        Route::post('{comment}/resolve', [CommentController::class, 'resolve'])->name('resolve');
        Route::delete('{comment}/resolve', [CommentController::class, 'unresolve'])->name('unresolve');
    });

// Per-node revision history (schema.md §3.9, ui.md §7). `view` for
// listing, matching the comments read routes just above; `update` for
// reverting a field ("reverting a field needs update") — a revert is a
// real write to the node, gated exactly like NodeController::update()
// gates any other one. Logic lives in NodeRevisionController and
// App\Domain\History\RevisionReverter (W18b) — see that controller's own
// docblock for why this used to be two route closures.
Route::middleware('active')
    ->prefix('assessments/{assessment:uuid}/nodes/{node:uuid}/revisions')
    ->name('assessments.nodes.revisions.')
    ->group(function () {
        Route::get('/', [NodeRevisionController::class, 'index'])->name('index');
    });

// security.md §9 default: "everything else authenticated: 300/min per
// user".
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/nodes/{node:uuid}/revisions')
    ->name('assessments.nodes.revisions.')
    ->group(function () {
        Route::post('{revision}/revert', [NodeRevisionController::class, 'revert'])->name('revert');
    });

// W20 — templates + catalogue
// ---------------------------------------------------------------------
// Templates and catalogue (schema.md §3.14, PLAN.md Phase 8 item 4).
// Appended as its own block per this package's file ownership:
// "routes/web.php — APPEND ONLY, in a single clearly-marked block at the
// very end. Never edit or reorder an existing line."
//
// Catalogue browsing (`GET /catalog`) needs only an authenticated user
// (CLAUDE.md's W20 brief: "browsing the catalogue needs an authenticated
// user") — it is a global library, not scoped to any one assessment, so
// it sits in `auth`+`active` for a clean 401 rather than a
// policy-driven 403, matching the W12/W14/W19 mutation groups' own
// convention above. Inserting an entry into a specific assessment
// (`POST .../catalog-entries/{catalogEntry}/insert`) additionally
// requires `update` on that assessment, checked inside
// CatalogController::insert() itself via Gate::forUser() — the same
// "guest still reaches AssessmentPolicy" reasoning ShowAssessmentRequest
// uses — since AssessmentPolicy is the single source of truth for that
// decision and this route sits inside `auth` only for the clean-401
// reason above, not as the authorization boundary itself.
//
// `{assessment:uuid}` matches every other package's binding convention;
// `{catalogEntry}` binds by numeric id — CatalogEntry carries no uuid
// column (schema.md §3.14) — matching SnapshotController's own
// `{snapshot}` convention and its documented reason (Assessment has no
// singular relation for Route::scopeBindings() to guess from). No
// cross-assessment IDOR risk exists for catalogEntry the way it does for
// {snapshot}, since a catalogue entry belongs to no assessment at all —
// only the optional `parent_uuid` in the request body needs — and gets —
// that same scoping check, done in the controller.
//
// Template routes: `GET /templates` lists templates the caller can
// `view` (owner, a shared role, is_public, or a valid share-link
// session — the same audience `view` grants everywhere else), so it also
// sits in `auth`+`active` for the clean-401 reason above even though
// `view` itself would tolerate a guest, matching how the W12 node-mutate
// group reasons about `update`/`delete` never being guest-reachable.
// `PATCH /assessments/{assessment:uuid}/template` needs `saveAsTemplate`
// — owner or admin. W20 gated it on `manage`; W45 narrowed it, because
// publishing sets `is_public` too and so exposes every value in the
// assessment to the whole instance, which is an ownership-weight decision
// rather than a sharing one (see AssessmentPolicy::saveAsTemplate).
// `GET /assessments/{assessment:uuid}/template` reports the two flags to
// the Share dialog and needs only `view`;
// `POST /templates/{assessment:uuid}/use` needs `view` on the source
// (TemplateController::store()'s own docblock explains why `view`, not
// `update`, is correct here) plus the source actually being a template,
// checked in the controller since AssessmentPolicy has no ability for
// "is this specific assessment usable as a template" — that is not an
// authorization question, it is a data invariant.
// ---------------------------------------------------------------------
// security.md §9 default: "everything else authenticated: 300/min per
// user".
Route::middleware(['auth', 'active', 'throttle:authenticated'])->group(function () {
    Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');

    Route::post('assessments/{assessment:uuid}/catalog-entries/{catalogEntry}/insert', [CatalogController::class, 'insert'])
        ->name('assessments.catalog-entries.insert');

    Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('templates/{assessment:uuid}/use', [TemplateController::class, 'store'])->name('templates.use');
    // 2.5.1: "add from template" in the editor — read-only, `useAsTemplate`.
    Route::get('templates/options', [TemplateController::class, 'options'])->name('templates.options');
    Route::get('templates/{assessment:uuid}/nodes', [TemplateController::class, 'nodes'])->name('templates.nodes');
    Route::get('assessments/{assessment:uuid}/template', [TemplateController::class, 'show'])->name('assessments.template.show');
    Route::patch('assessments/{assessment:uuid}/template', [TemplateController::class, 'update'])->name('assessments.template.update');
});

// W21 — external references
// ---------------------------------------------------------------------
// CVE and friends (schema.md §3.12, ui.md §7's References tab, PLAN.md
// Phase 8 item 5). Appended as its own block per this package's file
// ownership: "routes/web.php is shared: append only, in a single clearly
// marked block at the end. Do not touch or reorder existing routes."
//
// `{assessment:uuid}/nodes/{node:uuid}/references` matches the W18/W18b
// comments-and-revisions nesting convention exactly: `{node:uuid}` scopes
// through `Assessment::nodes()` via Laravel's implicit route-model-binding
// scoping, and `ReferenceController`/its FormRequests additionally assert
// `$node->assessment_id === $assessment->id` (security.md §3.3 IDOR
// pattern), the same belt-and-suspenders NodeController's own FormRequests
// use — Route::scopeBindings() is not relied on alone.
//
// Read route sits outside `auth` — `view` tolerates a guest via
// is_public/a share-link session (schema.md, security.md §3.1), matching
// ShowAssessmentRequest/CommentController::index's own reasoning — so
// ReferenceController::index() runs Gate::forUser() itself rather than the
// route boundary rejecting an anonymous caller before that policy check
// ever runs. Every mutation (`store`/`reorder`/`destroy`) needs `update`,
// which AssessmentPolicy never grants a guest, so those three sit inside
// `auth`+`active` for a clean 401, matching every other mutation group
// above.
Route::middleware('active')
    ->prefix('assessments/{assessment:uuid}/nodes/{node:uuid}/references')
    ->name('assessments.node-references.')
    ->group(function () {
        Route::get('/', [ReferenceController::class, 'index'])->name('index');
    });

// security.md §9 default: "everything else authenticated: 300/min per
// user".
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/nodes/{node:uuid}/references')
    ->name('assessments.node-references.')
    ->group(function () {
        Route::post('/', [ReferenceController::class, 'store'])->name('store');
        Route::post('reorder', [ReferenceController::class, 'reorder'])->name('reorder');
        // {reference} binds by plain id, not {reference:uuid} —
        // node_references carries no uuid column (schema.md §3.12) — so
        // this is not auto-scoped to $node the way {node:uuid} is scoped
        // to $assessment; ReferenceController::destroy() re-asserts
        // $reference->node_id === $node->id explicitly, matching
        // NodeRevisionController::revert()'s identical plain-id-bound
        // {revision} pattern.
        Route::delete('{reference}', [ReferenceController::class, 'destroy'])->name('destroy');
    });

// W23 — help + docs
// ---------------------------------------------------------------------
// Markdown help pages (PLAN.md Phase 9 item 2, resources/markdown/help/**).
// Appended as its own block per this package's file ownership:
// "routes/web.php is shared: append only, in a single clearly-marked block
// at the end. Do not touch or reorder existing routes."
//
// Deliberately outside the `auth` group and using only the `active`
// middleware, matching the W17/W19/W21 read-route convention above: help
// is general product documentation, not assessment data, so a facilitator
// running a public workshop or a guest reading a shared assessment through
// a read-only link (both of whom may have no account at all) can reach it
// without logging in. `active` still applies so a deactivated user's
// session is rejected here exactly as it is everywhere else — see
// EnsureUserIsActive, which is a no-op for a null/guest user and only
// bites an authenticated-but-deactivated one.
//
// `{slug}` never becomes a filesystem path: HelpController resolves it
// through App\Domain\Help\HelpLibrary, a hardcoded manifest, and 404s an
// unknown slug before any file is touched — no dynamic path from request
// input, the same discipline security.md §7 requires of column names.
Route::middleware('active')->prefix('help')->name('help.')->group(function () {
    Route::get('/', [HelpController::class, 'index'])->name('index');
    Route::get('{slug}', [HelpController::class, 'show'])->name('show');
});

// W26 — assessment lifecycle: list, create, delete
// ---------------------------------------------------------------------
// The application shipped without any of these. Every other assessment
// route is `assessments/{assessment}/...`, so nothing could produce the
// assessment they all presuppose: a fresh deployment reached first-run
// setup, logged in, and then had no way to create anything. docs/PLAN.md
// never assigned this to a package — it fell between W8, W9 and W12.
//
// `index` and `store` take no {assessment}, so there is no subject for
// AssessmentPolicy to authorize: `auth`+`active` is the boundary, and the
// index scopes its own query to the caller's owned and shared rows rather
// than filtering after the fact. `destroy` does have a subject and goes
// through the policy's `delete` ability like everything else.
//
// Appended as its own block, per the convention every package here
// follows for this shared file.
// security.md §9 default: "everything else authenticated: 300/min per
// user".
Route::middleware(['auth', 'active', 'throttle:authenticated'])->group(function () {
    Route::get('assessments', [AssessmentLifecycleController::class, 'index'])->name('assessments.index');
    Route::post('assessments', [AssessmentLifecycleController::class, 'store'])->name('assessments.store');
    Route::delete('assessments/{assessment:uuid}', [AssessmentLifecycleController::class, 'destroy'])
        ->name('assessments.destroy');

    // W42 item 20. Gated on `view`, not `manage`: duplicating clones into
    // the actor's own ownership and touches nothing in the source, which is
    // exactly the reasoning `templates.use` already follows (W20) — anyone
    // who can read a thing can build their own private copy of it.
    Route::post('assessments/{assessment:uuid}/duplicate', [AssessmentLifecycleController::class, 'duplicate'])
        ->name('assessments.duplicate');
});

// W43 labels (item 18). Appended as its own block, per the convention every
// package here follows for this shared file.
//
// security.md §9: "Node and link mutations: 120/min per user" — labelling a
// node is a node mutation and belongs in that bucket, not in the 300/min
// default. Asserted by value in tests/Feature/Security, because a group
// whose limiter merely *starts with* `throttle` is not a guard (CLAUDE.md).
Route::middleware(['auth', 'active', 'throttle:mutations'])
    ->prefix('assessments/{assessment:uuid}')
    ->name('assessments.')
    ->group(function () {
        Route::post('nodes/{node:uuid}/tags', [TagController::class, 'store'])->name('nodes.tags.store');
        Route::delete('nodes/{node:uuid}/tags/{tag}', [TagController::class, 'destroy'])->name('nodes.tags.destroy');
    });

// W44 assessment settings (item 12). Appended as its own block, per the
// convention every package here follows for this shared file.
//
// Gated on `manage` inside the controller and the FormRequest rather than by
// the middleware: these change what every figure in the assessment means, so
// they are an ownership-level act, not an edit. security.md §9's default
// "everything else authenticated: 300/min per user" is the right bucket — a
// settings form is submitted once, not typed into.
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}')
    ->name('assessments.')
    ->group(function () {
        Route::get('settings', [AssessmentSettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('settings', [AssessmentSettingsController::class, 'update'])->name('settings.update');
    });

// W46 assignees (item 16). Appended as its own block, per the convention
// every package here follows for this shared file.
//
// security.md §9: "Node and link mutations: 120/min per user". Assigning is a
// node mutation and belongs in that bucket, not in the 300/min default.
// Asserted by value in tests/Feature/Security, because a group whose limiter
// merely *starts with* `throttle` is not a guard (CLAUDE.md).
//
// Every route is gated inside AssignmentController: `view` to read the list,
// `update` to change it, and `manage` — additionally — for the one path that
// would share the assessment with someone new. That last split is the whole
// authorization content of this package; see the controller's docblock for
// why an editor must not reach it.
Route::middleware(['auth', 'active', 'throttle:mutations'])
    ->prefix('assessments/{assessment:uuid}')
    ->name('assessments.')
    ->group(function () {
        Route::get('assignee-candidates', [AssignmentController::class, 'candidates'])->name('assignee-candidates.index');
        Route::get('nodes/{node:uuid}/assignees', [AssignmentController::class, 'index'])->name('nodes.assignees.index');
        Route::post('nodes/{node:uuid}/assignees', [AssignmentController::class, 'store'])->name('nodes.assignees.store');
        Route::delete('nodes/{node:uuid}/assignees/{user}', [AssignmentController::class, 'destroy'])->name('nodes.assignees.destroy');
    });

// W47 notifications (item 15). Appended as its own block, per the convention
// every package here follows for this shared file.
//
// No assessment in the route and so nothing for AssessmentPolicy to decide:
// every query is scoped to `$request->user()->notifications()`, which answers
// "whose feed is this?" before the database does. Someone else's id finds no
// row and 404s — 403 would confirm the id exists.
//
// security.md §9's default bucket. A notification panel is opened, not typed
// into, and the mark-read calls are one per open.
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('notifications')
    ->name('notifications.')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::patch('/', [NotificationController::class, 'update'])->name('update');
        Route::patch('{notification}', [NotificationController::class, 'markRead'])->name('read');
    });

// W48 history (item 21). Appended as its own block, per the convention every
// package here follows for this shared file.
//
// `view`, gated in the controller — history is the record of what was done to
// something you can already read, and gating it higher would let a viewer see
// today's numbers but not how they got there, which is the opposite of what a
// defensible assessment is for.
//
// There is no revert route. D15 specifies one; the audit this package's brief
// required found that several mutations reach the graph without writing a
// revision — a defence's strength among them — so a reconstruction would
// produce an assessment that looks restored and is quietly wrong. The findings
// are pinned in tests/Feature/History/RevisionCoverageTest.php.
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}')
    ->name('assessments.')
    ->group(function () {
        Route::get('history', [AssessmentHistoryController::class, 'index'])->name('history.index');
        // 2.5.1: save the current version by hand, from the History page.
        Route::post('history/versions', [AssessmentHistoryController::class, 'store'])->name('history.versions.store');
    });

// W50 — the assistant runtime (PLAN-2.3-ai.md). Appended as its own block, per
// the convention every package here follows for this shared file.
//
// **`assistant` is the point of this block.** D25: a deployment with no
// inference runtime does not serve a disabled assistant endpoint, it serves no
// assistant endpoint — so the feature 404s for someone who read about it
// elsewhere and tried the URL. The middleware answers that from config alone,
// touching neither the database nor the network, so the overwhelming majority
// of installations that will never run a runtime pay nothing.
//
// It is middleware rather than an `if` around this block because a boot-time
// branch cannot be tested in a suite that boots once — see
// EnsureAssistantConfigured for the full reasoning. The two remaining
// conditions, an admin having switched it on and the runtime actually
// answering, are per-request and live in App\Domain\Assistant\Availability,
// which the controller consults.
//
// `throttle:authenticated` is security.md §9's default bucket. The tighter
// per-user limit the generating routes need arrives with W52, which is the
// first package that can exhaust a CPU; asking for a status is not that.
Route::middleware(['auth', 'active', 'assistant', 'throttle:authenticated'])
    ->prefix('assistant')
    ->name('assistant.')
    ->group(function () {
        Route::get('status', AssistantStatusController::class)->name('status');

        // W51 — job A. `throttle:assistant` (6/min per user) stacks on the
        // group's default and binds first; it is a named limiter, so it is a
        // genuinely separate bucket rather than double-counting the same one.
        //
        // Behind `auth` even though /help itself is not: the help *pages* are
        // public product documentation and cost a file read, while this spends
        // seconds of CPU per call. See AssistantHelpController.
        Route::post('help', AssistantHelpController::class)
            ->middleware('throttle:assistant')
            ->name('help');
    });

// W52 — job B. Assessment-scoped, so it lives on the assessment prefix rather
// than the flat /assistant one: SuggestChildrenRequest runs AssessmentPolicy
// like every other route touching an assessment, and the parent uuid is
// checked against *this* assessment so the endpoint cannot be used to read one
// assessment's node titles from inside another (security.md §3.3).
//
// Same three guards as job A: `assistant` (the deployment has a runtime),
// `throttle:assistant` (6/min per user), and SingleFlight inside the
// controller (one at a time, which the per-minute limit cannot see).
Route::middleware(['auth', 'active', 'assistant', 'throttle:assistant'])
    ->prefix('assessments/{assessment:uuid}/assistant')
    ->name('assessments.assistant.')
    ->group(function () {
        Route::post('suggestions', AssistantSuggestionController::class)->name('suggestions');
    });

// W54 — job C, the drafted executive summary (PLAN-2.3-ai.md). Appended as its
// own block, per the append-only rule every block above follows.
//
// **Two groups, and the split is the package's main deployment decision.**
//
// The drafting route is assistant-gated like job A and B: `assistant` (this
// deployment has a runtime at all), `throttle:assistant` (6/min per user), and
// a per-assessment in-progress flag in the controller, which the per-minute
// limit cannot see because two different collaborators would each be within
// theirs.
//
// The narrative routes are not gated on anything assistant-related. The
// report's written summary is an ordinary field of the assessment that a
// person types; the overwhelming majority of deployments have no inference
// runtime (D25) and must still be able to write, edit and clear it. Putting
// these behind the `assistant` middleware would have made a plain text field
// disappear on every instance that never wanted an assistant.
Route::middleware(['auth', 'active', 'assistant', 'throttle:assistant'])
    ->prefix('assessments/{assessment:uuid}/assistant')
    ->name('assessments.assistant.')
    ->group(function () {
        Route::post('summary', AssistantSummaryController::class)->name('summary');
    });

Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/summary')
    ->name('assessments.summary.')
    ->group(function () {
        Route::put('/', [SummaryNarrativeController::class, 'update'])->name('update');
        Route::delete('draft', [SummaryNarrativeController::class, 'destroyDraft'])->name('draft.destroy');
    });

// W58 — progress and cancellation. Appended as its own block, per the
// append-only rule every block above follows.
//
// **`throttle:authenticated`, not `throttle:assistant`, and that is the
// deliberate part.** The 6/min assistant limiter bounds CPU spent on
// inference. These four routes spend none — they read and write a cache key —
// and putting a poll in that bucket would make it impossible to watch a
// four-minute job for longer than its first thirty seconds. Assert the rate
// these carry rather than that they carry one: a presence check would not
// notice the difference (RELEASE-2.0.1.md, and CLAUDE.md's rule about it).
//
// Still behind `assistant`: a deployment with no runtime has no generation to
// report on, and D25's "no assistant route exists at all" holds for these too.
Route::middleware(['auth', 'active', 'assistant', 'throttle:authenticated'])
    ->prefix('assistant')
    ->name('assistant.')
    ->group(function () {
        Route::get('generation', [AssistantGenerationController::class, 'show'])->name('generation.show');
        Route::delete('generation', [AssistantGenerationController::class, 'destroy'])->name('generation.destroy');
    });

Route::middleware(['auth', 'active', 'assistant', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/assistant')
    ->name('assessments.assistant.')
    ->group(function () {
        Route::get('summary/progress', [AssistantGenerationController::class, 'summary'])->name('summary.progress');
        Route::delete('summary', [AssistantGenerationController::class, 'cancelSummary'])->name('summary.cancel');
    });

// ---------------------------------------------------------------------
// W61 — snapshot restore. Appended as its own block, per the append-only
// rule every block above follows.
//
// The route W48 pinned as missing: `assessments.snapshots.restore`, asserted
// by name in tests/Feature/History/RevisionCoverageTest.php so that building
// it turns that pin red rather than letting it be forgotten.
//
// `update`, not `manage`, and the reasoning is on SnapshotController::restore:
// undo is `update`-gated and the inverse of a restore is another restore, so
// gating this higher would have been a longer route to the same place rather
// than a guard.
//
// `throttle:authenticated` — the default for an authenticated mutation
// (security.md §9). A restore is expensive but it is one deliberate act
// behind a confirmation dialog, not something a client loops over.
// ---------------------------------------------------------------------
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/snapshots')
    ->name('assessments.snapshots.')
    ->group(function () {
        Route::post('{snapshot}/restore', [SnapshotController::class, 'restore'])->name('restore');
    });

// ---------------------------------------------------------------------
// W63 — browsing a frozen version. Appended as its own block, per the
// append-only rule every block above follows.
//
// `active` and not `auth`, matching the read group `show` and `index` live
// in: a snapshot is exactly as visible as the assessment it was taken from,
// and a share-token or public-assessment reader who can already open `show`
// can already read this same decoded graph. Gating the *rendering* of it
// higher than the data would be a lock on the door of an open room.
//
// Two segments, so it does not collide with `{snapshot}` above it.
// ---------------------------------------------------------------------
Route::middleware('active')
    ->prefix('assessments/{assessment:uuid}/snapshots')
    ->name('assessments.snapshots.')
    ->group(function () {
        Route::get('{snapshot}/view', [SnapshotController::class, 'view'])->name('view');
    });

// ---------------------------------------------------------------------
// W65 — the scale calibration screen. Appended as its own block, per the
// append-only rule every block above follows.
//
// `manage`, and for once the spec says so outright rather than leaving it
// to be argued: calculation-engine.md §8.4 pitfall 6 — "editing a scale
// changes every number computed from it. Bump calc_version and require the
// manager role." Enforced in the controller through AssessmentPolicy like
// every other route touching an assessment, never by the route alone.
//
// `{kind}` is checked against ScaleCalibrator::kinds() inside the
// controller before it is used for anything, so a nonsense kind 404s
// rather than reaching a column name.
//
// `throttle:authenticated` — the default for an authenticated mutation
// (security.md §9). Recalibration is rare and administrative.
// ---------------------------------------------------------------------
Route::middleware(['auth', 'active', 'throttle:authenticated'])
    ->prefix('assessments/{assessment:uuid}/scales')
    ->name('assessments.scales.')
    ->group(function () {
        // W87, D46: the GET that rendered `Scales/Calibrate` is **gone**, not
        // merely unlinked. The three cards now live on Assessment settings,
        // and a screen that still answers its URL after its entry points are
        // removed is how a second, divergent editor survives a consolidation.
        Route::put('{kind}', [ScaleCalibrationController::class, 'update'])->name('update');
        // Reset one scale to the instance default (item 10). A write, so not a
        // GET; `{kind}/reset` rather than a flag on the PUT, so a reset cannot
        // be reached by a malformed save.
        Route::put('{kind}/reset', [ScaleCalibrationController::class, 'reset'])->name('reset');
    });
