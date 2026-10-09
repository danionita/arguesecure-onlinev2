<?php

namespace App\Domain\Notifications;

use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "You were mentioned in a comment" (W47, item 15).
 *
 * Database channel only. **No email** — that needs a mail configuration, a
 * digest policy and an unsubscribe path, none of which exist here, and a
 * half-built one is worse than none. Stated in PLAN-2.2.md's brief rather than
 * discovered, and repeated here because this is the class someone would add
 * `'mail'` to.
 *
 * The payload is denormalised on purpose. A notification is a record of what
 * was true when it was sent: the excerpt is the comment as it read at the
 * moment of the mention, not as it reads now. Rendering it live would mean an
 * edit could rewrite a message someone has already been sent — and the reader
 * would have no way to tell.
 */
class MentionedInComment extends Notification
{
    use Queueable;

    /** Long enough to recognise the comment, short enough for a panel row. */
    private const EXCERPT_LENGTH = 140;

    public function __construct(private readonly Comment $comment) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $node = $this->comment->node;
        $assessment = $this->comment->assessment;

        return [
            'kind' => 'mention',
            // Carried so a re-parse on edit can tell "already told them about
            // this comment" from "a new person was named". See Notifier.
            'comment_id' => $this->comment->id,
            'assessment_uuid' => $assessment?->uuid,
            'assessment_title' => $assessment === null
                ? 'Untitled assessment'
                : ($assessment->title ?? 'Untitled assessment'),
            'node_uuid' => $node?->uuid,
            'node_title' => (string) $node?->title,
            // Display name only. A notification list is read by one person
            // about other people's activity, which is exactly where an address
            // leaks.
            'author' => $this->comment->user?->name,
            'excerpt' => Str::limit($this->comment->body, self::EXCERPT_LENGTH),
        ];
    }
}
