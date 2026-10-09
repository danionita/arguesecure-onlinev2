<?php

namespace App\Domain\Graph;

use App\Models\Assessment;
use App\Models\AttackDefence;
use App\Models\Node;
use App\Models\RiskAttack;
use App\Models\ScaleLevel;
use Illuminate\Support\Collection;

/**
 * Four queries load an entire assessment, always (schema.md §1, §4): nodes,
 * risk_attack, attack_defence, scale_levels. The legacy tree render was an
 * N+1 by construction (CLAUDE.md); this loader is the fix, and its query
 * count is asserted by a test rather than merely hoped for.
 *
 * Assumes the given Assessment is already loaded (a fifth query, or zero if
 * the caller eager-loaded it via route model binding) — that row is not
 * counted here because a controller almost always already holds it before
 * reaching this loader.
 */
final class AssessmentGraphLoader
{
    public function load(Assessment $assessment): AssessmentGraph
    {
        // Query 1/4 — every node in the assessment, of every type. STI's
        // newFromBuilder() (Node::$typeMap) hydrates each row as the correct
        // subclass; hydration happens after the query is built, so a subclass's
        // 'type' global scope never constrains this.
        //
        // Deliberately NOT withoutGlobalScopes(): the 'type' scope is declared
        // on the subclasses (Risk, Attack, Defence, RootNode), never on Node
        // itself, so querying the base class has no type scope to strip. The
        // only global scope on Node is SoftDeletingScope — so removing "all"
        // scopes achieved nothing except readmitting soft-deleted nodes into
        // the graph the calculation engine consumes, silently counting deleted
        // risks and attacks in every total.
        $nodes = Node::query()
            ->where('assessment_id', $assessment->id)
            ->orderBy('position')
            ->get();

        $idToUuid = $nodes->pluck('uuid', 'id');

        $riskIds = $nodes->where('type', 'risk')->pluck('id')->values();
        $attackIds = $nodes->where('type', 'attack')->pluck('id')->values();

        // Query 2/4.
        $riskAttackRows = $riskIds->isEmpty()
            ? collect()
            : RiskAttack::query()
                ->whereIn('risk_node_id', $riskIds)
                ->orderBy('position')
                ->get();

        // Query 3/4.
        $attackDefenceRows = $attackIds->isEmpty()
            ? collect()
            : AttackDefence::query()
                ->whereIn('attack_node_id', $attackIds)
                ->orderBy('position')
                ->get();

        // Query 4/4 — every level of the assessment's three configured
        // scales, joined to `scales` in the same query so the DTO carries
        // unit/kind without a fifth round trip (needed by ScaleMapper's
        // unit-agreement assertion, calculation-engine.md §8.3). `is_system`
        // rides along on the same join so the DTO's `scales` collection
        // (calculation-engine.md §12, `uncalibrated_scales`) is built from
        // these same rows rather than a fifth query.
        $scaleIds = array_values(array_unique([
            $assessment->impact_scale_id,
            $assessment->likelihood_scale_id,
            $assessment->strength_scale_id,
        ]));

        $scaleLevelRows = ScaleLevel::query()
            ->join('scales', 'scales.id', '=', 'scale_levels.scale_id')
            ->whereIn('scale_levels.scale_id', $scaleIds)
            ->orderBy('scale_levels.ordinal')
            ->get(['scale_levels.*', 'scales.kind as scale_kind', 'scales.unit as scale_unit', 'scales.is_system as scale_is_system']);

        return new AssessmentGraph(
            assessmentUuid: $assessment->uuid,
            currency: $assessment->currency,
            amortizationYears: $assessment->amortization_years,
            rootTitle: $nodes->firstWhere('type', 'root')?->title,
            rootDescription: $nodes->firstWhere('type', 'root')?->description,
            risks: $this->mapRisks($nodes),
            attacks: $this->mapAttacks($nodes),
            defences: $this->mapDefences($nodes),
            riskAttackLinks: $this->mapRiskAttackLinks($riskAttackRows, $idToUuid),
            attackDefenceLinks: $this->mapAttackDefenceLinks($attackDefenceRows, $idToUuid),
            scaleLevels: $this->mapScaleLevels($scaleLevelRows),
            scales: $this->mapScales($scaleLevelRows),
        );
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return array<string, array{uuid: string, title: string, impact_mode: ?string, impact_amount: ?float, impact_level_id: ?int}>
     */
    private function mapRisks(Collection $nodes): array
    {
        $risks = [];

        foreach ($nodes->where('type', 'risk') as $node) {
            $risks[$node->uuid] = [
                'uuid' => $node->uuid,
                'title' => $node->title,
                'impact_mode' => $node->impact_mode,
                'impact_amount' => $node->impact_amount !== null ? (float) $node->impact_amount : null,
                'impact_level_id' => $node->impact_level_id,
            ];
        }

        return $risks;
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return array<string, array{uuid: string, title: string, likelihood_mode: ?string, likelihood_aro: ?float, likelihood_level_id: ?int}>
     */
    private function mapAttacks(Collection $nodes): array
    {
        $attacks = [];

        foreach ($nodes->where('type', 'attack') as $node) {
            $attacks[$node->uuid] = [
                'uuid' => $node->uuid,
                'title' => $node->title,
                'likelihood_mode' => $node->likelihood_mode,
                'likelihood_aro' => $node->likelihood_aro !== null ? (float) $node->likelihood_aro : null,
                'likelihood_level_id' => $node->likelihood_level_id,
            ];
        }

        return $attacks;
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return array<string, array{uuid: string, title: string, is_transfer: bool, status: ?string, capex_amount: ?float, opex_annual_amount: ?float}>
     */
    private function mapDefences(Collection $nodes): array
    {
        $defences = [];

        foreach ($nodes->where('type', 'defence') as $node) {
            $defences[$node->uuid] = [
                'uuid' => $node->uuid,
                'title' => $node->title,
                'is_transfer' => (bool) $node->is_transfer,
                'status' => $node->status,
                'capex_amount' => $node->capex_amount !== null ? (float) $node->capex_amount : null,
                'opex_annual_amount' => $node->opex_annual_amount !== null ? (float) $node->opex_annual_amount : null,
            ];
        }

        return $defences;
    }

    /**
     * @param  Collection<int, RiskAttack>  $rows
     * @param  Collection<int, string>  $idToUuid
     * @return list<array{risk_uuid: string, attack_uuid: string, position: int}>
     */
    private function mapRiskAttackLinks(Collection $rows, Collection $idToUuid): array
    {
        return $rows
            ->filter(fn (RiskAttack $row): bool => $this->bothEndsAlive($idToUuid, $row->risk_node_id, $row->attack_node_id))
            ->map(fn (RiskAttack $row): array => [
                'risk_uuid' => (string) $idToUuid[$row->risk_node_id],
                'attack_uuid' => (string) $idToUuid[$row->attack_node_id],
                'position' => (int) $row->position,
            ])->values()->all();
    }

    /**
     * Edge rows are selected by their *near* side only — risk_attack by
     * risk_node_id, attack_defence by attack_node_id — so the far side may
     * belong to a node that is soft-deleted and therefore absent from
     * $idToUuid. A soft delete deliberately leaves edge rows in place, so
     * that an undo can restore the node with its links intact
     * (DeleteNodeApplier: dangling edges are "simply left in place,
     * invisible to the graph loader until restored").
     *
     * They were not in fact invisible. Without this filter the far-side
     * lookup hits a missing key and the whole load throws, so deleting an
     * attack that sits under a risk — an ordinary click in the editor —
     * made the entire assessment fail to load: the tree, the report and
     * every calculation, not just the deleted branch. This makes the
     * loader behave the way DeleteNodeApplier already documents.
     *
     * @param  Collection<int, string>  $idToUuid
     */
    private function bothEndsAlive(Collection $idToUuid, int $nearId, int $farId): bool
    {
        return $idToUuid->has($nearId) && $idToUuid->has($farId);
    }

    /**
     * @param  Collection<int, AttackDefence>  $rows
     * @param  Collection<int, string>  $idToUuid
     * @return list<array{attack_uuid: string, defence_uuid: string, position: int, strength_mode: ?string, strength_fraction: ?float, strength_level_id: ?int, reduction_target: ?string, rationale: ?string}>
     */
    private function mapAttackDefenceLinks(Collection $rows, Collection $idToUuid): array
    {
        return $rows
            ->filter(fn (AttackDefence $row): bool => $this->bothEndsAlive($idToUuid, $row->attack_node_id, $row->defence_node_id))
            ->map(fn (AttackDefence $row): array => [
                'attack_uuid' => (string) $idToUuid[$row->attack_node_id],
                'defence_uuid' => (string) $idToUuid[$row->defence_node_id],
                'position' => (int) $row->position,
                'strength_mode' => $row->strength_mode,
                'strength_fraction' => $row->strength_fraction !== null ? (float) $row->strength_fraction : null,
                'strength_level_id' => $row->strength_level_id,
                'reduction_target' => $row->reduction_target,
                // Carried for the node modal's Links tab, not for the engine: the
                // Links tab could save a rationale but never show it again,
                // because this map dropped the column on every reload.
                'rationale' => $row->rationale,
            ])->values()->all();
    }

    /**
     * @param  Collection<int, ScaleLevel>  $rows
     * @return array<int, array{id: int, scale_id: int, kind: string, unit: string, code: string, label: string, ordinal: int, lower_bound: float, upper_bound: ?float, representative: float}>
     */
    private function mapScaleLevels(Collection $rows): array
    {
        $levels = [];

        foreach ($rows as $row) {
            $levels[$row->id] = [
                'id' => $row->id,
                'scale_id' => $row->scale_id,
                'kind' => (string) $row->getAttribute('scale_kind'),
                'unit' => (string) $row->getAttribute('scale_unit'),
                'code' => $row->code,
                'label' => $row->label,
                'ordinal' => (int) $row->ordinal,
                'lower_bound' => (float) $row->lower_bound,
                'upper_bound' => $row->upper_bound !== null ? (float) $row->upper_bound : null,
                'representative' => (float) $row->representative,
            ];
        }

        return $levels;
    }

    /**
     * Collapses the joined scale columns back into one row per scale.
     *
     * Derived from the level rows rather than queried, because query 4 already
     * joins `scales`; a separate select would be a fifth round trip for three
     * booleans. A scale with no levels cannot occur — schema.md §3.2 invariant
     * 3 requires at least one band starting at 0 — so nothing is lost by
     * reaching them through their levels.
     *
     * @param  Collection<int, ScaleLevel>  $rows
     * @return array<int, array{id: int, kind: string, unit: string, is_system: bool}>
     */
    private function mapScales(Collection $rows): array
    {
        $scales = [];

        foreach ($rows as $row) {
            $scaleId = (int) $row->scale_id;

            if (isset($scales[$scaleId])) {
                continue;
            }

            $scales[$scaleId] = [
                'id' => $scaleId,
                'kind' => (string) $row->getAttribute('scale_kind'),
                'unit' => (string) $row->getAttribute('scale_unit'),
                'is_system' => (bool) $row->getAttribute('scale_is_system'),
            ];
        }

        return $scales;
    }
}
