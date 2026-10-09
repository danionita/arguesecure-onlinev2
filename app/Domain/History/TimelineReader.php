<?php

namespace App\Domain\History;

use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\Node;
use App\Models\NodeRevision;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The assessment-wide history feed (W48, item 21; PLAN-2.2.md D15).
 *
 * ## Two logs, one timeline
 *
 * This codebase keeps history in two places, and the split is deliberate
 * (schema.md §3.9/§3.10): `node_revisions` records **field edits** — one row
 * per save, carrying every field that save changed — and
 * `assessment_operations` records **structural changes** — create, delete,
 * move, link, unlink, clone, catalogue insert — as entries on the undo stack.
 * Neither is a subset of the other and a reader wants both, in one column,
 * newest first. Merging them here rather than in the page is what keeps that
 * true for the JSON export and any later consumer.
 *
 * ## Grouping
 *
 * A structural operation is one entry by construction. Field edits group by
 * `operation_id` where they have one — and where they do not (the ordinary
 * case: `RevisionRecorder` writes `operation_id = null`, and its docblock
 * explains why), the row is already one user action, because every field
 * dirtied by one save lands in a single revision row. So "one user action
 * reads as one entry" needs no grouping machinery beyond folding the
 * `operation_id` groups together, which is exactly what this class does.
 *
 * ## Ordering across two tables
 *
 * Both are ordered by `created_at`, then by id within the same second. The two
 * id sequences are independent, so an id tiebreak across tables is meaningless
 * — a tie is broken by putting the structural entry first, on the reasoning
 * that a link created in the same second as the field edit that followed it
 * was the earlier act.
 */
class TimelineReader
{
    /** One screenful. The page paginates; a history that scrolls forever is a log file. */
    public const PER_PAGE = 25;

    /**
     * @param  array{user_id?: int|null, action?: string|null, node_uuid?: string|null}  $filters
     * @return array{entries: list<array<string, mixed>>, page: int, hasMore: bool, total: int}
     */
    public function read(Assessment $assessment, array $filters = [], int $page = 1): array
    {
        $page = max(1, $page);

        $entries = collect($this->sortedEntries($assessment, $filters));

        $total = $entries->count();
        $slice = $entries->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return [
            'entries' => $slice
                ->map(function (array $entry): array {
                    unset($entry['sort_key']);

                    return $entry;
                })
                ->values()
                ->all(),
            'page' => $page,
            'hasMore' => $total > $page * self::PER_PAGE,
            'total' => $total,
        ];
    }

