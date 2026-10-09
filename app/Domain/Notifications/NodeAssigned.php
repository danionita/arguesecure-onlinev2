<?php

namespace App\Domain\Notifications;

use App\Models\Node;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "A node was assigned to you" (W47, item 15; the second of the feed's two
 * sources).
 *
 * Database channel only, for the reason in MentionedInComment.
 *
 * Being assigned still grants nothing (W46, security.md §3.1b). This
 * notification tells someone a node is now theirs to look at; whether they can
 * open it is decided by their collaborator role, and the assign route already
 * refuses to name anyone who has no access at all.
 */
class NodeAssigned extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Node $node,
        private readonly ?User $assignedBy,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $assessment = $this->node->assessment;

        return [
            'kind' => 'assignment',
            'assessment_uuid' => $assessment->uuid,
            'assessment_title' => $assessment->title ?? 'Untitled assessment',
            'node_uuid' => $this->node->uuid,
            'node_title' => (string) $this->node->title,
            'node_type' => $this->node->type,
            'assigned_by' => $this->assignedBy?->name,
        ];
    }
}
