<?php

namespace App\Domain\Catalog;

use App\Models\AttackDefence;
use App\Models\CatalogEntry;
use App\Models\Node;
use App\Models\RiskAttack;

/**
 * One run of `CatalogInserter::insert()` — everything the recursion needs that
 * is resolved once for the whole bundle rather than once per node (W64).
 *
 * ## Why it exists
 *
 * W24 measured the inserter issuing three separate queries per descendant: the
 * children of each entry, a `MAX(position)` for each inserted node, and a scale
 * level lookup for each level code. Query count scaled with bundle size, which
 * is worst exactly where the catalogue earns its keep — an ISO entry shipping
 * dozens of controls. All three are now resolved up front, and this is where
 * the answers live.
 *
 * It also replaces the eight-parameter recursion that grew out of passing three
 * accumulators by reference. Adding three more preloaded maps to that signature
 * would have made the batching harder to read than the N+1 it removes.
 *
 * Mutable on purpose, and deliberately not readonly: `$positions` is a counter
 * the recursion advances, and the three link/node lists are accumulators. It is
 * created inside `insert()` and never escapes it, so nothing outside one
 * transaction can observe a half-built run.
 */
final class CatalogInsertRun
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<RiskAttack> */
    public array $riskAttackLinks = [];

    /** @var list<AttackDefence> */
    public array $attackDefenceLinks = [];

    /**
     * Every entry this run has already turned into a node, as
     * `[catalog_entry_id => Node]` (W78).
     *
     * `catalog_entry_links` is a DAG, and the recursion used to keep no memory
     * of what it had built, so an entry reachable from two parents inside one
     * bundle arrived as two nodes — the shipped ISO/IEC 27001+27002 bundle put
     * `defence-access-control-policy` under two of one risk's attacks and got
     * ten defence nodes for nine distinct controls. Nothing errored and nothing
     * was missing, which is why it survived: the result was over-full, not
     * empty. But CLAUDE.md's domain model says a defence may belong to several
     * attacks precisely so one control is one node — counted once in a cost
     * rollup, and edited in one place.
     *
     * Scoped to the run, not cached beyond it: two separate inserts of the same
     * entry are two deliberate acts and must stay two nodes.
     *
     * @var array<int, Node>
     */
    public array $nodesByEntry = [];

    /**
     * @param  array<int, array<string, int>>  $levels  scale id => [level code => level id], the target's own calibration
     * @param  array<string, int>  $positions  node type => the position the next inserted node of that type takes
     * @param  array<int, list<array{entry: CatalogEntry, defaults: array<string, mixed>}>>  $children  parent entry id => its children and their edge defaults, in a fixed order
     */
    public function __construct(
        public array $levels,
        public array $positions,
        public array $children,
    ) {}

    /**
     * The position for the next node of this type, advancing the counter.
     *
     * Reproduces exactly what a per-node `MAX(position) + 1` returned, because
     * each insert used to see the previous one already committed. Doing it in
     * memory is safe for the same reason the old query was: the whole insert is
     * one transaction, so nothing else can interleave a row between two of
     * these.
     */
    public function takePosition(string $type): int
    {
        $position = $this->positions[$type] ?? 0;
        $this->positions[$type] = $position + 1;

        return $position;
    }

    /**
     * @return list<array{entry: CatalogEntry, defaults: array<string, mixed>}>
     */
    public function childrenOf(CatalogEntry $entry): array
    {
        return $this->children[$entry->id] ?? [];
    }
}
