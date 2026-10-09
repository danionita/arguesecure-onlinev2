<?php

namespace App\Domain\Snapshots;

use App\Domain\Calculation\CalculationResults;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraph;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\Snapshot;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Freezes a named version of an assessment (schema.md §3.13, PLAN.md Phase 8
 * item 3): the graph AND the computed results, with the scales inlined so a
 * later recalibration cannot retroactively rewrite a report already sent to
 * a client (the entire reason this package exists — CLAUDE.md's W19 brief).
 *
 * **Never reimplements any part of the engine.** This class only calls
 * AssessmentGraphLoader::load() and RiskCalculator::compute() — exactly the
 * same two calls ReportController makes — and then serializes their output
 * byte-for-byte. Nothing here re-derives a figure.
 *
 * The "inlining" property is structural, not a special case: AssessmentGraph
 * already carries every scale level's full row (code, bounds, representative
 * — see app/Domain/Graph/AssessmentGraph.php's ScaleLevelData shape) rather
 * than a foreign key into `scale_levels`, so serializing the graph as-is
 * already captures the calibration by value. Mutating a `scale_levels` row
 * after the fact cannot reach into an already-encoded snapshot — there is no
 * id reference left to follow.
 */
final class SnapshotService
{
    /**
     * Bumped to 2 by W62, which added `tags` and `assignees` to every node.
     *
     * The number exists so a reader can tell what it is holding rather than
     * guess (schema.md §3.13). A v1 snapshot is still valid, still readable
     * and still restorable — it simply has no labels in it, and everything
     * downstream is written to ask which version it is looking at instead of
     * assuming the newest shape. Nothing rewrites an existing snapshot: a
     * frozen version that changed shape after the fact would not be frozen.
     */
    private const SCHEMA_VERSION = 2;

    public function __construct(
        private readonly AssessmentGraphLoader $loader,
        private readonly RiskCalculator $calculator,
        private readonly SnapshotGraphCodec $codec,
    ) {}

    /**
     * @param  'manual'|'auto'|'safety'  $kind  how the version came to exist (2.5.1)
     */
    public function create(Assessment $assessment, string $name, ?string $note, ?int $createdByUserId, string $kind = 'manual'): Snapshot
    {
        $graph = $this->loader->load($assessment);
        $results = $this->calculator->compute($graph);
        $nodesByUuid = $this->loadNodes($assessment);

        $canonicalGraph = $this->buildCanonicalGraph($graph, $nodesByUuid);

        return Snapshot::create([
            'assessment_id' => $assessment->id,
            'name' => $name,
            'note' => $note,
            'kind' => $kind,
            'created_by' => $createdByUserId,
            'graph' => $this->codec->encode($canonicalGraph),
            'results' => $this->resultsToArray($results),
            'schema_version' => self::SCHEMA_VERSION,
            'created_at' => now(),
        ]);
    }

    /**
     * Eager-loaded, both of them: without the `with()` this is an N+1 by
     * construction over every node in the assessment, which is the exact
     * defect CLAUDE.md names in the legacy tree render.
     *
     * `assignees` is also narrowed to `id` and `name` here, but that is a
     * second line and **not** the thing keeping emails out of a snapshot —
     * assigneesOf() names the two keys it writes, and widening this select
     * changes nothing on its own. Verified by mutation: dropping the narrowing
     * leaves the suite green, widening the serializer turns it red. It stays
     * because a column not loaded cannot be serialized by a later edit that
     * forgets why, and because there is no reason to read it.
     *
     * @return Collection<string, Node>
     */
    private function loadNodes(Assessment $assessment): Collection
    {
        return Node::query()
            ->with(['tags', 'assignees:id,name'])
            ->where('assessment_id', $assessment->id)
            ->orderBy('position')
            ->get()
            ->keyBy('uuid');
    }

    /**
     * The canonical graph structure (schema.md §3.13): "nodes keyed by uuid,
     * both pivots, scales inlined." Descriptive columns AssessmentGraph
     * intentionally excludes (title beyond what the engine needs,
     * description, position) are merged back in from the Node model
     * here, the same way AssessmentJsonExporter does for the JSON export —
     * a snapshot is meant to be readable years later as a report, not just
     * as engine input.
     *
     * @param  Collection<string, Node>  $nodesByUuid
     * @return array<string, mixed>
     */
    private function buildCanonicalGraph(AssessmentGraph $graph, Collection $nodesByUuid): array
    {
        $nodes = [];

        foreach ($nodesByUuid as $uuid => $node) {
            $entry = [
                'uuid' => $uuid,
                'type' => $node->type,
                'title' => $node->title,
                'description' => $node->description,
                'position' => $node->position,
                'tags' => $this->tagsOf($node),
                'assignees' => $this->assigneesOf($node),
            ];

            $entry += match ($node->type) {
                'risk' => $graph->risks[$uuid] ?? [],
                'attack' => $graph->attacks[$uuid] ?? [],
                'defence' => $graph->defences[$uuid] ?? [],
                default => [],
            };

            $nodes[$uuid] = $entry;
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'assessment' => [
                'uuid' => $graph->assessmentUuid,
                'currency' => $graph->currency,
                'amortizationYears' => $graph->amortizationYears,
            ],
            'root' => [
                'title' => $graph->rootTitle,
                'description' => $graph->rootDescription,
            ],
            'nodes' => $nodes,
            'riskAttackLinks' => $graph->riskAttackLinks,
            'attackDefenceLinks' => $graph->attackDefenceLinks,
            'scales' => array_values($graph->scales),
            'scaleLevels' => array_values($graph->scaleLevels),
        ];
    }

    /**
     * A node's labels, **by value** (W62).
     *
     * By value and not by id, for the same reason the scales are inlined one
     * method above: a `tags` row can be renamed, recoloured or deleted after
     * this snapshot is taken, and an id left pointing into that table would
     * make a frozen version quietly follow it. `slug` is what a restore
     * matches on — it is the assessment-scoped stable name (schema.md §3.11)
     * — and `name` and `color` ride along so a reader of this snapshot sees
     * the label as it looked, not as it looks now.
     *
     * Sorted by slug so two snapshots of an unchanged assessment encode
     * identically. SnapshotDiffer compares decoded structures, and a set with
     * no order reports itself as changed every time it is re-frozen.
     *
     * @return list<array{name: string, slug: string, color: ?string}>
     */
    private function tagsOf(Node $node): array
    {
        return $node->tags
            ->map(fn (Tag $tag): array => [
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ])
            ->sortBy('slug')
            ->values()
            ->all();
    }

    /**
     * Who was responsible for a node when this was frozen (W62).
     *
     * The id is what a restore re-attaches by, because a person is their
     * account and not their name; the name is what lets this snapshot still
     * say who it was once that account is gone. Deliberately no email — see
     * loadNodes() for why a snapshot is the wrong place to put one.
     *
     * Already ordered by name: Node::assignees() orders at the relation, for
     * the same encode-identically reason as the labels above.
     *
     * @return list<array{id: int, name: string}>
     */
    private function assigneesOf(Node $node): array
    {
        return $node->assignees
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /**
     * Generic readonly-DTO -> array converter for CalculationResults, kept
     * as this package's own private copy rather than a shared helper — this
     * package owns no file the equivalent AssessmentJsonExporter copy lives
     * in, and that class documents the same reasoning for its own copy.
     *
     * @return array<string, mixed>
     */
    private function resultsToArray(CalculationResults $results): array
    {
        return [
            'inherent' => $this->toArray($results->inherent),
            'current' => $this->toArray($results->current),
            'target' => $this->toArray($results->target),
        ];
    }

    private function toArray(mixed $value): mixed
    {
        if (is_object($value)) {
            return array_map([$this, 'toArray'], get_object_vars($value));
        }

        if (is_array($value)) {
            return array_map([$this, 'toArray'], $value);
        }

        return $value;
    }
}
