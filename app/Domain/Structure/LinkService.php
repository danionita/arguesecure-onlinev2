<?php

namespace App\Domain\Structure;

use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\RiskAttack;
use Illuminate\Support\Facades\DB;

/**
 * Creates and removes the DAG edges (schema.md §3.5, §3.6). W5 deliberately
 * left endpoint-type and same-assessment validation to this service — it is
 * not database-enforceable on a self-referencing pivot.
 *
 * Wrong-endpoint-type is rejected by PHP's own type system: each method only
 * accepts the STI subclass an edge actually connects (Risk<->Attack,
 * Attack<->Defence), so passing e.g. a Defence where an Attack is expected
 * is a TypeError, not a runtime branch to get wrong.
 *
 * The graph is cycle-free by construction: risks only link to attacks and
 * attacks only link to defences, a strict three-level structure with no
 * edge type that could ever point back toward the root, so no explicit
 * cycle check is needed here.
 */
class LinkService
{
    public function linkRiskToAttack(Risk $risk, Attack $attack, ?int $position = null): RiskAttack
    {
        $this->assertSameAssessment($risk, $attack);

        return DB::transaction(function () use ($risk, $attack, $position): RiskAttack {
            // Direct property assignment, not ::create(): RiskAttack's
            // $fillable deliberately excludes its own foreign keys (they are
            // identity columns, never request input — security.md §7), so a
            // mass-assigned create() here would silently drop
            // risk_node_id/attack_node_id and fail the NOT NULL constraint.
            $link = new RiskAttack;
            $link->risk_node_id = $risk->id;
            $link->attack_node_id = $attack->id;
            $link->position = $position ?? $this->nextPosition('risk_attack', 'risk_node_id', $risk->id);
            $this->carryEndpoints($link, ['risk' => $risk, 'attack' => $attack]);
            $link->save();

            return $link;
        });
    }

    /**
     * Deletes through the model, one row at a time, rather than with a single
     * query-builder delete(). A builder delete issues raw SQL and fires no
     * model events, so RiskAttackObserver would never see the removal: the
     * LinkChanged broadcast would be silently skipped and — the part that
     * actually corrupts output — assessments.calc_version would never be
     * bumped, leaving the cached result in place. Unlinking an attack would
     * appear to change nothing at all.
     *
     * An unlink removes one row (the pair is unique), so the loop is not an
     * N+1 in any meaningful sense.
     */
    public function unlinkRiskFromAttack(Risk $risk, Attack $attack): void
    {
        RiskAttack::query()
            ->where('risk_node_id', $risk->id)
            ->where('attack_node_id', $attack->id)
            ->get()
            ->each(function (RiskAttack $link) use ($risk, $attack): void {
                $this->carryEndpoints($link, ['risk' => $risk, 'attack' => $attack]);
                $link->delete();
            });
    }

    public function linkAttackToDefence(Attack $attack, Defence $defence, ?int $position = null, ?string $reductionTarget = null): AttackDefence
    {
        $this->assertSameAssessment($attack, $defence);

        return DB::transaction(function () use ($attack, $defence, $position, $reductionTarget): AttackDefence {
            // schema.md §3.6: transfers default reduction_target to `impact`;
            // ordinary defences default to null (combined loss). Both are
            // stored explicitly and user-overridable via $reductionTarget.
            $reductionTarget ??= $defence->is_transfer ? 'impact' : null;

            // Direct property assignment, not ::create() — see the same note
            // in linkRiskToAttack(): AttackDefence's own foreign keys are
            // deliberately not in $fillable.
            $link = new AttackDefence;
            $link->attack_node_id = $attack->id;
            $link->defence_node_id = $defence->id;
            $link->position = $position ?? $this->nextPosition('attack_defence', 'attack_node_id', $attack->id);
            $link->reduction_target = $reductionTarget;
            $this->carryEndpoints($link, ['attack' => $attack, 'defence' => $defence]);
            $link->save();

            return $link;
        });
    }

    /** Deletes through the model for the same reason as unlinkRiskFromAttack(). */
    public function unlinkAttackFromDefence(Attack $attack, Defence $defence): void
    {
        AttackDefence::query()
            ->where('attack_node_id', $attack->id)
            ->where('defence_node_id', $defence->id)
            ->get()
            ->each(function (AttackDefence $link) use ($attack, $defence): void {
                $this->carryEndpoints($link, ['attack' => $attack, 'defence' => $defence]);
                $link->delete();
            });
    }

    /**
     * Hands an edge the two nodes it connects, before the save or delete that
     * fires its observer (W68).
     *
     * The observers and LinkChanged both need the endpoints — for the channel
     * name, for the payload's uuids, and for the assessment the recalculation
     * is debounced against — and every one of those reads used to lazy-load a
     * node this method's caller was already holding. Three `SELECT * FROM
     * nodes` per edge, on every path that links anything, which is why a
     * catalogue bundle's cost scaled with its size.
     *
     * This is a fast path and not a correctness requirement: an edge saved
     * without it still resolves the same nodes, just by querying for them. Any
     * caller that does not have both endpoints in hand can leave it alone.
     *
     * @param  array<string, Node>  $endpoints  relation name => node
     */
    private function carryEndpoints(RiskAttack|AttackDefence $link, array $endpoints): void
    {
        foreach ($endpoints as $relation => $node) {
            $link->setRelation($relation, $node);
        }
    }

    private function assertSameAssessment(Node $a, Node $b): void
    {
        if ($a->assessment_id !== $b->assessment_id) {
            throw new LinkValidationException(
                "Cannot link node {$a->uuid} to {$b->uuid}: they belong to different assessments."
            );
        }
    }

    private function nextPosition(string $table, string $column, int $id): int
    {
        $max = DB::table($table)->where($column, $id)->max('position');

        return $max === null ? 0 : (int) $max + 1;
    }
}