    /**
     * Every entry, newest first, unpaginated — for the History page's grouping
     * by version (VersionTimeline, 2.5.1), which has to place each entry
     * against the versions' timestamps before it can decide what to show.
     *
     * @param  array{user_id?: int|null, action?: string|null, node_uuid?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function entries(Assessment $assessment, array $filters = []): array
    {
        return collect($this->sortedEntries($assessment, $filters))
            ->map(function (array $entry): array {
                unset($entry['sort_key']);

                return $entry;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{user_id?: int|null, action?: string|null, node_uuid?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function sortedEntries(Assessment $assessment, array $filters): array
    {
        return collect($this->operations($assessment, $filters))
            ->concat($this->revisions($assessment, $filters))
            ->sortByDesc(fn (array $entry): string => $entry['sort_key'])
            ->values()
            ->all();
    }

    /**
     * Everyone who has ever appeared in this assessment's history, for the
     * filter's dropdown.
     *
     * Read from the two logs rather than from the collaborator list: someone
     * who has since been removed still made the changes they made, and a
     * filter that cannot name them makes their entries unreachable.
     *
     * @return list<array{id: int, name: string}>
     */
    public function actors(Assessment $assessment): array
    {
        $ids = NodeRevision::query()
            ->where('assessment_id', $assessment->id)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->concat(
                AssessmentOperation::query()
                    ->where('assessment_id', $assessment->id)
                    ->whereNotNull('user_id')
                    ->distinct()
                    ->pluck('user_id')
            )
            ->unique()
            ->all();

        return User::query()
            ->select(['id', 'name'])
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            // Display name only. A history page is readable by everyone who
            // can view the assessment, and an address has no business in it.
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function operations(Assessment $assessment, array $filters): array
    {
        // A structural entry names no single node — a clone or a catalogue
        // insert touches several — so a node filter excludes the whole log
        // rather than guessing which entries belong to that node.
        if (($filters['node_uuid'] ?? null) !== null) {
            return [];
        }

        $query = AssessmentOperation::query()
            ->with('user:id,name')
            ->where('assessment_id', $assessment->id);

        if (($filters['user_id'] ?? null) !== null) {
            $query->where('user_id', $filters['user_id']);
        }

        if (($filters['action'] ?? null) !== null) {
            $query->where('kind', $filters['action']);
        }

        return $query->latest('created_at')->get()
            ->map(fn (AssessmentOperation $operation): array => [
                'sort_key' => $this->sortKey($operation->created_at?->format('Y-m-d H:i:s'), 0),
                'id' => "op-{$operation->id}",
                'source' => 'operation',
                'action' => $operation->kind,
                'summary' => $operation->summary,
                'user' => $operation->user?->name,
                'created_at' => $operation->created_at?->toJSON(),
                'node_uuid' => null,
                'undone' => $operation->undone_at !== null,
                'changes' => [],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function revisions(Assessment $assessment, array $filters): array
    {
        if (($filters['action'] ?? null) !== null && ! str_starts_with((string) $filters['action'], 'field.')) {
            return [];
        }

        $query = NodeRevision::query()
            ->with('user:id,name')
            ->where('assessment_id', $assessment->id);

        if (($filters['user_id'] ?? null) !== null) {
            $query->where('user_id', $filters['user_id']);
        }

        if (($filters['node_uuid'] ?? null) !== null) {
            $query->where('node_uuid', $filters['node_uuid']);
        }

        $revisions = $query->latest('created_at')->latest('id')->get();

        $titles = $this->titlesFor($revisions->pluck('node_uuid')->unique()->all());

        return $revisions
            // Fold the grouped ones together: several revisions sharing an
            // operation_id were one user action and read as one entry.
            ->groupBy(fn (NodeRevision $revision): string => $revision->operation_id === null
                ? "r{$revision->id}"
                : "o{$revision->operation_id}")
            ->map(function (Collection $group) use ($titles): array {
                /** @var NodeRevision $first */
                $first = $group->first();

                $changes = [];
                foreach ($group as $revision) {
                    foreach ($this->fieldsOf($revision) as $field => $change) {
                        $changes[] = [
                            'node_uuid' => $revision->node_uuid,
                            'node_title' => $titles[$revision->node_uuid] ?? null,
                            'field' => $field,
                            'from' => is_array($change) ? ($change['from'] ?? null) : null,
                            'to' => is_array($change) ? ($change['to'] ?? null) : null,
                        ];
                    }
                }

                return [
                    'sort_key' => $this->sortKey($first->created_at?->format('Y-m-d H:i:s'), 1),
                    'id' => "rev-{$first->id}",
                    'source' => 'revision',
                    'action' => 'field.'.$first->action,
                    'summary' => $this->summarise($changes, $this->subjectOf($first, $titles)),
                    'user' => $first->user?->name,
                    'created_at' => $first->created_at?->toJSON(),
                    'node_uuid' => $first->node_uuid,
                    'undone' => false,
                    'changes' => $changes,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Titles for the nodes named in these revisions.
     *
     * `withTrashed`, and keyed by uuid rather than id: `node_revisions.
     * node_uuid` is denormalised precisely so history survives a purge
     * (schema.md §3.9), and a deleted node's entries should still say what
     * they were about. A uuid with no row left at all falls back to null and
     * the entry names the change without the title, which is the honest
     * rendering.
     *
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    private function titlesFor(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return Node::query()
            ->withTrashed()
            ->whereIn('uuid', $uuids)
            ->pluck('title', 'uuid')
            ->all();
    }

    /**
     * The `{field: {from, to}}` pairs inside one revision, whatever shape it
     * was written in.
     *
     * A `link_update` (W56) carries the shape schema.md §3.9 reserves for link
     * actions — `{"other_uuid":…, "other_title":…, "pivot": {…}}` — so its
     * fields are one level down, and the counterpart's title is folded into
     * the field name. "strength_fraction on MFA" reads as what it is; a bare
     * "strength_fraction" on an attack with four defences names no number in
     * particular.
     *
     * @return array<string, mixed>
     */
    private function fieldsOf(NodeRevision $revision): array
    {
        /** @var array<string, mixed> $changes */
        $changes = (array) $revision->changes;

        // An assessment-settings revision (W60) hangs off the root node and
        // keeps its fields one level down under `fields`, beside a `scope`
        // marker — the same shape link actions use, and for the same reason:
        // a setting called `title` could otherwise not be told apart from the
        // node column of that name.
        if ($this->isAssessmentScoped($revision)) {
            /** @var array<string, mixed> $fields */
            $fields = is_array($changes['fields'] ?? null) ? $changes['fields'] : [];

            return $fields;
        }

        if ($revision->action !== 'link_update') {
            return $changes;
        }

        /** @var array<string, mixed> $pivot */
        $pivot = is_array($changes['pivot'] ?? null) ? $changes['pivot'] : [];
        $other = is_string($changes['other_title'] ?? null) ? $changes['other_title'] : 'a defence';

        $labelled = [];

        foreach ($pivot as $field => $change) {
            $labelled["{$field} on {$other}"] = $change;
        }

        return $labelled;
    }

    /** Whether this revision is about the assessment rather than its node (W60). */
    private function isAssessmentScoped(NodeRevision $revision): bool
    {
        /** @var array<string, mixed> $changes */
        $changes = (array) $revision->changes;

        return ($changes['scope'] ?? null) === RevisionRecorder::ASSESSMENT_SCOPE;
    }

    /**
     * What the summary line says the change was made to.
     *
     * A settings revision hangs off the root node, but "Changed currency on
     * Payment platform" reads as an edit to a node — and a reader hunting for
     * why every annual figure moved needs to see that it was the assessment.
     *
     * @param  array<string, string>  $titles
     */
    private function subjectOf(NodeRevision $revision, array $titles): string
    {
        if ($this->isAssessmentScoped($revision)) {
            return 'the assessment';
        }

        return $titles[$revision->node_uuid] ?? 'a node';
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    private function summarise(array $changes, string $subject): string
    {
        $count = count($changes);

        if ($count === 1) {
            return "Changed {$changes[0]['field']} on {$subject}";
        }

        return "Changed {$count} fields on {$subject}";
    }

    /**
     * A sortable string across two tables with independent id sequences.
     *
     * The trailing digit is the tiebreak within the same second: structural
     * first (0), field edits after (1). Descending sort then puts the field
     * edit above the structural entry it followed, which is the order they
     * happened in.
     */
    private function sortKey(?string $timestamp, int $rank): string
    {
        return ($timestamp ?? '0000-00-00 00:00:00').'-'.$rank;
    }
}
