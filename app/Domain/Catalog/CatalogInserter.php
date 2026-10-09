<?php

namespace App\Domain\Catalog;

use App\Domain\Structure\LinkService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\CatalogEntry;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\RiskAttack;
use App\Models\ScaleLevel;
use App\Observers\Concerns\TriggersRecalculation;
use Illuminate\Support\Facades\DB;

/**
 * Inserts a catalogue entry — and, if it is a bundle, every entry linked
 * to it through `catalog_entry_links` — into a target assessment
 * (schema.md §3.14, PLAN.md Phase 8 item 4).
 *
 * Deliberately independent of
 * Structure\AssessmentCloner::insertCatalogEntry(): that method (built in
 * W6) resolves a single entry's level codes but silently leaves an
 * unresolvable code unset rather than failing, and has no bundle support
 * at all — it never reads catalog_entry_links. Both behaviours conflict
 * with this package's spec ("fail loudly... never silently fall back to
 * a default or to null", "entries may be bundles"). This package does not
 * own AssessmentCloner (W6), so rather than edit it, catalogue insertion
 * is implemented here from the same primitives it already uses: the Node
 * STI subclasses and Structure\LinkService. AssessmentCloner itself is
 * untouched and remains in use, unmodified, for template cloning
 * (see Http\Controllers\TemplateController).
 *
 * **The single most important correctness property in this package**: a
 * catalogue entry's `defaults` stores scale level *codes*, never ids
 * (CatalogEntry's own docblock, schema.md §3.14), specifically so the same
 * entry inserts correctly into any assessment regardless of that
 * assessment's own scale calibration. Every code is resolved against the
 * *target* assessment's scales at insert time, here, never against
 * whatever assessment the entry might have been authored against — a
 * catalogue entry carries no assessment_id at all, so there is no "wrong"
 * assessment for it to accidentally resolve against, only the target.
 */
class CatalogInserter
{
    /**
     * How far below the root a bundle's edges can go: risk → attack → defence
     * is three tiers, so two levels of edges. A guard on malformed data rather
     * than a limit on the feature — see loadBundle().
     */
    private const MAX_BUNDLE_DEPTH = 2;

    public function __construct(private readonly LinkService $links) {}

    /**
     * @return array{
     *     root: Node,
     *     nodes: list<Node>,
     *     riskAttackLinks: list<RiskAttack>,
     *     attackDefenceLinks: list<AttackDefence>,
     * }
     */
    public function insert(CatalogEntry $entry, Assessment $target, ?Node $parent = null, ?int $userId = null): array
    {
        return DB::transaction(fn (): array => TriggersRecalculation::coalesce(function () use ($entry, $target, $parent, $userId): array {
            // Everything the recursion used to fetch per node, fetched once
            // (W64). See CatalogInsertRun for the measurement that prompted it.
            //
            // The coalesce() wrapper is the other half, and the larger one.
            // Without it every node and every edge bumps the assessment's
            // calculation version on its own, so a bundle of forty controls
            // issues eighty UPDATEs to change one number. Inserting a bundle is
            // one logical operation and gets one bump.
            $run = new CatalogInsertRun(
                levels: $this->levelMap($target),
                positions: $this->startingPositions($target),
                children: $this->loadBundle($entry),
            );

            $root = $this->insertRecursive($entry, $target, $parent, null, $userId, $run);

            return [
                'root' => $root,
                'nodes' => $run->nodes,
                'riskAttackLinks' => $run->riskAttackLinks,
                'attackDefenceLinks' => $run->attackDefenceLinks,
            ];
        }));
    }

    /**
     * The target assessment's own calibration, as `[scale id][code] => level id`
     * (W64).
     *
     * One query for all three scales instead of one per level code in the
     * bundle. The resolution *rule* is unchanged and is still the single most
     * important correctness property here: a code is resolved against the
     * target's scales, never against whatever the entry was authored beside,
     * and a code that does not resolve still fails loudly rather than falling
     * back. This only changes when the lookup table is built.
     *
     * `impact_scale_id` is nullable, so it is filtered out rather than allowed
     * to widen the `whereIn` to `NULL` — resolveLevelDefault() still raises the
     * "no scale configured" error for that case, and it has to keep doing so,
     * because an empty map is indistinguishable from an unconfigured scale here.
     *
     * @return array<int, array<string, int>>
     */
    private function levelMap(Assessment $target): array
    {
        $scaleIds = array_values(array_filter([
            $target->impact_scale_id,
            $target->likelihood_scale_id,
            $target->strength_scale_id,
        ]));

        if ($scaleIds === []) {
            return [];
        }

        $map = [];

        foreach (ScaleLevel::query()->whereIn('scale_id', $scaleIds)->get(['id', 'scale_id', 'code']) as $level) {
            $map[(int) $level->scale_id][(string) $level->code] = (int) $level->id;
        }

        return $map;
    }

