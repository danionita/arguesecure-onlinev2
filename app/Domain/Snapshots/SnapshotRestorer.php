<?php

namespace App\Domain\Snapshots;

use App\Domain\History\RevisionRecorder;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\RiskAttack;
use App\Models\ScaleLevel;
use App\Models\Snapshot;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Puts a frozen version back (W61, closing the last of W48's audit findings).
 *
 * W19 could create, read and diff a snapshot, and nothing could apply one.
 * That is the reason "revert to here" was never built: PLAN-2.2.md D15's own
 * fallback was "offer revert only back to the nearest snapshot", and there was
 * no path for a revert to go through. This is that path.
 *
 * ## In place, not as a copy
 *
 * The assessment keeps its uuid, its collaborators, its share links, its
 * comments and its history. Restoring into a *clone* would have been easier to
 * implement and wrong to use: every link anyone has shared, every notification
 * anyone has been sent and every report already handed to a client points at
 * this assessment, and a restore that quietly moves the work somewhere else
 * leaves all of them pointing at the version nobody wanted.
 *
 * ## Nothing is destroyed without a way back
 *
 * A safety snapshot of the *current* state is taken before anything is
 * touched, in the same transaction, so a restore is itself restorable and a
 * misclick costs one more click rather than the assessment. Nodes the snapshot
 * does not contain are **soft**-deleted, so their comments and revisions go
 * with them rather than being orphaned.
 *
 * ## Matched by uuid, never rebuilt
 *
 * `uuid` is the stable identity across clones and snapshots (schema.md §3.4),
 * so a node the snapshot and the assessment share is *updated*, not deleted
 * and recreated. That is what lets its comments — which a snapshot still does
 * not record — survive a restore on every node that still exists.
 *
 * ## Labels and assignees, from v2 onward (W62)
 *
 * A v1 snapshot holds no labels and no assignees, which made every restore
 * partly incomplete: a node this brought back came back bare, and the count
 * of them was reported because there was nothing better to do about it.
 * `snapshots.graph` v2 carries both, so from a v2 snapshot they are restored
 * like anything else — **replaced, not merged**, because a restore is not a
 * merge. Both readings still work, and which one applies is decided by the
 * snapshot's own `schemaVersion` rather than assumed.
 *
 * ## Three ways a restore is incomplete, all counted and reported
 *
 * - **`unlabelled`** — v1 only. That shape holds no labels or assignees, so a
 *   node this restore brings back comes back bare, because there is nowhere
 *   to read them from. Zero for a v2 snapshot.
 * - **`unassignable`** — a frozen assignment naming someone who has since
 *   lost access to this assessment, or whose account is gone. Dropped and
 *   counted; see reattach() for why putting it back would undo a guard.
 * - **`unrestorableLevels`** — a frozen node can name a `scale_levels` row
 *   that has since been rebuilt. The id is dropped and the *mode* kept, which
 *   is the legal "qualitative, nothing chosen yet" state the schema already
 *   allows (`chk_nodes_impact_mode`) and the engine already treats as missing
 *   (calculation-engine.md §6). Coercing it to whichever level happens to hold
 *   that id today would invent a number.
 *
 * ## One timeline entry, not one per node
 *
 * Every node write fires NodeObserver, which writes a revision. Restoring
 * fifty nodes would be fifty timeline entries for one click. So the operation
 * row is recorded **first** and every revision is written inside
 * `RevisionRecorder::withinOperation()`, which stamps them with its id —
 * TimelineReader has folded revisions sharing an `operation_id` into one entry
 * since W48, and until now nothing ever produced any.
 */
final class SnapshotRestorer
{
    /**
     * Every `schemaVersion` this code knows how to apply.
     *
     * A list rather than a ceiling, deliberately. `>= 1` would read as "this
     * is fine with anything from the past", which is only true while nothing
     * has ever been *removed* from the shape — and the moment something is,
     * a silently-partial restore is exactly the "looks restored and is
     * quietly wrong" failure the W48 audit was run to prevent. Adding a
     * version here should mean someone has read what changed.
     *
     * @var list<int>
     */
    private const READABLE_VERSIONS = [1, 2];

    /**
     * The first version that carries labels and assignees (W62).
     *
     * Below this, a restore has nowhere to read them from and says so; at or
     * above it, the frozen set is authoritative and is applied.
     */
    private const LABELS_FROM_VERSION = 2;

    public function __construct(
        private readonly SnapshotGraphCodec $codec,
        private readonly SnapshotService $snapshots,
        private readonly RevisionRecorder $revisions,
    ) {}

    /**
     * @throws SnapshotNotRestorableException the snapshot belongs to another assessment, or was written by a schema version this code cannot read
     */
    public function restore(Assessment $assessment, Snapshot $snapshot, ?User $actor, ?int $operationId): RestoreOutcome
    {
        if ($snapshot->assessment_id !== $assessment->id) {
            throw new SnapshotNotRestorableException('That snapshot belongs to a different assessment.');
        }

        $graph = $this->codec->decode($snapshot->graph);

        $version = (int) ($graph['schemaVersion'] ?? 0);

        if (! in_array($version, self::READABLE_VERSIONS, true)) {
            // Forward-compatible readers are the reason `schema_version`
            // exists (schema.md §3.13). Refusing a version this code does not
            // know is the point of it: a partial restore of a shape it had
            // guessed at would be exactly the "looks restored and is quietly
            // wrong" failure the whole audit exists to prevent.
            throw new SnapshotNotRestorableException("This snapshot was written by a newer version of ArgueSecure (schema v{$version}) and cannot be restored here.");
        }

        return $this->revisions->withinOperation(
            $operationId,
            fn (): RestoreOutcome => $this->apply($assessment, $graph, $actor, $version),
        );
    }

    /**
     * The safety snapshot, taken before anything is touched.
     *
     * Called by the same caller, in the same transaction, rather than from
     * restore(): it has to capture the state the restore is about to
     * overwrite, and its id is what the operation's inverse points at — which
     * means it must exist before the operation row is written.
     */
    public function safetySnapshot(Assessment $assessment, Snapshot $restoring, ?User $actor): Snapshot
    {
        return $this->snapshots->create(
            assessment: $assessment,
            // `name` is varchar(120) and a user-chosen one can use all of it,
            // so the prefix is what gets to be long and the name is what gets
            // cut.
            name: mb_substr('Before restoring '.$restoring->name, 0, 120),
            note: 'Taken automatically so the restore could be undone.',
            createdByUserId: $actor?->id,
            kind: 'safety',
        );
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    private function apply(Assessment $assessment, array $graph, ?User $actor, int $version): RestoreOutcome
    {
        $carriesLabels = $version >= self::LABELS_FROM_VERSION;
        /** @var array<string, array<string, mixed>> $frozenNodes */
        $frozenNodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];

        // withTrashed: a node deleted since the snapshot was taken comes back
        // by restoring its row rather than by inserting a second one, which
        // would collide on `nodes.uuid` and would orphan its comments.
        /** @var Collection<string, Node> $existing */
        $existing = Node::query()
            ->withTrashed()
            ->where('assessment_id', $assessment->id)
            ->get()
            ->keyBy('uuid');

        $liveLevelIds = array_flip(array_map(
            'intval',
            ScaleLevel::query()->pluck('id')->all(),
        ));

        // Resolved once, outside the loop. Both are lookups every node needs
        // and neither changes during a restore, so reading them per node
        // would be the same N+1 the snapshot side was just fixed for.
        $tagIds = $carriesLabels ? $this->tagVocabulary($assessment) : [];
        $assignable = $carriesLabels ? $this->assignableUserIds($assessment) : [];

        $created = 0;
        $updated = 0;
        $unlabelled = 0;
        $unassignable = 0;
        $unrestorableLevels = 0;

        foreach ($frozenNodes as $uuid => $frozen) {
            $type = (string) ($frozen['type'] ?? '');

            if ($type === 'root') {
                // The root carries the assessment's title and description and
                // is never created or deleted here: exactly one per
                // assessment, held by a generated column (schema.md §3.2).
                $root = $existing->get((string) $uuid);

                if ($root !== null) {
                    $this->fill($root, $frozen, $liveLevelIds, $unrestorableLevels);
                    $updated += $this->save($root, $actor);

                    if ($carriesLabels) {
                        $this->reattach($root, $frozen, $assessment, $tagIds, $assignable, $unassignable);
                    }
                }

                continue;
            }

            $node = $existing->get((string) $uuid);

            if ($node === null) {
                $node = $this->make($assessment, (string) $uuid, $type, $actor);
                $this->fill($node, $frozen, $liveLevelIds, $unrestorableLevels);
                $node->updated_by = $actor?->id;
                $node->save();
                $created++;

                if ($carriesLabels) {
                    $this->reattach($node, $frozen, $assessment, $tagIds, $assignable, $unassignable);
                } else {
                    $unlabelled++;
                }

                continue;
            }

            if ($node->trashed()) {
                $node->restore();

                if (! $carriesLabels) {
                    // Its labels went with it: `node_tag` rows are removed on
                    // delete and a v1 snapshot has none to put back.
                    $unlabelled++;
                }
            }

            $this->fill($node, $frozen, $liveLevelIds, $unrestorableLevels);
            $updated += $this->save($node, $actor);

            if ($carriesLabels) {
                $this->reattach($node, $frozen, $assessment, $tagIds, $assignable, $unassignable);
            }
        }

        $deleted = $this->deleteAbsent($existing, $frozenNodes);

        // Rebuilt wholesale rather than diffed: an edge carries no identity
        // beyond the pair it joins, so "the same edge with a different
        // strength" and "a different edge" are indistinguishable from outside.
        // Delete-and-reinsert is the only reading that cannot leave behind an
        // edge the snapshot does not contain.
        $this->rebuildEdges($assessment, $graph, $liveLevelIds, $unrestorableLevels);

        $this->restoreSettings($assessment, $graph, $actor);

        return new RestoreOutcome(
            safetySnapshotId: 0,
            safetySnapshotName: '',
            created: $created,
            updated: $updated,
            deleted: $deleted,
            unrestorableLevels: $unrestorableLevels,
            unlabelled: $unlabelled,
            unassignable: $unassignable,
        );
    }

    /**
     * Puts a node's labels and assignees back (W62).
     *
     * `sync()`, not attach: a restore replaces, so a label added to this node
     * after the snapshot was taken has to come off it. That is the same
     * reading the graph itself gets — a restore is not a merge, and a node
     * that kept a label nobody in the frozen version had chosen would be a
     * node the restore only partly reached.
     *
     * @param  array<string, mixed>  $frozen
     * @param  array<string, int>  $tagIds  slug => id, seeded and extended by tagVocabulary()
     * @param  array<int, true>  $assignable  the set of user ids allowed to hold an assignment here
     */
    private function reattach(
        Node $node,
        array $frozen,
        Assessment $assessment,
        array &$tagIds,
        array $assignable,
        int &$unassignable,
    ): void {
        /** @var list<array<string, mixed>> $frozenTags */
        $frozenTags = is_array($frozen['tags'] ?? null) ? $frozen['tags'] : [];

        $ids = [];

        foreach ($frozenTags as $tag) {
            $slug = (string) ($tag['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            $ids[] = $tagIds[$slug] ??= $this->makeTag($assessment, $tag, $slug);
        }

        $node->tags()->sync($ids);

        /** @var list<array<string, mixed>> $frozenAssignees */
        $frozenAssignees = is_array($frozen['assignees'] ?? null) ? $frozen['assignees'] : [];

        $userIds = [];

        foreach ($frozenAssignees as $assignee) {
            $id = (int) ($assignee['id'] ?? 0);

            // The guard, and the reason this method takes `$assignable` at
            // all. An assignment grants nothing on its own, but it is written
            // into the dashboard, the report and every notification — and
            // AssessmentUser's `deleted` hook exists precisely so that losing
            // access removes it. Re-attaching a frozen assignment without
            // re-checking would put back, in one click, every assignment that
            // hook was written to take away: an assessment naming someone who
            // was removed from it, on a node they cannot open. Counted rather
            // than silently dropped, because the person restoring is often
            // the person who removed them and needs to see it happen.
            if (! isset($assignable[$id])) {
                $unassignable++;

                continue;
            }

            $userIds[] = $id;
        }

        $node->assignees()->sync($userIds);
    }

    /**
     * Every label this assessment already has, keyed by the slug a snapshot
     * matches on (schema.md §3.11 — slugs are unique per assessment).
     *
     * @return array<string, int>
     */
    private function tagVocabulary(Assessment $assessment): array
    {
        /** @var array<string, int> $ids */
        $ids = Tag::query()
            ->where('assessment_id', $assessment->id)
            ->pluck('id', 'slug')
            ->all();

        return $ids;
    }

    /**
     * A label the snapshot has and the assessment no longer does.
     *
     * Created rather than skipped: the slug is gone from the vocabulary
     * because someone deleted it, and a restore that puts the nodes back
     * without the label they were filed under has restored the graph and lost
     * the filing. `name` and `color` come from the snapshot, so it comes back
     * looking as it did.
     *
     * `assessment_id` is assigned as a **property**. It is an identity column
     * and is deliberately absent from Tag::$fillable — a create() carrying one
     * is dropped silently by Eloquent and then dies on the NOT NULL
     * constraint, which has broken three packages here already (CLAUDE.md).
     *
     * @param  array<string, mixed>  $frozen
     */
    private function makeTag(Assessment $assessment, array $frozen, string $slug): int
    {
        $tag = new Tag([
            'name' => (string) ($frozen['name'] ?? $slug),
            'slug' => $slug,
            'color' => $this->stringOrNull($frozen['color'] ?? null),
        ]);
        $tag->assessment_id = $assessment->id;
        $tag->save();

        return $tag->id;
    }

    /**
     * Who may hold an assignment on this assessment: its collaborators, plus
     * its owner, who is `assessments.owner_user_id` and not a membership row
     * (schema.md §3.1).
     *
     * @return array<int, true>
     */
    private function assignableUserIds(Assessment $assessment): array
    {
        /** @var list<int> $ids */
        $ids = $assessment->members()->pluck('users.id')->all();

        $allowed = array_fill_keys(array_map('intval', $ids), true);
        $allowed[(int) $assessment->owner_user_id] = true;

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $frozen
     * @param  array<int, int>  $liveLevelIds
     */
    private function fill(Node $node, array $frozen, array $liveLevelIds, int &$unrestorableLevels): void
    {
        $node->title = (string) ($frozen['title'] ?? '');
        $node->description = $this->stringOrNull($frozen['description'] ?? null);
        $node->position = (int) ($frozen['position'] ?? 0);

        match ($node->type) {
            'risk' => $this->fillRisk($node, $frozen, $liveLevelIds, $unrestorableLevels),
            'attack' => $this->fillAttack($node, $frozen, $liveLevelIds, $unrestorableLevels),
            'defence' => $this->fillDefence($node, $frozen),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $frozen
     * @param  array<int, int>  $liveLevelIds
     */
    private function fillRisk(Node $node, array $frozen, array $liveLevelIds, int &$unrestorableLevels): void
    {
        $node->impact_mode = $this->stringOrNull($frozen['impact_mode'] ?? null);
        $node->impact_amount = $this->floatOrNull($frozen['impact_amount'] ?? null);
        $node->impact_level_id = $this->levelOrNull($frozen['impact_level_id'] ?? null, $liveLevelIds, $unrestorableLevels);
    }

    /**
     * @param  array<string, mixed>  $frozen
     * @param  array<int, int>  $liveLevelIds
     */
    private function fillAttack(Node $node, array $frozen, array $liveLevelIds, int &$unrestorableLevels): void
    {
        $node->likelihood_mode = $this->stringOrNull($frozen['likelihood_mode'] ?? null);
        $node->likelihood_aro = $this->floatOrNull($frozen['likelihood_aro'] ?? null);
        $node->likelihood_level_id = $this->levelOrNull($frozen['likelihood_level_id'] ?? null, $liveLevelIds, $unrestorableLevels);
    }

    /**
     * @param  array<string, mixed>  $frozen
     */
    private function fillDefence(Node $node, array $frozen): void
    {
        $node->is_transfer = (bool) ($frozen['is_transfer'] ?? false);
        $node->status = $this->stringOrNull($frozen['status'] ?? null);
        $node->capex_amount = $this->floatOrNull($frozen['capex_amount'] ?? null);
        $node->opex_annual_amount = $this->floatOrNull($frozen['opex_annual_amount'] ?? null);
    }

    /**
     * A new row for a node the assessment no longer has.
     *
     * `assessment_id`, `uuid` and `created_by` are assigned as **properties**,
     * never through create(): they are identity and audit columns, deliberately
     * absent from Node::$fillable, and a create() carrying one is dropped
     * silently by Eloquent and then dies on the NOT NULL constraint. That has
     * broken three packages in this codebase already (CLAUDE.md).
     */
    private function make(Assessment $assessment, string $uuid, string $type, ?User $actor): Node
    {
        $node = match ($type) {
            'risk' => new Risk,
            'attack' => new Attack,
            'defence' => new Defence,
            default => throw new SnapshotNotRestorableException("This snapshot contains a node of an unknown type '{$type}'."),
        };

        $node->assessment_id = $assessment->id;
        $node->uuid = $uuid;
        $node->created_by = $actor?->id;

        return $node;
    }

    /** @return int 1 if the row actually changed, 0 if it was already identical */
    private function save(Node $node, ?User $actor): int
    {
        if (! $node->isDirty()) {
            // Stamping `updated_by` on an unchanged node would make the save
            // real, write a revision saying nothing changed, and record this
            // person as having edited a node they did not touch — the same
            // reasoning AssessmentSettingsController's own guard documents.
            return 0;
        }

        $node->updated_by = $actor?->id;
        $node->save();

        return 1;
    }

    /**
     * @param  Collection<string, Node>  $existing
     * @param  array<string, array<string, mixed>>  $frozenNodes
     */
    private function deleteAbsent(Collection $existing, array $frozenNodes): int
    {
        $deleted = 0;

        foreach ($existing as $uuid => $node) {
            if ($node->type === 'root' || $node->trashed() || array_key_exists((string) $uuid, $frozenNodes)) {
                continue;
            }

            $node->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $graph
     * @param  array<int, int>  $liveLevelIds
     */
    private function rebuildEdges(Assessment $assessment, array $graph, array $liveLevelIds, int &$unrestorableLevels): void
    {
        // The whole node set, keyed by uuid — one query, as the `pluck` this
        // replaced was, but hydrated. The edges below hand each endpoint to the
        // edge before saving it (W68), and the observer layer would otherwise
        // lazy-load all three of them per link, on a path that rebuilds every
        // edge in the assessment.
        /** @var Collection<string, Node> $nodes */
        $nodes = Node::query()
            ->where('assessment_id', $assessment->id)
            ->get()
            ->keyBy('uuid');

        /** @var array<string, int> $ids */
        $ids = $nodes->map(fn (Node $node): int => (int) $node->id)->all();

        $nodeIds = array_values($ids);

        RiskAttack::query()->whereIn('risk_node_id', $nodeIds)->delete();
        AttackDefence::query()->whereIn('attack_node_id', $nodeIds)->delete();

        /** @var list<array<string, mixed>> $riskAttack */
        $riskAttack = is_array($graph['riskAttackLinks'] ?? null) ? $graph['riskAttackLinks'] : [];

        foreach ($riskAttack as $link) {
            $riskId = $ids[(string) ($link['risk_uuid'] ?? '')] ?? null;
            $attackId = $ids[(string) ($link['attack_uuid'] ?? '')] ?? null;

            if ($riskId === null || $attackId === null) {
                continue;
            }

            // Identity columns as properties, never through create() — see
            // make() above, and LinkService, which is this codebase's
            // reference example for the same rule.
            $edge = new RiskAttack(['position' => (int) ($link['position'] ?? 0)]);
            $edge->risk_node_id = $riskId;
            $edge->attack_node_id = $attackId;
            $edge->setRelation('risk', $nodes[(string) ($link['risk_uuid'] ?? '')]);
            $edge->setRelation('attack', $nodes[(string) ($link['attack_uuid'] ?? '')]);
            $edge->save();
        }

        /** @var list<array<string, mixed>> $attackDefence */
        $attackDefence = is_array($graph['attackDefenceLinks'] ?? null) ? $graph['attackDefenceLinks'] : [];

        foreach ($attackDefence as $link) {
            $attackId = $ids[(string) ($link['attack_uuid'] ?? '')] ?? null;
            $defenceId = $ids[(string) ($link['defence_uuid'] ?? '')] ?? null;

            if ($attackId === null || $defenceId === null) {
                continue;
            }

            $edge = new AttackDefence([
                'position' => (int) ($link['position'] ?? 0),
                'strength_mode' => $this->stringOrNull($link['strength_mode'] ?? null),
                'strength_fraction' => $this->floatOrNull($link['strength_fraction'] ?? null),
                'strength_level_id' => $this->levelOrNull($link['strength_level_id'] ?? null, $liveLevelIds, $unrestorableLevels),
                'reduction_target' => $this->stringOrNull($link['reduction_target'] ?? null),
                'rationale' => $this->stringOrNull($link['rationale'] ?? null),
            ]);
            $edge->attack_node_id = $attackId;
            $edge->defence_node_id = $defenceId;
            $edge->setRelation('attack', $nodes[(string) ($link['attack_uuid'] ?? '')]);
            $edge->setRelation('defence', $nodes[(string) ($link['defence_uuid'] ?? '')]);
            $edge->save();
        }
    }

    /**
     * The settings a snapshot froze.
     *
     * `amortization_years` is why this matters: it divides every capital cost
     * in the report, so a graph restored beside today's divisor would produce
     * figures matching neither version. `risk_tolerance` is not part of the
     * snapshot's shape (schema.md §3.13) and is left alone rather than guessed
     * at.
     *
     * Written through RevisionRecorder (W60) so the change reads in the
     * timeline like any other settings edit, folded into this restore's
     * operation by the enclosing withinOperation().
     *
     * @param  array<string, mixed>  $graph
     */
    private function restoreSettings(Assessment $assessment, array $graph, ?User $actor): void
    {
        /** @var array<string, mixed> $frozen */
        $frozen = is_array($graph['assessment'] ?? null) ? $graph['assessment'] : [];

        $before = $assessment->only(['currency', 'amortization_years', 'risk_tolerance']);

        $assessment->currency = (string) ($frozen['currency'] ?? $assessment->currency);
        $assessment->amortization_years = (int) ($frozen['amortizationYears'] ?? $assessment->amortization_years);
        // Unconditional, unlike the settings screen's own guarded bump: the
        // graph has just changed underneath every cached figure whether or not
        // the divisor moved with it.
        $assessment->calc_version = $assessment->calc_version + 1;
        $assessment->save();

        $this->revisions->recordSettingsUpdate($assessment, $before, $actor);
    }

    /**
     * @param  array<int, int>  $liveLevelIds
     */
    private function levelOrNull(mixed $value, array $liveLevelIds, int &$unrestorableLevels): ?int
    {
        if ($value === null) {
            return null;
        }

        $id = (int) $value;

        if (! array_key_exists($id, $liveLevelIds)) {
            $unrestorableLevels++;

            return null;
        }

        return $id;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
