<?php

namespace App\Domain\History;

use App\Models\Assessment;
use App\Models\Snapshot;
use Illuminate\Support\Carbon;

/**
 * The History page's shape since 2.5.1: versions first, changes inside them.
 *
 * Through 2.5 the page was one flat timeline of changes, and versions
 * (snapshots) lived on a separate page reachable only from a footnote at the
 * bottom of it. Now every saved version is a group, holding the changes made
 * between the version before it and itself — "what went into this version" —
 * and the changes made since the latest one sit on top, under **Current
 * version**, which is what a person saving a version by hand is about to
 * freeze.
 *
 * Timestamps decide membership: an entry belongs to the first version saved
 * at or after it. Both logs and `snapshots.created_at` are whole seconds, so
 * an edit in the same second as a save counts as part of that version — the
 * save reads the database after the edit committed, so that is also what the
 * version actually contains.
 *
 * Each group carries its total and, unless it is the one being expanded, only
 * its newest PER_GROUP entries: a version saved after fifty changes should
 * not put fifty rows on the page before the reader has asked to see them.
 */
final class VersionTimeline
{
    public const PER_GROUP = 10;

    public const CURRENT = 'current';

    public function __construct(private readonly TimelineReader $timeline) {}

    /**
     * @param  array{user_id?: int|null, action?: string|null, node_uuid?: string|null}  $filters
     * @return list<array{key: string, number: int|null, snapshot: array<string, mixed>|null, entries: list<array<string, mixed>>, total: int}>
     */
    public function build(Assessment $assessment, array $filters = [], ?string $expand = null): array
    {
        $snapshots = $assessment->snapshots()
            ->with('createdBy:id,name')
            // The relation orders newest first; numbering needs oldest first.
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values();

        // Oldest first for numbering; the page shows them newest first.
        $groups = [];
        foreach ($snapshots as $index => $snapshot) {
            $groups[] = [
                'key' => "v{$snapshot->id}",
                'number' => $index + 1,
                'snapshot' => $this->summary($snapshot),
                'at' => Carbon::parse($snapshot->getAttribute('created_at')),
                'entries' => [],
            ];
        }

        $current = [
            'key' => self::CURRENT,
            'number' => null,
            'snapshot' => null,
            'at' => null,
            'entries' => [],
        ];

        foreach ($this->timeline->entries($assessment, $filters) as $entry) {
            $at = Carbon::parse((string) $entry['created_at']);
            $placed = false;

            foreach ($groups as $i => $group) {
                if ($at->lte($group['at'])) {
                    $groups[$i]['entries'][] = $entry;
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $current['entries'][] = $entry;
            }
        }

        return collect([$current, ...array_reverse($groups)])
            ->map(function (array $group) use ($expand): array {
                $total = count($group['entries']);

                return [
                    'key' => $group['key'],
                    'number' => $group['number'],
                    'snapshot' => $group['snapshot'],
                    'entries' => $group['key'] === $expand
                        ? $group['entries']
                        : array_slice($group['entries'], 0, self::PER_GROUP),
                    'total' => $total,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Display name only for the author, never the address: the History page
     * is readable by everyone who can view the assessment.
     *
     * @return array<string, mixed>
     */
    private function summary(Snapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'name' => $snapshot->name,
            'note' => $snapshot->note,
            'kind' => $snapshot->getAttribute('kind') ?? 'manual',
            'schemaVersion' => $snapshot->schema_version,
            'createdBy' => $snapshot->createdBy?->name,
            'createdAt' => Carbon::parse($snapshot->getAttribute('created_at'))->toIso8601String(),
        ];
    }
}
