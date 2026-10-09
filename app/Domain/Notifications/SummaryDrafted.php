<?php

namespace App\Domain\Notifications;

use App\Models\Assessment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "Your executive summary draft is ready" — or "it failed" (W54).
 *
 * Database channel only, like the feed's other two sources.
 *
 * ## Both outcomes are notified, and that is the point of the class
 *
 * Job C runs for minutes. A user who asked for a draft and is told nothing has
 * no way to distinguish "still working" from "the runtime died twenty minutes
 * ago" except by reloading the report and guessing. A failure notification
 * costs one row and closes that gap, so `succeeded` is a field rather than
 * this class only ever meaning good news.
 *
 * ## It carries no draft text
 *
 * The draft lives in `assessments.summary_draft`, and the notification points
 * at the report. Copying prose into a notification payload would put a second
 * copy of model-written text somewhere with no generated mark on it, no
 * accept step in front of it, and no way for clearing the draft to remove it.
 */
class SummaryDrafted extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Assessment $assessment,
        private readonly bool $succeeded,
        private readonly ?string $reason = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'summary',
            'assessment_uuid' => $this->assessment->uuid,
            'assessment_title' => $this->assessment->title ?? 'Untitled assessment',
            // The feed's other kinds link to a node. This one has none: the
            // draft is about the whole assessment and belongs on the report.
            'node_uuid' => null,
            'node_title' => '',
            'succeeded' => $this->succeeded,
            // Shown to the person who asked, so it says what they can do, not
            // what the stack trace said.
            'reason' => $this->reason,
        ];
    }
}
