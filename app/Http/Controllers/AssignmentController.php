<?php

namespace App\Http\Controllers;

use App\Domain\History\RevisionRecorder;
use App\Domain\Notifications\Notifier;
use App\Domain\Sharing\CollaboratorService;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Assignees on a node (W46, item 16; PLAN-2.2.md D13).
 *
 * ## Authorization is deliberately small
 *
 * Assigning is `update` on the *assessment*. An assignee gains **nothing**
 * from being assigned — no node-scoped grant, no second policy path, no
 * ability that their collaborator role did not already give them. A viewer
 * who is assigned a node is still a viewer, and that is asserted directly in
 * tests/Feature/Assignments rather than left to be inferred from the absence
 * of code.
 *
 * ## An assignee must already be a collaborator (D13)
 *
 * Assigning someone who cannot open the assessment produces a task nobody can
 * do and a name the team reads as a commitment. So the picker offers
 * collaborators, and naming anyone else is refused with 422 — unless the
 * caller explicitly asks, in the same request, to share the assessment with
 * them at `viewer` first.
 *
 * That second path needs `manage`, not `update`. Adding a collaborator has
 * always been a `manage` action (ShareController), and routing it through the
 * word "assign" must not turn it into an `update` one — otherwise any editor
 * could hand read access to the whole assessment to anyone with an account,
 * by a route whose name says nothing about sharing. An editor who needs a
 * colleague on the assessment asks someone who can share; that is a smaller
 * cost than a silent disclosure path.
 *
 * The UI names the consequence before the click. A silent share is a data
 * disclosure the user did not ask for.
 */
class AssignmentController extends Controller
{
    public function index(Request $request, Assessment $assessment, Node $node): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('view', $assessment)) {
            abort(403);
        }

        $this->assertBelongsTo($assessment, $node);

        return response()->json($this->payload($assessment, $node, $request));
    }

    public function store(Request $request, Assessment $assessment, Node $node): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('update', $assessment)) {
            abort(403);
        }

        $this->assertBelongsTo($assessment, $node);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            // The confirmed second step, never a default. Absent means "only
            // if they are already a collaborator".
            'share_as_viewer' => ['sometimes', 'boolean'],
        ]);

        /** @var User $target */
        $target = User::query()->findOrFail($validated['user_id']);

        if (! $this->isCollaborator($assessment, $target)) {
            if (! ($validated['share_as_viewer'] ?? false)) {
                return response()->json([
                    'message' => "{$target->name} is not on this assessment yet. Share it with them first, or choose someone who already has access.",
                    'requires_share' => true,
                ], 422);
            }

            // The escalation check, and the reason this is not simply an
            // `update` route with an extra flag.
            if (Gate::forUser($request->user())->denies('manage', $assessment)) {
                return response()->json([
                    'message' => "{$target->name} is not on this assessment, and you cannot share it. Ask someone who can share to add them first.",
                    'requires_share' => true,
                ], 403);
            }

            if (! $target->is_active) {
                return response()->json([
                    'message' => 'That account is not active.',
                ], 422);
            }

            /** @var User $actor */
            $actor = $request->user();

            // `viewer`, the bottom of the ladder, and nothing more. The point
            // of the confirmed step is access to *read* the thing they are
            // responsible for; anything above that is a sharing decision the
            // user should make in the Share dialog, where the roles are
            // explained.
            CollaboratorService::add($assessment, $target, 'viewer', $actor);
        }

        // syncWithoutDetaching, not attach(): `node_assignees` has a
        // composite primary key, so assigning someone already assigned would
        // be a duplicate-key error rather than the no-op the caller means.
        $before = $node->assignees()->where('users.id', $target->id)->exists();

        $node->assignees()->syncWithoutDetaching([$target->id]);

        // W47. Only on a genuine change: `syncWithoutDetaching` is a no-op for
        // someone already assigned, and a no-op should not send a message.
        if (! $before) {
            app(Notifier::class)->nodeAssigned($node, $target, $request->user());

            // W56, closing the third of W48's history gaps. An assignee moves
            // no figure, but "Dana was made responsible for this" is exactly
            // what a reader goes to a timeline for, and the same no-op guard
            // that stops a duplicate notification stops a duplicate row.
            app(RevisionRecorder::class)
                ->recordFieldChange($node, 'assignee', null, $target->name, $request->user());
        }

        return response()->json($this->payload($assessment, $node->fresh(), $request), 201);
    }

    public function destroy(Request $request, Assessment $assessment, Node $node, User $user): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('update', $assessment)) {
            abort(403);
        }

        $this->assertBelongsTo($assessment, $node);

        // Unassigning never touches `assessment_user`. Someone shared in
        // through the confirmed step above keeps their access until it is
        // revoked in the Share dialog, deliberately: silently un-sharing is
        // as surprising as silently sharing, and the person may by then be
        // reading the assessment for reasons unrelated to this node.
        if ($node->assignees()->detach($user->id) > 0) {
            app(RevisionRecorder::class)
                ->recordFieldChange($node, 'assignee', $user->name, null, $request->user());
        }

        return response()->json($this->payload($assessment, $node->fresh(), $request));
    }

    /**
     * The people who may be assigned: the owner and every collaborator.
     *
     * `update`, not `manage` — an editor assigns work, and the names here are
     * already visible to them in presence avatars and comment authorship.
     * Searching *beyond* the collaborators is `share.users.search`, which
     * needs `manage`, and stays there.
     */
    public function candidates(Request $request, Assessment $assessment): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('update', $assessment)) {
            abort(403);
        }

        return response()->json(['users' => $this->collaborators($assessment)]);
    }

    /** @return array<string, mixed> */
    private function payload(Assessment $assessment, Node $node, Request $request): array
    {
        return [
            'assignees' => $node->assignees()->get()
                // Display name only, never the address. An assignee list is
                // shown to everyone who can read the assessment, which for a
                // published template is every account on the instance.
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all(),
            'canAssign' => Gate::forUser($request->user())->allows('update', $assessment),
            'canShare' => Gate::forUser($request->user())->allows('manage', $assessment),
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function collaborators(Assessment $assessment): array
    {
        $users = User::query()
            ->select(['id', 'name'])
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('id', $assessment->owner_user_id)
                ->orWhereHas('sharedAssessments', fn ($q) => $q->where('assessments.id', $assessment->id)))
            ->orderBy('name')
            ->get();

        return $users->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    private function isCollaborator(Assessment $assessment, User $user): bool
    {
        return $user->id === $assessment->owner_user_id
            || $assessment->members()->where('users.id', $user->id)->exists();
    }

    /**
     * A node from another assessment 404s rather than 403s: the caller has
     * already proved they may act on *this* assessment, so the only thing
     * left to say is that the node is not in it.
     */
    private function assertBelongsTo(Assessment $assessment, Node $node): void
    {
        abort_unless($node->assessment_id === $assessment->id, 404);
    }
}
