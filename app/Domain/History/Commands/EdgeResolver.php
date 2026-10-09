<?php

namespace App\Domain\History\Commands;

use App\Models\AttackDefence;
use App\Models\Node;
use App\Models\RiskAttack;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Resolves the two typed edge pivots generically so Unlink/Relink/
 * ReparentEdge don't each re-derive the risk_attack vs attack_defence
 * branching. The `edge` discriminator is matched against a fixed two-entry
 * whitelist below — never used to build a class name from stored input
 * (CLAUDE.md, security.md §7 defect #7).
 */
final class EdgeResolver
{
    /**
     * @return array{model: class-string<Pivot>, parentColumn: string, childColumn: string}
     */
    public static function meta(string $edge): array
    {
        return match ($edge) {
            'risk_attack' => [
                'model' => RiskAttack::class,
                'parentColumn' => 'risk_node_id',
                'childColumn' => 'attack_node_id',
            ],
            'attack_defence' => [
                'model' => AttackDefence::class,
                'parentColumn' => 'attack_node_id',
                'childColumn' => 'defence_node_id',
            ],
            default => throw new \InvalidArgumentException("Unknown edge type: {$edge}"),
        };
    }

    /**
     * `Node` itself carries no type scope (unlike Risk/Attack/Defence), so
     * this resolves any node regardless of type — exactly what a generic
     * edge endpoint lookup needs. Includes trashed rows: an Applier decides
     * for itself whether a soft-deleted endpoint is acceptable.
     */
    public static function findNode(string $uuid): ?Node
    {
        return Node::withTrashed()->where('uuid', $uuid)->first();
    }
}