    /**
     * Where each type's numbering picks up, as one grouped query rather than a
     * `MAX(position)` per inserted node (W64).
     *
     * Only `risk` and `defence`: attack order lives on `risk_attack.position`
     * and is set by LinkService, which is why instantiate() leaves an attack at
     * the column default.
     *
     * @return array<string, int>
     */
    private function startingPositions(Assessment $target): array
    {
        $rows = Node::withoutGlobalScopes()
            ->where('assessment_id', $target->id)
            ->whereIn('type', ['risk', 'defence'])
            ->groupBy('type')
            ->selectRaw('type, MAX(position) as max_position')
            ->get();

        $positions = ['risk' => 0, 'defence' => 0];

        foreach ($rows as $row) {
            // `+ 1` here rather than at the call site, matching what the old
            // per-node query returned: the next free position, not the last
            // used one. A type with no rows yet keeps its 0.
            $positions[(string) $row->getAttribute('type')] = (int) $row->getAttribute('max_position') + 1;
        }

        return $positions;
    }

    /**
     * The whole bundle's `catalog_entry_links` tree, read breadth-first (W64).
     *
     * Two queries per level instead of one per entry — and the depth is not the
     * bundle's size but the DAG's, which schema.md fixes at risk → attack →
     * defence. A bundle of eighty controls is the same number of queries as a
     * bundle of two.
     *
     * ## The two bounds, and why both are here
     *
     * `assertValidShape()` already makes a cycle unreachable *during insertion*
     * — a defence cannot legally have children — but this method runs **before**
     * any validation, so it cannot lean on that. A `catalog_entry_links` row
     * pair pointing back at an ancestor would spin here forever, on a query
     * loop, inside a transaction. So the walk stops on a parent it has already
     * expanded, and stops again at the DAG's depth regardless. Neither bound
     * can trigger on well-formed data; they exist because this code reads rows
     * that nothing in the schema forbids from being circular.
     *
     * @return array<int, list<array{entry: CatalogEntry, defaults: array<string, mixed>}>>
     */
    private function loadBundle(CatalogEntry $root): array
    {
        $children = [];
        $frontier = [$root->id];
        $expanded = [];

        // risk → attack → defence is three tiers, so two levels of edges below
        // the root is every edge a valid bundle can have.
        for ($depth = 0; $depth < self::MAX_BUNDLE_DEPTH && $frontier !== []; $depth++) {
            foreach ($frontier as $id) {
                $expanded[$id] = true;
            }

            // Ordered so a bundle inserts the same way every time. The previous
            // per-entry `catalogChildren()->get()` had no ordering at all, so
            // the positions a bundle's defences received were whatever the
            // database happened to return.
            $rows = DB::table('catalog_entry_links')
                ->whereIn('parent_entry_id', $frontier)
                ->orderBy('parent_entry_id')
                ->orderBy('child_entry_id')
                ->get(['parent_entry_id', 'child_entry_id', 'defaults']);

            if ($rows->isEmpty()) {
                break;
            }

            $entries = CatalogEntry::query()
                ->whereIn('id', $rows->pluck('child_entry_id')->unique()->all())
                ->get()
                ->keyBy('id');

            $frontier = [];

            foreach ($rows as $row) {
                $child = $entries->get($row->child_entry_id);

                if ($child === null) {
                    continue;
                }

                $children[(int) $row->parent_entry_id][] = [
                    'entry' => $child,
                    // `catalog_entry_links` has no Pivot class, so this JSON
                    // column arrives uncast — a raw string — unlike
                    // CatalogEntry::$defaults, which the model does cast.
                    'defaults' => $this->decodePivotDefaults($row->defaults),
                ];

                if (! isset($expanded[$child->id])) {
                    $frontier[] = $child->id;
                }
            }

            $frontier = array_values(array_unique($frontier));
        }

        return $children;
    }

