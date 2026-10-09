<?php

namespace App\Http\Controllers;

use App\Domain\Comments\CommentService;
use App\Domain\Comments\Exceptions\ThreadTooDeepException;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Node;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Comments (PLAN.md Phase 8 item 1, schema.md §3.8, ui.md §7). Every action
 * runs through AssessmentPolicy/CommentPolicy — no exceptions (CLAUDE.md):
 * reading a node's comments needs `view`; posting, resolving or unresolving
 * need `comment` (the whole reason the `commenter` role exists — it can
 * comment but never `update` the assessment); editing or deleting a single
 * comment is additionally restricted to that comment's own author or an
 * assessment manager/owner, which is exactly what CommentPolicy::update()
 * already encodes — this controller defers to it rather than duplicating
 * the "own comment OR manage" check inline.
 *
 * No FormRequest class here, matching SnapshotController's own documented
 * reason: this package owns app/Http/Controllers/CommentController.php but
 * not app/Http/Requests/**, so authorization and validation happen inline.
 *
 * `{node:uuid}` and `{comment}` both nest under `assessments/{assessment:uuid}`
 * and bind through Assessment's own `nodes()`/`comments()` relations
 * (Laravel's implicit route-model-binding scoping guesses those relation
 * names from the route segments `node`/`comment`), so a comment or node
 * belonging to a different assessment 404s before any method here runs —
 * the same IDOR pattern NodeControllerTest documents for the W12 routes.
 */
class CommentController extends Controller
{
    public function index(Request $request, Assessment $assessment, Node $node, CommentService $comments): JsonResponse
    {
        $this->authorizeView($request, $assessment);

        $threads = $comments->threadsForNode($node);
        $canManage = (bool) Gate::forUser($request->user())->allows('manage', $assessment);

        return response()->json([
            'comments' => $threads->map(fn (Comment $c) => $this->commentToArray($c, $request, $canManage))->values(),
        ]);
    }

    public function unresolvedCounts(Request $request, Assessment $assessment, CommentService $comments): JsonResponse
    {
        $this->authorizeView($request, $assessment);

        return response()->json(['counts' => $comments->unresolvedCountsByNode($assessment)]);
    }

    public function store(Request $request, Assessment $assessment, Node $node, CommentService $comments): JsonResponse
    {
        $this->authorizeComment($request, $assessment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_comment_id' => ['nullable', 'integer'],
        ]);

        $parent = null;
        if (! empty($validated['parent_comment_id'])) {
            $parent = Comment::query()
                ->where('assessment_id', $assessment->id)
                ->where('node_id', $node->id)
                ->find($validated['parent_comment_id']);

            if ($parent === null) {
                return response()->json(['message' => 'The comment you are replying to no longer exists.'], 422);
            }
        }

        try {
            $comment = $comments->create($assessment, $node, $request->user(), $validated['body'], $parent);
        } catch (ThreadTooDeepException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $comment->load(['user:id,name']);
        $canManage = (bool) Gate::forUser($request->user())->allows('manage', $assessment);

        return response()->json(['comment' => $this->commentToArray($comment, $request, $canManage)], 201);
    }

    public function update(Request $request, Assessment $assessment, Comment $comment, CommentService $comments): JsonResponse
    {
        $this->assertBelongsTo($assessment, $comment);
        $this->authorizeCommentOwnership($request, $comment, 'update');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = $comments->updateBody($comment, $validated['body']);
        $comment->load(['user:id,name', 'resolvedBy:id,name']);
        $canManage = (bool) Gate::forUser($request->user())->allows('manage', $assessment);

        return response()->json(['comment' => $this->commentToArray($comment, $request, $canManage)]);
    }

    public function destroy(Request $request, Assessment $assessment, Comment $comment): JsonResponse
    {
        $this->assertBelongsTo($assessment, $comment);
        $this->authorizeCommentOwnership($request, $comment, 'delete');

        $comment->delete();

        return response()->json(status: 204);
    }

    public function resolve(Request $request, Assessment $assessment, Comment $comment, CommentService $comments): JsonResponse
    {
        $this->assertBelongsTo($assessment, $comment);
        $this->authorizeComment($request, $assessment);

        // Only a top-level comment can be resolved — resolving is a
        // thread-level action (schema.md §3.8's threading note), and a
        // reply has nowhere of its own for "resolved" to mean anything.
        if ($comment->parent_comment_id !== null) {
            return response()->json(['message' => 'Only the top-level comment of a thread can be resolved.'], 422);
        }

        $comment = $comments->resolve($comment, $request->user());
        $comment->load(['user:id,name', 'resolvedBy:id,name']);
        $canManage = (bool) Gate::forUser($request->user())->allows('manage', $assessment);

        return response()->json(['comment' => $this->commentToArray($comment, $request, $canManage)]);
    }

