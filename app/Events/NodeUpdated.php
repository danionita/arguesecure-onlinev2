<?php

namespace App\Events;

use App\Domain\Structure\AssessmentUuids;
use App\Events\Concerns\BroadcastsToAssessmentChannel;
use App\Models\Node;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ui.md §6 / PLAN.md Phase 6 item 4. Carries the node's full current row
 * (not merely the changed keys): the client keys its store by uuid
 * (assessmentStore.ts's own docblock — "the store holds nodes once"), so
 * `Object.assign(store.nodes[uuid], event.node)` is the entire patch, with
 * no reconciliation against the client's own optimistic write beyond a
 * plain object merge — the same shape NodeController::update()'s response
 * already produces, so the client can treat "my own PATCH response" and
 * "somebody else's NodeUpdated broadcast" identically.
 */
class NodeUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsToAssessmentChannel;
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $assessmentUuid;

    /** @var array<string, mixed> */
    public array $node;

    public ?int $actorId;

    public ?string $actorName;

    public function __construct(Node $node, ?int $actorId, ?string $actorName)
    {
        // See NodeCreated: resolved once per request rather than once per
        // broadcast (W68).
        $this->assessmentUuid = app(AssessmentUuids::class)->forNode($node);
        $this->node = NodeBroadcastPayload::toArray($node);
        $this->actorId = $actorId;
        $this->actorName = $actorName;

        // The author's own client already applied this from the HTTP
        // response. Echoing it back a second later wrote the row *as saved*
        // over whatever they had typed since — and for a create, replaced the
        // record the open modal was bound to with one that had no `tags`,
        // which blanked the Details tab. Only the
        // originating socket is excluded (X-Socket-ID, set by useRealtime.ts),
        // so the same user's other tabs still receive it. Outside a request —
        // a queued job, a console command — there is no socket id and the
        // event goes to everyone, which is correct: nobody applied it yet.
        $this->dontBroadcastToCurrentUser();
    }

    public function broadcastAs(): string
    {
        return 'node.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'node' => $this->node,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
        ];
    }
}
