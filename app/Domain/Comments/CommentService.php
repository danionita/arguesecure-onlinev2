<?php

namespace App\Domain\Comments;

use App\Domain\Comments\Exceptions\ThreadTooDeepException;
use App\Domain\Notifications\Notifier;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Comments (PLAN.md Phase 8 item 1, schema.md §3.8): threaded exactly two
 * levels deep — a top-level comment plus replies, no deeper. The schema
 * note is explicit that this is enforced in application code, not the
 * database: "`parent_comment_id` must reference a comment whose own
 * `parent_comment_id IS NULL`. Enforce in `CommentService`." This class is
 * that enforcement point, and the only place a Comment row is created,
 * updated or resolved from user input.
 */
class CommentService
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * Creates a top-level comment when $parent is null, or a reply when
     * it is given. Throws when $parent is itself a reply — the two-level
     * ceiling — so a client cannot build arbitrary nesting by chaining
     * "reply to a reply" requests, only by tampering with the exposed
     * parent id, which this method is the sole gate for either way.
     *
     * @throws ThreadTooDeepException
     */
    public function create(Assessment $assessment, Node $node, ?User $author, string $body, ?Comment $parent = null): Comment
    {
        if ($parent !== null) {
            if ($parent->node_id !== $node->id || $parent->assessment_id !== $assessment->id) {
                throw new \InvalidArgumentException('A reply must target a comment on the same node.');
            }

            if ($parent->parent_comment_id !== null) {
                throw new ThreadTooDeepException('Replies may not themselves be replied to — threading is exactly two levels deep.');
            }
        }

        // Direct property assignment, not Comment::create(): assessment_id,
        // node_id, parent_comment_id and user_id are identity columns
        // deliberately absent from Comment::$fillable (its own docblock:
        // "set by the controller/service from the authenticated route
        // context, never from request input directly"), so mass
        // assignment via create() would silently drop them and trip the
        // NOT NULL constraint on assessment_id. This mirrors
        // NodeController::store()'s identical pattern for Node's own
        // guarded identity columns.
        $comment = new Comment;
        $comment->assessment_id = $assessment->id;
        $comment->node_id = $node->id;
        $comment->parent_comment_id = $parent?->id;
        $comment->user_id = $author?->id;
        $comment->body = $body;
        $comment->save();

        $this->notifier->mentionsIn($assessment, $comment, $author);

        return $comment;
    }

    /**
     * Re-parses mentions (W47).
     *
     * An edit can name someone who was not named before, and they should hear
     * about it — but everyone already named must not be told twice, which is
     * `Notifier`'s job and is why this can call it unconditionally.
     *
     * The author of record, not the editor, is used for the never-notify-
     * yourself check. Editing is limited to the author or a manager, so the
     * only case they differ is a manager tidying someone else's comment, and
     * treating the comment as still coming from its author is the reading that
     * matches what the notification says.
     */
    public function updateBody(Comment $comment, string $body): Comment
    {
        $comment->body = $body;
        $comment->save();

        $assessment = $comment->assessment;

        if ($assessment !== null) {
            $this->notifier->mentionsIn($assessment, $comment, $comment->user);
        }

        return $comment;
    }

    public function resolve(Comment $comment, ?User $resolvedBy): Comment
    {
        $comment->resolved_at = now();
        $comment->resolved_by = $resolvedBy?->id;
        $comment->save();

        return $comment;
    }

    public function unresolve(Comment $comment): Comment
    {
        $comment->resolved_at = null;
        $comment->resolved_by = null;
        $comment->save();

        return $comment;
    }

    /**
     * Top-level comments for a node, with their replies and resolver
     * eager-loaded so the modal's Comments tab renders in one query per
     * relation rather than N+1 per thread (CLAUDE.md "eager-load
     * everything"). Both the author and resolver are loaded through the
     * allowlisted `id, name` column selection here, not left to the
     * controller — see CommentController::commentToArray() for the
     * matching serialization allowlist.
     *
     * @return Collection<int, Comment>
     */
    public function threadsForNode(Node $node): Collection
    {
        // Deliberately not $node->comments(): Node::comments() is
        // `hasMany(Comment::class)` with no explicit foreign key, and
        // Eloquent's default guess is derived from the *runtime* class —
        // Str::snake(class_basename($this)).'_id'. Every concrete node is
        // hydrated as an STI subclass (Risk/Attack/Defence/RootNode, see
        // Node::newFromBuilder()), so calling ->comments() on any real
        // node builds `where risk_id = ...` / `where attack_id = ...`
        // instead of `where node_id = ...`, and 42S22s against a column
        // that does not exist. Confirmed via
        // (new Risk)->comments()->getForeignKeyName() === 'risk_id'.
        // app/Models/Node.php is W6's, not this package's, so this is
        // worked around here with an explicit query rather than edited —
        // see the W18 report for the bug writeup. The same defect affects
        // Node::revisions(), worked around the same way in the W18
        // routes/web.php history block.
        return Comment::query()
            ->where('node_id', $node->id)
            ->whereNull('parent_comment_id')
            ->with([
                'user:id,name',
                'replies.user:id,name',
                'resolvedBy:id,name',
            ])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Unresolved top-level-comment counts per node, for the whole
     * assessment in one query — the node card's "has comments (with
     * unresolved count)" indicator (ui.md §3.2) needs this for every node
     * rendered, not one at a time. Only top-level comments count: a
     * resolved thread with an unresolved reply is a contradiction the UI
     * has no way to represent (resolving is a thread-level action), so
     * schema.md's model of "resolved lives on the comment being replied
     * to" is respected by counting only rows with no parent.
     *
     * @return array<string, int> keyed by node uuid
     */
    public function unresolvedCountsByNode(Assessment $assessment): array
    {
        return Comment::query()
            ->where('comments.assessment_id', $assessment->id)
            ->whereNull('comments.parent_comment_id')
            ->whereNull('comments.resolved_at')
            ->join('nodes', 'nodes.id', '=', 'comments.node_id')
            ->selectRaw('nodes.uuid as node_uuid, count(*) as unresolved_count')
            ->groupBy('nodes.uuid')
            ->pluck('unresolved_count', 'node_uuid')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}