    /**
     * @param  array<string, mixed>|null  $linkDefaults  the (parent, this
     *                                                   entry) pivot's own `catalog_entry_links.defaults` — e.g. a default
     *                                                   strength level code — distinct from $entry->defaults, which
     *                                                   describes the node's own value, not the edge into it.
     */
    private function insertRecursive(
        CatalogEntry $entry,
        Assessment $target,
        ?Node $parent,
        ?array $linkDefaults,
        ?int $userId,
        CatalogInsertRun $run,
    ): Node {
        $this->assertValidShape($entry, $parent);

        // One entry is one node per run, however many parents reach it (W78).
        // `catalog_entry_links` is a DAG — loadBundle() already guards against
        // re-expanding a parent — and until this map existed the recursion
        // below made a fresh node for every edge it walked, so a control shared
        // by two attacks arrived twice. The second occurrence links the node
        // that already exists to its new parent instead.
        $existing = $run->nodesByEntry[$entry->id] ?? null;

        $node = $existing ?? $this->instantiate($entry, $target, $userId, $run);

        if ($existing === null) {
            $run->nodesByEntry[$entry->id] = $node;
            $run->nodes[] = $node;
        }

        // The edge is made for every occurrence, reused node or not: strength,
        // reduction target and rationale live on the pivot (CLAUDE.md), which
        // is the whole reason one control can be 80% effective against one
        // attack and 20% against another. Only the *node* defaults — status,
        // capex, opex from `catalog_entries.defaults` — are the first
        // occurrence's, which is the behaviour change this fix carries.
        if ($parent instanceof Risk && $node instanceof Attack) {
            $run->riskAttackLinks[] = $this->links->linkRiskToAttack($parent, $node);
        } elseif ($parent instanceof Attack && $node instanceof Defence) {
            $link = $this->links->linkAttackToDefence($parent, $node);
            $this->applyEdgeDefaults($link, $linkDefaults ?? [], $target, $run);
            $run->attackDefenceLinks[] = $link;
        }

        // A reused node already brought its own subtree in on its first
        // occurrence; walking it again would rebuild every descendant edge.
        //
        // Defensive, and deliberately not claimed as covered: with today's
        // two-tier DAG no fixture can reach it. A bundle is rooted at one
        // risk, `catalog_entry_links_unique (parent_entry_id,
        // child_entry_id)` stops that root linking the same attack twice,
        // and assertValidShape() allows only risk → attack → defence — so
        // the only entry two parents can share is a defence, which is a
        // leaf, and re-walking a leaf changes nothing. W78 mutation-tested
        // it, saw it survive, and kept it rather than pretending otherwise:
        // it costs nothing and it is what stops this being silently wrong
        // again if the DAG ever gains a tier.
        if ($existing !== null) {
            return $node;
        }

        // Read from the bundle loaded once in insert(), not queried per entry.
        foreach ($run->childrenOf($entry) as $child) {
            $this->insertRecursive($child['entry'], $target, $node, $child['defaults'], $userId, $run);
        }

        return $node;
    }

    /**
     * Enforces the only two edge shapes the DAG permits (risk_attack,
     * attack_defence — schema.md §3.5, §3.6), the same "cycle-free by
     * construction" invariant LinkService's own docblock describes, and
     * that a top-level insert (no parent) only ever makes sense for a
     * `risk` entry — an attack or defence catalogue entry with no parent
     * would create an unreachable node nothing links into.
     */
    private function assertValidShape(CatalogEntry $entry, ?Node $parent): void
    {
        if ($parent === null) {
            if ($entry->kind !== 'risk') {
                throw new CatalogInsertionException(
                    "Cannot insert a '{$entry->kind}' catalogue entry without a parent node to attach it to."
                );
            }

            return;
        }

        $valid = ($parent instanceof Risk && $entry->kind === 'attack')
            || ($parent instanceof Attack && $entry->kind === 'defence');

        if (! $valid) {
            throw new CatalogInsertionException(sprintf(
                "Cannot attach a '%s' catalogue entry to a %s node.",
                $entry->kind,
                $parent->type,
            ));
        }
    }

    private function instantiate(CatalogEntry $entry, Assessment $target, ?int $userId, CatalogInsertRun $run): Node
    {
        $attributes = [
            'title' => $entry->title,
            'description' => $entry->description,
        ];

        $attributes = [...$attributes, ...$this->resolveNodeDefaults($entry->kind, $entry->defaults ?? [], $target, $run)];

        $node = $this->instantiateNode($entry->kind, $attributes);

        $node->assessment_id = $target->id;
        $node->created_by = $userId;
        $node->updated_by = $userId;

        // Node::position orders risks under the root and defences in the
        // assessment library (schema.md §3.4 migration comment); attack
        // order lives on risk_attack.position, set by LinkService instead,
        // so attacks are deliberately left at the column default here.
        if ($entry->kind === 'risk' || $entry->kind === 'defence') {
            $node->position = $run->takePosition($entry->kind);
        }

        $node->save();

        return $node;
    }

