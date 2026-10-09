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
 * ui.md §6 / PLAN.md Phase 6 item 4. Only identity is needed to remove a
 * node client-side (assessmentStore.ts's deleteNode() also only needs the
 * uuid to prune `nodes` and both edge arrays) — unlike NodeCreated/
 * NodeUpdated there is no row left to serialize once this fires.
 */
class NodeDeleted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsToAssessmentChannel;
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $assessmentUuid;

    public string $nodeUuid;

    public ?int $actorId;

    public ?string $actorName;

    public function __construct(Node $node, ?int $actorId, ?string $actorName)
    {
        // See NodeCreated: resolved once per request rather than once per
        // broadcast (W68). Also the one event that can fire while the
        // assessment itself is being deleted, which is why the resolver
        // answers a missing assessment with '' instead of fatalling.
        $this->assessmentUuid = app(AssessmentUuids::class)->forNode($node);
        $this->nodeUuid = $node->uuid;
        $this->actorId = $actorId;
        $this->actorName = $actorName;
    }

    public function broadcastAs(): string
    {
        return 'node.deleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'node_uuid' => $this->nodeUuid,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
        ];
    }
}
