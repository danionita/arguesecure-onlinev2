<?php

namespace App\Domain\Snapshots;

use App\Models\Snapshot;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Diffs two snapshots (schema.md §3.13, PLAN.md Phase 8 item 3): "Diffing
 * two snapshots is pure PHP: match nodes by uuid, produce added/removed/
 * changed field sets and added/removed edges, and diff the results totals.
 * Snapshot reports never recompute."
 *
 * Everything here reads the already-frozen `graph`/`results` columns —
 * never RiskCalculator, never AssessmentGraphLoader, never a live query
 * against the assessment's current nodes. Diffing two five-year-old
 * snapshots must produce the same answer today as it did the day both were
 * taken.
 */
final class SnapshotDiffer
{
    public function __construct(private readonly SnapshotGraphCodec $codec) {}

    /**
     * @return array<string, mixed>
     */
    public function diff(Snapshot $from, Snapshot $to): array
    {
        $fromGraph = $this->codec->decode($from->graph);
        $toGraph = $this->codec->decode($to->graph);

        return [
            'from' => $this->snapshotSummary($from),
            'to' => $this->snapshotSummary($to),
            'nodes' => $this->diffNodes($fromGraph['nodes'] ?? [], $toGraph['nodes'] ?? []),
            'riskAttackLinks' => $this->diffEdges(
                $fromGraph['riskAttackLinks'] ?? [],
                $toGraph['riskAttackLinks'] ?? [],
                fn (array $l): string => $l['risk_uuid'].'|'.$l['attack_uuid'],
                ['position'],
            ),
            'attackDefenceLinks' => $this->diffEdges(
                $fromGraph['attackDefenceLinks'] ?? [],
                $toGraph['attackDefenceLinks'] ?? [],
                fn (array $l): string => $l['attack_uuid'].'|'.$l['defence_uuid'],
                ['position', 'strength_mode', 'strength_fraction', 'strength_level_id', 'reduction_target', 'rationale'],
            ),
            'totals' => $this->diffTotals($this->resultsArray($from), $this->resultsArray($to)),
        ];
    }

    /**
     * @return array{id: int, name: string, createdAt: ?string}
     */
    private function snapshotSummary(Snapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'name' => $snapshot->name,
            'createdAt' => $this->isoDate($snapshot->getAttribute('created_at')),
        ];
    }

    /**
     * `results` is cast to `array` on the model (Snapshot::casts()), but
     * that Laravel-11-style method-based cast declaration isn't picked up
     * by static analysis here (`parseModelCastsMethod` defaults to false
     * in vendor/larastan/larastan/extension.neon, a file this package does
     * not own), so ->results reads as `string` to PHPStan even though it
     * is already an array at runtime. getAttribute() (untyped, `mixed`)
     * plus this runtime check sidesteps that without touching
     * app/Models/** — the same pattern UndoService::inverseOf() and
     * UndoController::toJson() already use for AssessmentOperation, which
     * has the identical `$timestamps = false` + method-cast() shape.
     *
     * @return array<string, mixed>
     */
    private function resultsArray(Snapshot $snapshot): array
    {
        $results = $snapshot->getAttribute('results');

        if (is_array($results)) {
            return $results;
        }

        $decoded = is_string($results) ? json_decode($results, associative: true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? Carbon::instance($value)->toIso8601String()
            : Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * @param  array<string, array<string, mixed>>  $fromNodes
     * @param  array<string, array<string, mixed>>  $toNodes
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}
     */
    private function diffNodes(array $fromNodes, array $toNodes): array
    {
        $added = [];
        $removed = [];
        $changed = [];

        $allUuids = array_unique([...array_keys($fromNodes), ...array_keys($toNodes)]);

        foreach ($allUuids as $uuid) {
            $before = $fromNodes[$uuid] ?? null;
            $after = $toNodes[$uuid] ?? null;

            if ($before === null && $after !== null) {
                $added[] = ['uuid' => $uuid, 'type' => $after['type'] ?? null, 'title' => $after['title'] ?? null];

                continue;
            }

            if ($before !== null && $after === null) {
                $removed[] = ['uuid' => $uuid, 'type' => $before['type'] ?? null, 'title' => $before['title'] ?? null];

                continue;
            }

            $fields = $this->diffFields($before, $after, exclude: ['uuid']);

            if ($fields !== []) {
                $changed[] = [
                    'uuid' => $uuid,
                    'type' => $after['type'] ?? $before['type'] ?? null,
                    'title' => $after['title'] ?? $before['title'] ?? null,
                    'fields' => $fields,
                ];
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * @param  list<array<string, mixed>>  $fromEdges
     * @param  list<array<string, mixed>>  $toEdges
     * @param  callable(array<string, mixed>): string  $keyOf
     * @param  list<string>  $watchFields
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}
     */
    private function diffEdges(array $fromEdges, array $toEdges, callable $keyOf, array $watchFields): array
    {
        $fromByKey = [];
        foreach ($fromEdges as $edge) {
            $fromByKey[$keyOf($edge)] = $edge;
        }

        $toByKey = [];
        foreach ($toEdges as $edge) {
            $toByKey[$keyOf($edge)] = $edge;
        }

        $added = [];
        $removed = [];
        $changed = [];

        $allKeys = array_unique([...array_keys($fromByKey), ...array_keys($toByKey)]);

        foreach ($allKeys as $key) {
            $before = $fromByKey[$key] ?? null;
            $after = $toByKey[$key] ?? null;

            if ($before === null && $after !== null) {
                $added[] = $after;

                continue;
            }

            if ($before !== null && $after === null) {
                $removed[] = $before;

                continue;
            }

            $fields = $this->diffFields($before, $after, only: $watchFields);

            if ($fields !== []) {
                $changed[] = ['edge' => $after, 'fields' => $fields];
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $exclude
     * @param  ?list<string>  $only
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function diffFields(array $before, array $after, array $exclude = [], ?array $only = null): array
    {
        $keys = $only ?? array_unique([...array_keys($before), ...array_keys($after)]);
        $out = [];

        foreach ($keys as $key) {
            if (in_array($key, $exclude, true)) {
                continue;
            }

            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;

            if ($beforeValue !== $afterValue) {
                $out[] = ['field' => $key, 'from' => $beforeValue, 'to' => $afterValue];
            }
        }

        return $out;
    }

    /**
     * The movement in each headline figure (PLAN.md Phase 8 item 3),
     * read straight off the two snapshots' frozen `results` columns —
     * never recomputed.
     *
     * @param  array<string, mixed>  $fromResults
     * @param  array<string, mixed>  $toResults
     * @return array<string, array{from: ?float, to: ?float, delta: ?float}>
     */
    private function diffTotals(array $fromResults, array $toResults): array
    {
        $out = [];

        foreach (['inherent', 'current', 'target'] as $baseline) {
            $fromTotal = $fromResults[$baseline]['total'] ?? null;
            $toTotal = $toResults[$baseline]['total'] ?? null;

            $out[$baseline] = [
                'from' => $fromTotal,
                'to' => $toTotal,
                'delta' => ($fromTotal !== null && $toTotal !== null) ? $toTotal - $fromTotal : null,
            ];
        }

        return $out;
    }
}
