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
 * PLAN.md Phase 6 item 4 / ui.md §6: carries the full new node, so a
 * collaborator's client patches its store directly — the legacy design
 * broadcast only an id and re-fetched a rendered HTML fragment per change,
 * which CLAUDE.md and PLAN.md both call out by name as the thing not to
 * repeat.
 *
 * Dispatched from App\Observers\NodeObserver rather than from
 * NodeController (outside this package's ownership): the observer fires on
 * every concrete node subclass's `created` event regardless of which
 * service created it, so this also covers node creation from services this
 * package never has to know about (e.g. a future catalogue insert).
 *
 * Implements ShouldDispatchAfterCommit because NodeController::store()
 * creates a node and its first link inside one DB transaction — broadcasting
 * before that transaction commits could tell a collaborator's client about
 * a node that a subsequent failure then rolls back.
 */
class NodeCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        // Through the resolver, not `$node->assessment->uuid`: that was one
        // lazy-loaded SELECT per broadcast, and a bulk path fires one of these
        // per row (W68).
        $this->assessmentUuid = app(AssessmentUuids::class)->forNode($node);
        $this->node = NodeBroadcastPayload::toArray($node);
        $this->actorId = $actorId;
        $this->actorName = $actorName;

        // The author's own client already applied this from the HTTP
        // response. Echoing it back a second later wrote the row *as saved*
        // over whatever they had typed since — and for a create, replaced the
        // record the open modal was bound to with one that had no `tags`,
        // which blanked the Details tab (RELEASE-2.5.0.md item 22b). Only the
        // originating socket is excluded (X-Socket-ID, set by useRealtime.ts),
        // so the same user's other tabs still receive it. Outside a request —
        // a queued job, a console command — there is no socket id and the
        // event goes to everyone, which is correct: nobody applied it yet.
        $this->dontBroadcastToCurrentUser();
    }

    public function broadcastAs(): string
    {
        return 'node.created';
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
