<?php

namespace App\Http\Controllers;

use App\Domain\History\Exceptions\RevisionNotRevertibleException;
use App\Domain\History\RevisionReverter;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\NodeRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Per-node revision history (PLAN.md Phase 8 item 2, schema.md §3.9,
 * ui.md §7). Originally shipped as two ~140-line route closures in
 * routes/web.php (the W18 "comments + history" block); moved here for
 * W18b per CLAUDE.md — "Business logic goes in app/Domain/, not in
 * controllers and never in views... Route closures are worse than either."
 * The actual revert business logic lives in
 * App\Domain\History\RevisionReverter; this controller is the thin HTTP
 * glue: authorization, request shape, response shape.
 *
 * `view` for listing (matching the comments read routes), `update` for
 * reverting a field — a revert is a real write to the node, gated exactly
 * like NodeController::update() gates any other one (CLAUDE.md: every
 * route touching an assessment goes through AssessmentPolicy).
 */
class NodeRevisionController extends Controller
{
    /**
     * Gate::forUser() rather than `$request->user()?->can(...)`: a
     * null/guest caller must still reach AssessmentPolicy::view()'s
     * is_public/share-token branches instead of short-circuiting to false
     * first — the same reasoning ReportController::authorizeView() and
     * ShowAssessmentRequest already document for this exact read-route
     * shape.
     */
    public function index(Request $request, Assessment $assessment, Node $node): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('view', $assessment)) {
            abort(403);
        }

        // Not $node->revisions(): Node::revisions() is
        // hasMany(NodeRevision::class) with no explicit foreign key, and
        // Eloquent's default guess comes from the *runtime* class — every
        // concrete node hydrates as an STI subclass (Risk/Attack/Defence/
        // RootNode), so the guessed key is 'risk_id'/'attack_id'/etc., not
        // 'node_id', and the query 42S22s. Confirmed the identical defect
        // for Node::comments() — see App\Domain\Comments\CommentService::
        // threadsForNode()'s docblock. app/Models/Node.php is W6's, not
        // this package's, so this queries NodeRevision directly instead of
        // editing the relation.
        $rows = NodeRevision::query()
            ->where('node_id', $node->id)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get();

        // A plain foreach rather than ->map(fn (NodeRevision $r): array =>
        // [...]): PHPStan cannot resolve the generic return type of
        // Collection::map() through an arrow-typed closure here (the same
        // class of larastan gap CommentController::isoDate()'s docblock
        // documents for a single property, just triggered differently by a
        // whole-row transform).
        $revisions = [];
        foreach ($rows as $r) {
            /** @var NodeRevision $r */
            $revisions[] = $this->present($r);
        }

        return response()->json(['revisions' => $revisions]);
    }

    public function revert(Request $request, Assessment $assessment, Node $node, NodeRevision $revision, RevisionReverter $reverter): JsonResponse
    {
        if (! $request->user()?->can('update', $assessment)) {
            abort(403);
        }

        // Cross-assessment/cross-node IDOR check: {revision} is bound by
        // plain numeric id (NodeRevision carries no uuid column, schema.md
        // §3.9) and is NOT scoped via Route::scopeBindings() — Node has no
        // singular revision() relation for that mechanism to guess — so
        // this re-asserts both memberships explicitly, the same pattern
        // SnapshotController::assertBelongsTo() and the W12/W16 node
        // routes already use.
        abort_unless(
            $revision->assessment_id === $assessment->id && $revision->node_id === $node->id,
            404,
        );

        $data = $request->validate([
            'field' => ['required', 'string'],
        ]);

        // ->id not ?->id: the ->can() check above already narrowed
        // $request->user() to non-null for the rest of this method.
        try {
            $newRevision = $reverter->revert($node, $revision, $data['field'], $request->user());
        } catch (RevisionNotRevertibleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'node' => [
                'uuid' => $node->uuid,
                $data['field'] => $node->fresh()->getAttribute($data['field']),
                'updated_at' => $node->fresh()->updated_at?->toJSON(),
            ],
            'revision' => $this->present($newRevision),
        ]);
    }

    /**
     * Explicit allowlist, never ->toArray() (CLAUDE.md "never
     * over-serialise"): a revision's `user` relation must expose id and
     * name only — never email, never a password hash.
     *
     * @return array<string, mixed>
     */
    private function present(NodeRevision $r): array
    {
        return [
            'id' => $r->id,
            'action' => $r->action,
            'changes' => $r->changes,
            'operation_id' => $r->operation_id,
            'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
            'created_at' => $r->getAttribute('created_at') instanceof \DateTimeInterface
                ? Carbon::instance($r->getAttribute('created_at'))->toIso8601String()
                : null,
        ];
    }
}
