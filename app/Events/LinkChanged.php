<?php

namespace App\Events;

use App\Domain\Structure\AssessmentUuids;
use App\Events\Concerns\BroadcastsToAssessmentChannel;
use App\Models\AttackDefence;
use App\Models\RiskAttack;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ui.md §6 / PLAN.md Phase 6 item 4 — "link changed". Covers both edge
 * tables (schema.md §1: risk_attack, attack_defence) with one event shape
 * rather than two, since the client-side patch is the same operation on a
 * different array (assessmentStore.ts's riskAttackLinks / attackDefenceLinks)
 * either way. A drag-reparent (routes/web.php's `move` action) unlinks then
 * relinks, so it surfaces as one `deleted` LinkChanged followed by one
 * `created` one — this is the "moved" case from PLAN.md Phase 6 item 4;
 * there is no separate node-position broadcast for it because the edge is
 * what actually changed, not the node.
 */
class LinkChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsToAssessmentChannel;
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $assessmentUuid;

    public string $edge;

    public string $action;

    /** @var array<string, mixed> */
    public array $link;

    public ?int $actorId;

    public ?string $actorName;

    public static function forRiskAttack(RiskAttack $row, string $action, ?int $actorId, ?string $actorName): self
    {
        // `$row->risk` is set as a relation by whoever built the edge — see
        // LinkService — so both this and the two uuids below read memory. The
        // relation is still lazy-loadable, so a caller that does not set it is
        // correct and merely slower, which is the property that keeps this
        // from being a trap (W68).
        $assessmentUuid = app(AssessmentUuids::class)->forNode($row->risk);

        return new self(
            $assessmentUuid,
            'risk_attack',
            $action,
            [
                'risk_uuid' => (string) $row->risk?->uuid,
                'attack_uuid' => (string) $row->attack?->uuid,
                'position' => (int) $row->position,
            ],
            $actorId,
            $actorName,
        );
    }

    public static function forAttackDefence(AttackDefence $row, string $action, ?int $actorId, ?string $actorName): self
    {
        // See forRiskAttack(): resolved through the attack side, which shares
        // an assessment with the defence by LinkService's own invariant.
        $assessmentUuid = app(AssessmentUuids::class)->forNode($row->attack);

        return new self(
            $assessmentUuid,
            'attack_defence',
            $action,
            [
                'attack_uuid' => (string) $row->attack?->uuid,
                'defence_uuid' => (string) $row->defence?->uuid,
                'position' => (int) $row->position,
                'strength_mode' => $row->strength_mode,
                'strength_fraction' => $row->strength_fraction,
                'strength_level_id' => $row->strength_level_id,
                'reduction_target' => $row->reduction_target,
            ],
            $actorId,
            $actorName,
        );
    }

    /** @param  array<string, mixed>  $link */
    private function __construct(
        string $assessmentUuid,
        string $edge,
        string $action,
        array $link,
        ?int $actorId,
        ?string $actorName,
    ) {
        $this->assessmentUuid = $assessmentUuid;
        $this->edge = $edge;
        $this->action = $action;
        $this->link = $link;
        $this->actorId = $actorId;
        $this->actorName = $actorName;
    }

    public function broadcastAs(): string
    {
        return 'link.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'edge' => $this->edge,
            'action' => $this->action,
            'link' => $this->link,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
        ];
    }
}
