<?php

namespace App\Domain\Notifications;

use App\Domain\Comments\MentionParser;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Node;
use App\Models\User;

/**
 * The one place a notification is sent (W47, item 15).
 *
 * Both sources go through here so the rules that apply to both — never notify
 * yourself, never notify twice for the same thing — are written once instead
 * of once per call site.
 */
class Notifier
{
    public function __construct(private readonly MentionParser $parser) {}

    /**
     * Notifies everyone newly named in a comment.
     *
     * Called on create **and** on edit, which is what makes the de-duplication
     * necessary rather than defensive. Editing a comment re-parses the whole
     * body, so everyone already named in it would be told a second time about
     * a message they read yesterday — and the author, fixing a typo, would
     * have no idea they had just pinged the room again. So a mention is
     * skipped when this person already has an unread-or-read notification for
     * this comment id; someone *newly* named by the edit still gets one, which
     * is the case the naive fix (notify only on create) gets wrong.
     */
    public function mentionsIn(Assessment $assessment, Comment $comment, ?User $author): void
    {
        $mentioned = $this->parser->parse($assessment, $comment->body);

        foreach ($mentioned as $user) {
            // Naming yourself is a note to self, not a notification.
            if ($author !== null && $user->id === $author->id) {
                continue;
            }

            if ($this->alreadyToldAbout($user, MentionedInComment::class, 'comment_id', $comment->id)) {
                continue;
            }

            $user->notify(new MentionedInComment($comment));
        }
    }

    /**
     * Notifies someone a node is now theirs.
     *
     * Assigning yourself notifies nobody: you already know. Re-assigning
     * someone who is already on the node cannot reach here — the route treats
     * that as a no-op — but the de-duplication is applied anyway, because a
     * node someone was unassigned from and assigned to again a minute later is
     * a real sequence and does not deserve a second identical row.
     */
    public function nodeAssigned(Node $node, User $assignee, ?User $assignedBy): void
    {
        if ($assignedBy !== null && $assignee->id === $assignedBy->id) {
            return;
        }

        if ($this->alreadyToldAbout($assignee, NodeAssigned::class, 'node_uuid', $node->uuid)) {
            return;
        }

        $assignee->notify(new NodeAssigned($node, $assignedBy));
    }

    /**
     * Whether this user already holds a notification of $type about $value.
     *
     * `data` is the framework's `text` column, not a `json` one, and this
     * package does not change the framework's migration shape — so the match
     * is a LIKE against the encoded `"key":value` pair rather than a JSON path
     * expression, which MariaDB could not index on a text column anyway. The
     * scan is bounded to one user's notifications of one type, which is small
     * by construction.
     *
     * Both halves go through `json_encode` rather than string concatenation,
     * so the fragment is built the same way the stored value was — a name or
     * uuid needing escaping produces the same bytes on both sides.
     */
    private function alreadyToldAbout(User $user, string $type, string $key, int|string $value): bool
    {
        $fragment = json_encode($key).':'.json_encode($value);

        return $user->notifications()
            ->where('type', $type)
            ->where('data', 'like', '%'.$fragment.'%')
            ->exists();
    }
}