    /**
     * Resolves a catalogue kind to its STI subclass and constructs it.
     * `$kind` is deliberately typed plain `string`, not read directly off
     * `$entry->kind` inside a match — CatalogEntry's Eloquent column type
     * narrows that to the literal 'risk'|'attack'|'defence' union, which
     * would make the `default` arm below provably unreachable to PHPStan.
     * Going through a plain-`string` parameter keeps the match honestly
     * exhaustive instead of narrowed away, the same shape
     * AssessmentCloner::instantiateNode() uses for the identical reason.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function instantiateNode(string $kind, array $attributes): Node
    {
        return match ($kind) {
            'risk' => new Risk($attributes),
            'attack' => new Attack($attributes),
            'defence' => new Defence($attributes),
            // catalog_entries.kind is a database ENUM restricted to the
            // three cases above (schema.md §3.14); this arm only exists so
            // the match is provably exhaustive to the type checker, never a
            // dynamic class lookup built from request input (CLAUDE.md,
            // security.md defect #7).
            default => throw new CatalogInsertionException("Unknown catalog entry kind: {$kind}"),
        };
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function resolveNodeDefaults(string $kind, array $defaults, Assessment $target, CatalogInsertRun $run): array
    {
        return match ($kind) {
            'risk' => $this->resolveLevelDefault(
                $defaults,
                $run,
                $target->impact_scale_id,
                'impact_mode',
                'impact_amount',
                'impact_level_id',
                'impact_level_code',
            ),
            'attack' => $this->resolveLevelDefault(
                $defaults,
                $run,
                $target->likelihood_scale_id,
                'likelihood_mode',
                'likelihood_aro',
                'likelihood_level_id',
                'likelihood_level_code',
            ),
            'defence' => array_intersect_key(
                $defaults,
                array_flip(['is_transfer', 'status', 'capex_amount', 'opex_annual_amount']),
            ),
            default => [],
        };
    }

    /**
     * Resolves a scale-level *code* to the target assessment's own level
     * id. This is the load-bearing method in the package: given the same
     * $defaults, two assessments with differently-calibrated scales must
     * resolve the same code to two different ids, each valid against its
     * own scale (see tests/Feature/Catalog/CatalogCodeResolutionTest.php).
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function resolveLevelDefault(
        array $defaults,
        CatalogInsertRun $run,
        ?int $scaleId,
        string $modeKey,
        string $amountKey,
        string $levelIdKey,
        string $levelCodeKey,
    ): array {
        $result = [];

        if (isset($defaults[$modeKey])) {
            $result[$modeKey] = $defaults[$modeKey];
        }

        if (isset($defaults[$amountKey])) {
            $result[$amountKey] = $defaults[$amountKey];
        }

        if (isset($defaults[$levelCodeKey])) {
            $code = (string) $defaults[$levelCodeKey];

            if ($scaleId === null) {
                throw new CatalogInsertionException(
                    "Cannot resolve level code '{$code}': the target assessment has no scale configured."
                );
            }

            // From the map built once per run (W64), not a query per code.
            $levelId = $run->levels[$scaleId][$code] ?? null;

            if ($levelId === null) {
                // Per schema.md §3.14: a code that does not resolve against
                // the target's calibration must fail loudly, never fall
                // back to a default or to null — a silent fallback here
                // would either produce a wrong number or, on strict FK
                // enforcement, an opaque database error far from its cause.
                throw new CatalogInsertionException(
                    "Level code '{$code}' does not exist on the target assessment's scale (scale id {$scaleId})."
                );
            }

            $result[$levelIdKey] = $levelId;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $linkDefaults  catalog_entry_links.defaults
     *                                              for this (parent, child) pair — e.g. {"strength_level_code": "strong"}
     */
    private function applyEdgeDefaults(AttackDefence $link, array $linkDefaults, Assessment $target, CatalogInsertRun $run): void
    {
        $update = [];

        if (isset($linkDefaults['reduction_target'])) {
            $update['reduction_target'] = $linkDefaults['reduction_target'];
        }

        if (isset($linkDefaults['strength_fraction'])) {
            $update['strength_mode'] = 'quantitative';
            $update['strength_fraction'] = $linkDefaults['strength_fraction'];
        }

        if (isset($linkDefaults['strength_level_code'])) {
            $code = (string) $linkDefaults['strength_level_code'];

            // Unlike resolveLevelDefault()'s $scaleId (which can be null in
            // theory, hence its own guard), assessments.strength_scale_id
            // is NOT NULL (schema.md §3.3's assessments migration) — no
            // "not configured" state exists to guard against here.
            $levelId = $run->levels[(int) $target->strength_scale_id][$code] ?? null;

            if ($levelId === null) {
                throw new CatalogInsertionException(
                    "Strength level code '{$code}' does not exist on the target assessment's strength scale (scale id {$target->strength_scale_id})."
                );
            }

            $update['strength_mode'] = 'qualitative';
            $update['strength_level_id'] = $levelId;
        }

        if ($update !== []) {
            $link->fill($update);
            $link->save();
        }
    }

    /** @return array<string, mixed> */
    private function decodePivotDefaults(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
