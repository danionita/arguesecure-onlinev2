<?php

namespace App\Domain\Structure;

use App\Models\AttackDefence;
use App\Models\Node;
use App\Models\RiskAttack;
use Illuminate\Support\Facades\DB;

/**
 * Re-sequences siblings inside a transaction (schema.md §4). Ordering
 * lives on the edge — risks-under-root and defences-in-the-library use
 * `nodes.position`; attacks-under-a-risk use `risk_attack.position`;
 * defences-under-an-attack use `attack_defence.position` — so the same
 * attack can sit in a different position under different risks.
 */
class ReorderService
{
    /**
     * Re-sequences risks under the root, or defences in the assessment
     * library — both use `nodes.position` directly.
     *
     * @param  array<int>  $nodeIdsInOrder  need not be a list — array_values() re-sequences it
     */
    public function reorderNodes(array $nodeIdsInOrder): void
    {
        DB::transaction(function () use ($nodeIdsInOrder): void {
            foreach (array_values($nodeIdsInOrder) as $position => $id) {
                Node::withoutGlobalScopes()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /** @param  array<int>  $attackNodeIdsInOrder  need not be a list — array_values() re-sequences it */
    public function reorderAttacksUnderRisk(int $riskNodeId, array $attackNodeIdsInOrder): void
    {
        DB::transaction(function () use ($riskNodeId, $attackNodeIdsInOrder): void {
            foreach (array_values($attackNodeIdsInOrder) as $position => $attackNodeId) {
                RiskAttack::query()
                    ->where('risk_node_id', $riskNodeId)
                    ->where('attack_node_id', $attackNodeId)
                    ->update(['position' => $position]);
            }
        });
    }

    /** @param  array<int>  $defenceNodeIdsInOrder  need not be a list — array_values() re-sequences it */
    public function reorderDefencesUnderAttack(int $attackNodeId, array $defenceNodeIdsInOrder): void
    {
        DB::transaction(function () use ($attackNodeId, $defenceNodeIdsInOrder): void {
            foreach (array_values($defenceNodeIdsInOrder) as $position => $defenceNodeId) {
                AttackDefence::query()
                    ->where('attack_node_id', $attackNodeId)
                    ->where('defence_node_id', $defenceNodeId)
                    ->update(['position' => $position]);
            }
        });
    }
}