    public function unresolve(Request $request, Assessment $assessment, Comment $comment, CommentService $comments): JsonResponse
    {
        $this->assertBelongsTo($assessment, $comment);
        $this->authorizeComment($request, $assessment);

        if ($comment->parent_comment_id !== null) {
            return response()->json(['message' => 'Only the top-level comment of a thread can be unresolved.'], 422);
        }

        $comment = $comments->unresolve($comment);
        $comment->load(['user:id,name', 'resolvedBy:id,name']);
        $canManage = (bool) Gate::forUser($request->user())->allows('manage', $assessment);

        return response()->json(['comment' => $this->commentToArray($comment, $request, $canManage)]);
    }

    /**
     * Never serializes `user`/`resolved_by`'s email or any other User
     * column beyond id/name — an explicit allowlist, not `->toArray()`
     * (CLAUDE.md: "Comment authors must expose id and name only — never
     * email, never the password hash... a real bug I fixed in W17").
     * `body` is returned as plain text: the frontend renders it through
     * Vue's default `{{ }}` interpolation, which HTML-escapes
     * automatically, never `v-html` (see CommentThread.vue). See the W18
     * report for why this does not attempt full markdown rendering.
     *
     * @return array<string, mixed>
     */
    private function commentToArray(Comment $comment, Request $request, bool $canManage): array
    {
        $userId = $request->user()?->id;
        $canEdit = $userId !== null && ($userId === $comment->user_id || $canManage);

        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'author' => $comment->user ? ['id' => $comment->user->id, 'name' => $comment->user->name] : null,
            'created_at' => $this->isoDate($comment->getAttribute('created_at')),
            'updated_at' => $this->isoDate($comment->getAttribute('updated_at')),
            'resolved_at' => $this->isoDate($comment->getAttribute('resolved_at')),
            'resolved_by' => $comment->resolvedBy ? ['id' => $comment->resolvedBy->id, 'name' => $comment->resolvedBy->name] : null,
            'can_edit' => $canEdit,
            'can_delete' => $canEdit,
            'replies' => $comment->relationLoaded('replies')
                ? $comment->replies->map(fn (Comment $r) => $this->commentToArray($r, $request, $canManage))->values()
                : [],
        ];
    }

    /**
     * `created_at`/`updated_at`/`resolved_at` are cast to `datetime` on the
     * Comment model (Comment::casts()), but that Laravel-11-style
     * method-based cast declaration is not picked up by static analysis
     * here — the same larastan gap SnapshotController::isoDate() and
     * UndoController::toJson() both already document for their own models
     * with an identical shape — so this sidesteps it the same way, without
     * touching app/Models/**.
     */
    private function isoDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? Carbon::instance($value)->toIso8601String()
            : Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * `{comment}` binds by plain id, not `{comment:uuid}` — Comment carries
     * no uuid column (schema.md §3.8) — so Laravel's implicit
     * route-model-binding scoping does not automatically apply here: it
     * only auto-scopes a nested parameter when the route declares an
     * explicit binding field (`{param:field}`), which is what makes
     * `{node:uuid}` above scope to its assessment for free but leaves a
     * bare `{comment}` unscoped. This re-asserts the cross-assessment
     * membership explicitly instead, the same IDOR pattern
     * SnapshotController::assertBelongsTo() already uses for its own
     * plain-id-bound `{snapshot}`.
     */
    private function assertBelongsTo(Assessment $assessment, Comment $comment): void
    {
        abort_unless($comment->assessment_id === $assessment->id, 404);
    }

    private function authorizeView(Request $request, Assessment $assessment): void
    {
        if (Gate::forUser($request->user())->denies('view', $assessment)) {
            abort(403);
        }
    }

    /**
     * `comment` is the ability the `commenter` role exists for (schema.md
     * §3.7): it grants posting, resolving and unresolving without granting
     * `update` — a commenter can never edit the assessment's structure or
     * values, only discuss it.
     */
    private function authorizeComment(Request $request, Assessment $assessment): void
    {
        if (Gate::forUser($request->user())->denies('comment', $assessment)) {
            abort(403);
        }
    }

    private function authorizeCommentOwnership(Request $request, Comment $comment, string $ability): void
    {
        if (Gate::forUser($request->user())->denies($ability, $comment)) {
            abort(403);
        }
    }
}
