<?php

namespace App\Domain\Structure;

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\CatalogEntry;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\RiskAttack;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clones an assessment, a subtree, or inserts a catalogue entry
 * (schema.md §3.14, §4). Every clone remaps `uuid`s — the copy is a new,
 * independent identity, never a reference back to the source.
 */
class AssessmentCloner
{
    /**
     * Columns never carried across by replicate().
     *
     * `root_marker` is the one that is not obvious: it is a *generated*
     * column (schema.md §3.3 — the trick that enforces exactly one root per
     * assessment), and MariaDB rejects an INSERT that supplies a value for
     * one. replicate() copies every loaded attribute, so it included
     * root_marker and every clone failed with error 1906 — meaning
     * cloneAssessment() could not clone anything at all, and "create a new
     * assessment from a template" was dead on arrival. Under a lenient
     * sql_mode that is only a warning, which is exactly why it survived:
     * this project runs MariaDB in strict mode, where it is a hard error.
     */
    private const NOT_REPLICATED = [
        'uuid',
        'root_marker',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Clones an entire assessment: its own scales (if custom), tags, every
     * node, and both edge tables. System scales are shared, not cloned.
     */
    public function cloneAssessment(Assessment $source, User $newOwner): Assessment
    {
        return DB::transaction(function () use ($source, $newOwner): Assessment {
            // Scale FKs are NOT NULL, so the assessment is first created
            // borrowing the source's scale ids (valid rows that already
            // exist), then repointed at freshly cloned scales below once it
            // has an id of its own to own them.
            $clone = new Assessment;
            $clone->uuid = (string) Str::uuid();
            $clone->owner_user_id = $newOwner->id;
            $clone->currency = $source->currency;
            $clone->display_mode = $source->display_mode;
            $clone->impact_scale_id = $source->impact_scale_id;
            $clone->likelihood_scale_id = $source->likelihood_scale_id;
            $clone->strength_scale_id = $source->strength_scale_id;
            $clone->amortization_years = $source->amortization_years;
            $clone->is_public = false;
            $clone->is_template = false;
            $clone->calc_version = 0;
            $clone->save();

            $impactLevelMap = [];
            $likelihoodLevelMap = [];
            $strengthLevelMap = [];

            $clone->impact_scale_id = $this->cloneScaleIfCustom($source->impactScale, $clone, $impactLevelMap);
            $clone->likelihood_scale_id = $this->cloneScaleIfCustom($source->likelihoodScale, $clone, $likelihoodLevelMap);
            $clone->strength_scale_id = $this->cloneScaleIfCustom($source->strengthScale, $clone, $strengthLevelMap);
            $clone->save();

            $tagMap = $this->cloneTags($source, $clone);

            $uuidMap = $this->cloneNodes($source, $clone, $impactLevelMap, $likelihoodLevelMap);
            $this->cloneRiskAttackLinks($uuidMap);
            $this->cloneAttackDefenceLinks($uuidMap, $strengthLevelMap);
            $this->cloneNodeTags($uuidMap, $tagMap);
            $this->cloneNodeReferences($uuidMap);

            return $clone->fresh() ?? $clone;
        });
    }

    /**
     * Duplicates a risk or an attack together with everything reachable
     * strictly downward through the DAG (a risk's attacks and their
     * defences; an attack's defences), appended to the end of the target
     * assessment. Used for the "duplicate" editor affordance.
     */
    public function cloneSubtree(Risk|Attack $root, Assessment $target): Node
    {
        return DB::transaction(function () use ($root, $target): Node {
            $uuidMap = [];

            $clonedRoot = $this->cloneSingleNode($root, $target, $uuidMap);

            if ($root instanceof Risk) {
                foreach ($root->attacks as $attack) {
                    $clonedAttack = $this->cloneSingleNode($attack, $target, $uuidMap);

                    // Direct property assignment, not ::create(): RiskAttack's
                    // $fillable is `position` only, so a mass-assigned
                    // create() silently dropped both foreign keys and failed
                    // the NOT NULL constraint — the exact bug LinkService's
                    // docblock was written to warn about.
                    $clonedLink = new RiskAttack;
                    $clonedLink->risk_node_id = $clonedRoot->id;
                    $clonedLink->attack_node_id = $clonedAttack->id;
                    $clonedLink->position = $attack->pivot->position;
                    // Both endpoints are already in hand; handing them to the
                    // edge saves the observer layer three lazy loads per link
                    // (W68, LinkService::carryEndpoints()).
                    $clonedLink->setRelation('risk', $clonedRoot);
                    $clonedLink->setRelation('attack', $clonedAttack);
                    $clonedLink->save();
                    $this->cloneDefencesOfAttack($attack, $clonedAttack, $target, $uuidMap);
                }
            } else {
                $this->cloneDefencesOfAttack($root, $clonedRoot, $target, $uuidMap);
            }

            // Same omission as cloneAssessment() had, reached from the editor's
            // duplicate affordance instead of from a template (W78). One
            // SELECT and one INSERT covering every node the subtree touched,
            // which is why it runs here rather than inside cloneSingleNode().
            $this->cloneNodeReferences($uuidMap);

            return $clonedRoot;
        });
    }

    /**
     * Inserts a catalogue entry as a new node in the target assessment
     * (a copy — later edits to the entry never propagate, schema.md §3.14).
     * Level codes in `defaults` are resolved against the target's own
     * scales; an unresolvable code is left unset rather than guessed.
     *
     * No `node_references` copy here, unlike cloneAssessment() and
     * cloneSubtree() (W78): the source is a `CatalogEntry`, and catalogue
     * entries carry no references at all — `catalog_entries` has kind, title,
     * description and `defaults`, and CatalogEntry has no references relation
     * (schema.md §3.14). There is nothing to drop on this path, so there is
     * nothing to fix. Stated rather than left to be rediscovered, because W76's
     * pin listed this method alongside the two that really did drop them.
     */
    public function insertCatalogEntry(CatalogEntry $entry, Assessment $target): Node
    {
        return DB::transaction(function () use ($entry, $target): Node {
            $defaults = $entry->defaults ?? [];

            $attributes = [
                'assessment_id' => $target->id,
                'type' => $entry->kind,
                'title' => $entry->title,
                'description' => $entry->description,
                'position' => $this->nextLibraryPosition($target, $entry->kind),
            ];

            $attributes = [...$attributes, ...$this->resolveCatalogDefaults($entry->kind, $defaults, $target)];

            $node = $this->instantiateNode($entry->kind, $attributes);
            $node->assessment_id = $target->id;
            $node->save();

            return $node;
        });
    }

    /**
     * Resolves a catalogue kind to its STI subclass and constructs it. The
     * return type is `Node`, not the union PHP would otherwise infer from
     * `new $class(...)`, because every branch of the match is a `Node`
     * subclass and this is where that fact is made visible to the type
     * checker — a single, honest place, not a per-call-site assertion.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function instantiateNode(string $kind, array $attributes): Node
    {
        $class = match ($kind) {
            'risk' => Risk::class,
            'attack' => Attack::class,
            'defence' => Defence::class,
            // The `kind` column is a database ENUM restricted to the three
            // cases above; this default only exists so the match is
            // provably exhaustive to the type checker.
            default => throw new \InvalidArgumentException("Unknown catalog entry kind: {$kind}"),
        };

        return new $class($attributes);
    }

    /**
     * @param  array<int, int>  $levelMap  filled in as [old_level_id => new_level_id]; unmodified when the scale is a shared system scale
     */
    private function cloneScaleIfCustom(?Scale $scale, Assessment $target, array &$levelMap): ?int
    {
        if ($scale === null) {
            return null;
        }

        if ($scale->is_system) {
            return $scale->id;
        }

        // Direct property assignment, not ::create() (W78). Both identity
        // columns here were mass-assigned: `assessment_id` on Scale and
        // `scale_id` on ScaleLevel are deliberately absent from their
        // `$fillable`s, and both models' docblocks say so in as many words.
        // Eloquent dropped each silently and the level then died on the NOT
        // NULL constraint — "Field 'scale_id' doesn't have a default value" —
        // so an assessment whose scales had been calibrated could not be
        // cloned at all, and therefore could not be published as a template
        // and instantiated. The transaction rolled back cleanly, so this was
        // a dead feature rather than corrupted data, which is exactly why it
        // survived: the seeded fixtures all use *system* scales, which return
        // above without ever reaching this code.
        //
        // This is the third and fourth time this trap has been sprung in this
        // file (W6 twice, then here); LinkService's docblock is the reference
        // example of doing it right.
        $clone = new Scale([
            'kind' => $scale->kind,
            'name' => $scale->name,
            'unit' => $scale->unit,
            'is_system' => false,
        ]);
        $clone->assessment_id = $target->id;

        // `set_key` (W77's grouping column for system scale sets) is
        // deliberately *not* carried across, and needs no line here to stay
        // NULL: the clone is built field by field rather than replicate()d,
        // so anything not named above is left at its column default. That is
        // load-bearing, not incidental — a clone inheriting the source's
        // set_key would look like a member of a system set, and
        // ScaleSets::resolve() would then find two scales of the same kind in
        // that set and throw on *every* assessment creation using it. An
        // assessment-owned scale belongs to no set by definition.
        $clone->save();

        foreach ($scale->levels as $level) {
            $newLevel = new ScaleLevel([
                'ordinal' => $level->ordinal,
                'code' => $level->code,
                'label' => $level->label,
                'color' => $level->color,
                'lower_bound' => $level->lower_bound,
                'upper_bound' => $level->upper_bound,
                'representative' => $level->representative,
            ]);
            $newLevel->scale_id = $clone->id;
            $newLevel->save();

            $levelMap[$level->id] = $newLevel->id;
        }

        return $clone->id;
    }

    /**
     * @return array<int, int> old tag id => cloned tag id
     */
    private function cloneTags(Assessment $source, Assessment $target): array
    {
        $map = [];

        foreach ($source->tags as $tag) {
            // Direct property assignment, not ::create(): Tag's $fillable is
            // name/slug/colour only — `assessment_id` is an identity column,
            // never request input — so a mass-assigned create() dropped it
            // silently and hit the NOT NULL constraint. Same reason
            // LinkService and cloneRiskAttackLinks() below already do this.
            $clone = new Tag;
            $clone->assessment_id = $target->id;
            $clone->name = $tag->name;
            $clone->slug = $tag->slug;
            $clone->color = $tag->color;
            $clone->save();

            $map[$tag->id] = $clone->id;
        }

        return $map;
    }

    /**
     * The `node_tag` rows (W43).
     *
     * `cloneTags()` above has copied the assessment's vocabulary since W6,
     * but nothing copied which nodes carried which label — so a clone
     * arrived with every label defined and none of them applied. That was
     * unreachable until W43, because until then no route could attach a tag
     * to a node at all; it becomes a real, silent data loss the moment one
     * can, which is why it is fixed here rather than pinned.
     *
     * One insert for the whole assessment, not one per node: a template with
     * two hundred labelled nodes is exactly the case this service exists to
     * make fast. (`CatalogInserter` was pinned for the same mistake until W64
     * batched its lookups; W78 closed its remaining pin, the duplicate node per
     * DAG edge, so that cross-reference no longer points at an open defect.)
     *
     * @param  array<int, Node>  $uuidMap  old node id => cloned Node
     * @param  array<int, int>  $tagMap  old tag id => cloned tag id
     */
    private function cloneNodeTags(array $uuidMap, array $tagMap): void
    {
        if ($uuidMap === [] || $tagMap === []) {
            return;
        }

        $rows = DB::table('node_tag')
            ->whereIn('node_id', array_keys($uuidMap))
            ->get();

        $inserts = [];
        foreach ($rows as $row) {
            $node = $uuidMap[$row->node_id] ?? null;
            $tagId = $tagMap[$row->tag_id] ?? null;

            if ($node === null || $tagId === null) {
                continue;
            }

            $inserts[] = ['node_id' => $node->id, 'tag_id' => $tagId];
        }

        if ($inserts !== []) {
            DB::table('node_tag')->insert($inserts);
        }
    }

    /**
     * The `node_references` rows — CVE, CWE, CAPEC, ATT&CK technique and free
     * URLs (schema.md §3.12) — W78.
     *
     * Nothing copied these until now, and nothing errored, because the table is
     * a plain child of `nodes` with no NOT NULL pointing back at the clone. So
     * a clone arrived structurally complete with every citation gone. That
     * matters most on the path this service exists for: TemplateController
     * calls cloneAssessment() unmodified, so every reference a template author
     * attached vanished the moment somebody started an assessment from that
     * template — the attack card intact, its reference list empty.
     *
     * One SELECT and one INSERT for the whole assessment, like cloneNodeTags()
     * above and for the same reason: a template with two hundred cited nodes is
     * exactly the case this service exists to make fast, and adding a per-node
     * query here would have reintroduced the N+1 W68 spent a package removing.
     *
     * The bulk insert also sidesteps `$fillable` entirely rather than tiptoeing
     * around it. `node_id` is an identity column deliberately absent from
     * NodeReference's `$fillable` (CLAUDE.md), so an Eloquent create() carrying
     * one drops it silently and then dies on the NOT NULL constraint; the query
     * builder never consults `$fillable` at all. Timestamps are therefore set
     * by hand, because the builder does not manage them either.
     *
     * ## The collision this cannot hit, and why it is still a plain insert
     *
     * `node_references_unique (node_id, kind, identifier)` makes a duplicated
     * (kind, identifier) pair on one clone node a hard insert failure that
     * rolls the whole transaction back, where before it was a silent no-op.
     * That is deliberate: a faithful copy is the contract, and a source holding
     * a pair the index forbids means something wrote around the index, which is
     * worth stopping for rather than papering over with insertOrIgnore().
     *
     * It is also unreachable through this path. Every clone node is brand new,
     * so nothing pre-exists to collide with, and two rows in the batch can only
     * collide if one *source* node held that pair — which the same index
     * already forbids. `identifier` is nullable and NULL never collides in a
     * unique index, so a node carrying several `url` references (which have no
     * identifier) copies faithfully rather than failing. Both halves are
     * pinned in SeededContentMatchesFixtureTest.
     *
     * @param  array<int, Node>  $uuidMap  old node id => cloned Node
     */
    private function cloneNodeReferences(array $uuidMap): void
    {
        if ($uuidMap === []) {
            return;
        }

        $rows = DB::table('node_references')
            ->whereIn('node_id', array_keys($uuidMap))
            ->orderBy('position')
            ->get();

        $now = now();
        $inserts = [];

        foreach ($rows as $row) {
            $node = $uuidMap[$row->node_id] ?? null;

            if ($node === null) {
                continue;
            }

            $inserts[] = [
                'node_id' => $node->id,
                'kind' => $row->kind,
                'identifier' => $row->identifier,
                'url' => $row->url,
                'title' => $row->title,
                'position' => $row->position,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($inserts !== []) {
            DB::table('node_references')->insert($inserts);
        }
    }

    /**
     * @param  array<int, int>  $impactLevelMap
     * @param  array<int, int>  $likelihoodLevelMap
     * @return array<int, Node> old node id => cloned Node
     */
    private function cloneNodes(Assessment $source, Assessment $target, array $impactLevelMap, array $likelihoodLevelMap): array
    {
        $uuidMap = [];

        $nodes = Node::withoutGlobalScopes()
            ->where('assessment_id', $source->id)
            ->orderBy('position')
            ->get();

        foreach ($nodes as $node) {
            $clone = $node->replicate(self::NOT_REPLICATED);
            $clone->assessment_id = $target->id;
            $clone->uuid = (string) Str::uuid();

            if ($node->impact_level_id !== null) {
                $clone->impact_level_id = $impactLevelMap[$node->impact_level_id] ?? $node->impact_level_id;
            }

            if ($node->likelihood_level_id !== null) {
                $clone->likelihood_level_id = $likelihoodLevelMap[$node->likelihood_level_id] ?? $node->likelihood_level_id;
            }

            $clone->save();

            $uuidMap[$node->id] = $clone;
        }

        return $uuidMap;
    }

    /** @param  array<int, Node>  $uuidMap  old node id => cloned Node */
    private function cloneRiskAttackLinks(array $uuidMap): void
    {
        RiskAttack::query()
            ->whereIn('risk_node_id', array_keys($uuidMap))
            ->get()
            ->each(function (RiskAttack $link) use ($uuidMap): void {
                if (! isset($uuidMap[$link->risk_node_id], $uuidMap[$link->attack_node_id])) {
                    return;
                }

                // Direct property assignment, not ::create(): RiskAttack's
                // $fillable deliberately excludes its own foreign keys.
                $clonedLink = new RiskAttack;
                $clonedLink->risk_node_id = $uuidMap[$link->risk_node_id]->id;
                $clonedLink->attack_node_id = $uuidMap[$link->attack_node_id]->id;
                $clonedLink->position = $link->position;
                // See the same pair in cloneAssessment(): the map already holds
                // both cloned nodes, so the observer layer need not re-read them.
                $clonedLink->setRelation('risk', $uuidMap[$link->risk_node_id]);
                $clonedLink->setRelation('attack', $uuidMap[$link->attack_node_id]);
                $clonedLink->save();
            });
    }

    /**
     * @param  array<int, Node>  $uuidMap  old node id => cloned Node
     * @param  array<int, int>  $strengthLevelMap
     */
    private function cloneAttackDefenceLinks(array $uuidMap, array $strengthLevelMap): void
    {
        AttackDefence::query()
            ->whereIn('attack_node_id', array_keys($uuidMap))
            ->get()
            ->each(function (AttackDefence $link) use ($uuidMap, $strengthLevelMap): void {
                if (! isset($uuidMap[$link->attack_node_id], $uuidMap[$link->defence_node_id])) {
                    return;
                }

                // Direct property assignment, not ::create(): AttackDefence's
                // $fillable deliberately excludes its own foreign keys.
                $clonedLink = new AttackDefence;
                $clonedLink->attack_node_id = $uuidMap[$link->attack_node_id]->id;
                $clonedLink->defence_node_id = $uuidMap[$link->defence_node_id]->id;
                $clonedLink->position = $link->position;
                $clonedLink->strength_mode = $link->strength_mode;
                $clonedLink->strength_fraction = $link->strength_fraction;
                $clonedLink->strength_level_id = $link->strength_level_id !== null
                    ? ($strengthLevelMap[$link->strength_level_id] ?? $link->strength_level_id)
                    : null;
                $clonedLink->reduction_target = $link->reduction_target;
                $clonedLink->rationale = $link->rationale;
                $clonedLink->setRelation('attack', $uuidMap[$link->attack_node_id]);
                $clonedLink->setRelation('defence', $uuidMap[$link->defence_node_id]);
                $clonedLink->save();
            });
    }

    /**
     * The map holds the cloned `Node`, not its uuid, so that it is the same
     * shape cloneNodes() returns and cloneNodeReferences() can consume either
     * (W78). Nothing ever read the uuid it used to store — it was a write-only
     * accumulator — so this narrows two maps to one rather than changing what
     * any caller sees.
     *
     * @param  array<int, Node>  $uuidMap  old node id => cloned Node
     */
    private function cloneSingleNode(Node $node, Assessment $target, array &$uuidMap): Node
    {
        $clone = $node->replicate(self::NOT_REPLICATED);
        $clone->assessment_id = $target->id;
        $clone->uuid = (string) Str::uuid();
        $clone->position = $this->nextLibraryPosition($target, $node->type);
        $clone->save();

        $uuidMap[$node->id] = $clone;

        return $clone;
    }

    /** @param  array<int, Node>  $uuidMap  old node id => cloned Node */
    private function cloneDefencesOfAttack(Attack $attack, Node $clonedAttack, Assessment $target, array &$uuidMap): void
    {
        foreach ($attack->defences as $defence) {
            $clonedDefence = $this->cloneSingleNode($defence, $target, $uuidMap);

            // Direct property assignment, not ::create(): AttackDefence's
            // $fillable deliberately excludes its own foreign keys.
            $clonedLink = new AttackDefence;
            $clonedLink->attack_node_id = $clonedAttack->id;
            $clonedLink->defence_node_id = $clonedDefence->id;
            $clonedLink->position = $defence->pivot->position;
            $clonedLink->strength_mode = $defence->pivot->strength_mode;
            $clonedLink->strength_fraction = $defence->pivot->strength_fraction;
            $clonedLink->strength_level_id = $defence->pivot->strength_level_id;
            $clonedLink->reduction_target = $defence->pivot->reduction_target;
            $clonedLink->rationale = $defence->pivot->rationale;
            $clonedLink->setRelation('attack', $clonedAttack);
            $clonedLink->setRelation('defence', $clonedDefence);
            $clonedLink->save();
        }
    }

    private function nextLibraryPosition(Assessment $assessment, string $type): int
    {
        $max = Node::withoutGlobalScopes()
            ->where('assessment_id', $assessment->id)
            ->where('type', $type)
            ->max('position');

        return $max === null ? 0 : (int) $max + 1;
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function resolveCatalogDefaults(string $kind, array $defaults, Assessment $target): array
    {
        return match ($kind) {
            'risk' => $this->resolveLevelDefault($defaults, $target->impact_scale_id, 'impact_mode', 'impact_amount', 'impact_level_id', 'impact_level_code'),
            'attack' => $this->resolveLevelDefault($defaults, $target->likelihood_scale_id, 'likelihood_mode', 'likelihood_aro', 'likelihood_level_id', 'likelihood_level_code'),
            default => array_intersect_key($defaults, array_flip(['is_transfer', 'status', 'capex_amount', 'opex_annual_amount'])),
        };
    }

    /** @param  array<string, mixed>  $defaults
     * @return array<string, mixed> */
    private function resolveLevelDefault(array $defaults, ?int $scaleId, string $modeKey, string $amountKey, string $levelIdKey, string $levelCodeKey): array
    {
        $result = [];

        if (isset($defaults[$modeKey])) {
            $result[$modeKey] = $defaults[$modeKey];
        }

        if (isset($defaults[$amountKey])) {
            $result[$amountKey] = $defaults[$amountKey];
        }

        if (isset($defaults[$levelCodeKey], $scaleId)) {
            $level = ScaleLevel::query()
                ->where('scale_id', $scaleId)
                ->where('code', $defaults[$levelCodeKey])
                ->first();

            // Per schema.md §3.14: if a code does not resolve against the
            // target assessment's calibration, leave it unset rather than
            // guessing a value.
            if ($level !== null) {
                $result[$levelIdKey] = $level->id;
            }
        }

        return $result;
    }
}
