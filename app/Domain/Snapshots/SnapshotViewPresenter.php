<?php

namespace App\Domain\Snapshots;

use App\Models\Snapshot;
use RuntimeException;

/**
 * Turns a frozen graph into the props the live editor's views already take
 * (W63), so an old version can be *read* in the chart and the list rather than
 * only as a page of counts.
 *
 * ## Why a translation layer and not a second set of views
 *
 * The obvious alternative — a read-only chart of its own — is the one that goes
 * wrong slowly. Two renderers of the same graph drift: a tier gets a new
 * indicator, a transfer starts drawing differently, and the version people look
 * at when something has gone wrong is the one still drawing it the old way. The
 * frozen graph is the same shape as the live one, so the honest move is to feed
 * the same components and make the *data* the only difference.
 *
 * That is what this class is: `snapshots.graph` in, AssessmentController's prop
 * shape out. It deliberately mirrors `AssessmentController::nodeToArray()` key
 * for key. If that method gains a field, this one is wrong until it gains the
 * same field, and `tests/Feature/Snapshots/SnapshotViewTest.php` compares the
 * two key sets directly so the drift is caught rather than noticed.
 *
 * ## Never recomputes
 *
 * `results` is passed through from the `results` column exactly as stored. A
 * snapshot is frozen output — that is the entire reason the feature exists —
 * and recomputing a frozen graph against today's engine would produce a page
 * claiming to be a version of the past while showing a number nobody ever saw.
 *
 * ## What it cannot show, and says so
 *
 * Comments, presence and locks are live state and are not in a snapshot. The
 * page does not render them at all rather than rendering today's against an old
 * graph, which would attach a conversation to a version it did not happen on.
 */
final class SnapshotViewPresenter
{
    public function __construct(private readonly SnapshotGraphCodec $codec) {}

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException the snapshot was written by a schema version this code cannot read
     */
    public function present(Snapshot $snapshot, string $assessmentUuid, string $displayMode): array
    {
        $graph = $this->codec->decode($snapshot->graph);

        $version = (int) ($graph['schemaVersion'] ?? 0);

        if ($version < 1) {
            throw new SnapshotNotRestorableException("This snapshot was written by a version of ArgueSecure this build cannot read (schema v{$version}).");
        }

        /** @var array<string, array<string, mixed>> $frozenNodes */
        $frozenNodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];

        /** @var array<string, mixed> $frozenAssessment */
        $frozenAssessment = is_array($graph['assessment'] ?? null) ? $graph['assessment'] : [];

        $vocabulary = $this->vocabulary($frozenNodes);

        return [
            'assessment' => [
                'uuid' => $assessmentUuid,
                // The frozen currency and period, not today's. A restore puts
                // these back for exactly the same reason: `amortization_years`
                // divides every capital cost, so a frozen graph shown beside
                // today's divisor produces figures matching neither version.
                'currency' => (string) ($frozenAssessment['currency'] ?? 'EUR'),
                'amortization_years' => (int) ($frozenAssessment['amortizationYears'] ?? 1),
                // Not part of a snapshot's shape. `display_mode` is a rendering
                // preference rather than a property of the frozen work, so the
                // reader's own is honoured; the other two are gates on a live
                // assessment and mean nothing here, so they take the closed
                // value rather than a guess.
                'display_mode' => $displayMode,
                'risk_tolerance' => null,
                'is_public' => false,
            ],
            'nodes' => $this->nodes($frozenNodes, $vocabulary),
            'riskAttackLinks' => is_array($graph['riskAttackLinks'] ?? null) ? array_values($graph['riskAttackLinks']) : [],
            'attackDefenceLinks' => is_array($graph['attackDefenceLinks'] ?? null) ? array_values($graph['attackDefenceLinks']) : [],
            'scales' => is_array($graph['scales'] ?? null) ? array_values($graph['scales']) : [],
            'scaleLevels' => is_array($graph['scaleLevels'] ?? null) ? array_values($graph['scaleLevels']) : [],
            'tags' => array_values($vocabulary),
            // Straight from the column. Never recomputed — see the class note.
            'results' => $snapshot->results,
        ];
    }

    /**
     * Every label anywhere in the frozen graph, with an id minted here.
     *
     * A snapshot stores labels **by value** and keeps no `tags.id`, on purpose
     * (schema.md §3.13) — an id would let a later rename reach into a frozen
     * version. But the front end keys chips and filters by id, so one has to
     * exist for the render. These are assigned by position over the slugs the
     * snapshot actually contains, which makes them stable for a given snapshot
     * and meaningless outside it.
     *
     * That is safe **only** because nothing on this page writes. If a mutation
     * ever becomes reachable from the frozen view, one of these ids reaching a
     * `node_tag` row would attach an unrelated live label to a node. The view
     * route is read-only and the store it feeds is built frozen; both are
     * asserted.
     *
     * @param  array<string, array<string, mixed>>  $frozenNodes
     * @return array<string, array{id: int, name: string, slug: string, color: ?string}>
     */
    private function vocabulary(array $frozenNodes): array
    {
        $bySlug = [];

        foreach ($frozenNodes as $frozen) {
            /** @var list<array<string, mixed>> $tags */
            $tags = is_array($frozen['tags'] ?? null) ? $frozen['tags'] : [];

            foreach ($tags as $tag) {
                $slug = (string) ($tag['slug'] ?? '');

                if ($slug === '' || isset($bySlug[$slug])) {
                    continue;
                }

                $bySlug[$slug] = [
                    'id' => count($bySlug) + 1,
                    'name' => (string) ($tag['name'] ?? $slug),
                    'slug' => $slug,
                    'color' => isset($tag['color']) ? (string) $tag['color'] : null,
                ];
            }
        }

        return $bySlug;
    }

    /**
     * @param  array<string, array<string, mixed>>  $frozenNodes
     * @param  array<string, array{id: int, name: string, slug: string, color: ?string}>  $vocabulary
     * @return list<array<string, mixed>>
     */
    private function nodes(array $frozenNodes, array $vocabulary): array
    {
        $nodes = [];

        foreach ($frozenNodes as $uuid => $frozen) {
            $nodes[] = [
                'uuid' => (string) $uuid,
                'type' => (string) ($frozen['type'] ?? ''),
                'title' => (string) ($frozen['title'] ?? ''),
                'description' => isset($frozen['description']) ? (string) $frozen['description'] : null,
                'position' => (int) ($frozen['position'] ?? 0),
                'impact_mode' => $this->stringOrNull($frozen['impact_mode'] ?? null),
                'impact_amount' => $this->floatOrNull($frozen['impact_amount'] ?? null),
                'impact_level_id' => $this->intOrNull($frozen['impact_level_id'] ?? null),
                'likelihood_mode' => $this->stringOrNull($frozen['likelihood_mode'] ?? null),
                'likelihood_aro' => $this->floatOrNull($frozen['likelihood_aro'] ?? null),
                'likelihood_level_id' => $this->intOrNull($frozen['likelihood_level_id'] ?? null),
                'tags' => $this->tagsOf($frozen, $vocabulary),
                'is_transfer' => (bool) ($frozen['is_transfer'] ?? false),
                'status' => $this->stringOrNull($frozen['status'] ?? null),
                'capex_amount' => $this->floatOrNull($frozen['capex_amount'] ?? null),
                'opex_annual_amount' => $this->floatOrNull($frozen['opex_annual_amount'] ?? null),
                // A snapshot does not record it, and it exists on the live
                // record to drive optimistic-concurrency checks on save. There
                // is no save here, so null rather than the snapshot's own date:
                // a timestamp in this field would read as "this node was last
                // edited then", which is not what it would mean.
                'updated_at' => null,
            ];
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $frozen
     * @param  array<string, array{id: int, name: string, slug: string, color: ?string}>  $vocabulary
     * @return list<array{id: int, name: string, slug: string, color: ?string}>
     */
    private function tagsOf(array $frozen, array $vocabulary): array
    {
        /** @var list<array<string, mixed>> $tags */
        $tags = is_array($frozen['tags'] ?? null) ? $frozen['tags'] : [];

        $out = [];

        foreach ($tags as $tag) {
            $slug = (string) ($tag['slug'] ?? '');

            if (isset($vocabulary[$slug])) {
                $out[] = $vocabulary[$slug];
            }
        }

        return $out;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
