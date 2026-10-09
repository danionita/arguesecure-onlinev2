<?php

namespace App\Domain\History;

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Node;
use App\Models\NodeRevision;
use App\Models\User;

/**
 * PLAN.md Phase 2's "History\RevisionRecorder" (schema.md §3.9): an
 * immutable, per-field change log written automatically whenever a node's
 * tracked fields change. All fields dirtied by one save land in a single
 * `node_revisions` row (`changes` is `{"field": {"from": x, "to": y}, …}`,
 * schema.md §3.9), which is what makes "one user action that touches
 * several fields read as one entry" (PLAN.md Phase 2) true without any
 * grouping machinery.
 *
 * Deliberately narrow: only ordinary field edits are recorded here
 * (`action = 'update'`). Node creation and deletion are NOT recorded —
 * schema.md §3.10 draws the line at "only structural changes enter [the
 * undo] stack", and node_revisions' sibling mechanism,
 * `assessment_operations` (History\UndoService, a separate, earlier
 * package), already owns create/delete/move/link as reversible structural
 * operations with their own summaries. This class never writes to
 * `assessment_operations` and never reads from it — `operation_id` is
 * null for every ordinary edit, which is exactly what an ungrouped field
 * edit should record (NodeRevisionRevertTest's own worked case: "field
 * edits never group under a structural operation").
 *
 * **W61 added the one exception**: a caller that is already performing a
 * structural operation can wrap its work in withinOperation(), and the
 * revisions it causes are stamped with that operation's id so the timeline
 * folds them into a single entry. A snapshot restore is the only such
 * caller today, and the reasoning is on that method.
 */
class RevisionRecorder
{
    /**
     * The pivot columns a link revision tracks: everything AttackDefence
     * allows a request to set, minus `position`. Ordering is structural and
     * has its own `move` action, exactly as trackedFields() reasons about
     * `nodes.position`.
     */
    private const LINK_FIELDS = [
        'strength_mode',
        'strength_fraction',
        'strength_level_id',
        'reduction_target',
        'rationale',
    ];

    /**
     * The assessment-level settings a revision tracks (W60).
     *
     * Title and description are deliberately absent, and not because they
     * never occurred to whoever wrote this list. Through W44–2.4,
     * `AssessmentSettingsController` also wrote both to the **root node**,
     * and that write already went through `recordUpdate()` via the ordinary
     * `NodeObserver` path — so listing them here too would have recorded one
     * edit twice, which is why this class never tracked them.
     *
     * **W86 (item 9) removed that write entirely.** Title and description
     * live on the root node and are edited there, in the tree; the settings
     * screen no longer carries its own copies at all, and
     * `UpdateAssessmentSettingsRequest` now `prohibited`s both fields rather
     * than merely not asking for them. The double-write this list was built
     * to avoid can no longer happen through this screen, but the list stays
     * exactly as it was: `recordSettingsUpdate()` still has no business
     * writing a field that lives on the node, whether or not anything else
     * currently writes it there too.
     */
    private const SETTING_FIELDS = [
        'currency',
        'amortization_years',
        'risk_tolerance',
    ];

    /**
     * Marks a revision as being about the assessment rather than about the
     * node it hangs from. See recordSettingsUpdate().
     */
    public const ASSESSMENT_SCOPE = 'assessment';

    /** How long further edits to the same link fold into one row. */
    private const COALESCE_SECONDS = 120;

    /**
     * The structural operation every revision written right now belongs to
     * (W61), or null for the ordinary case.
     *
     * **Static on purpose, and it is the only static state in this class.**
     * The writer of `node_revisions` is NodeObserver's injected instance;
     * whoever opens the operation holds a *different* instance, because
     * nothing binds this class as a singleton and an observer registered by
     * name is resolved once and cached. An instance property would therefore
     * be set on one object and read on another — silently doing nothing,
     * which is the worst of the three options. Binding a singleton to carry
     * request-scoped mutable state would be worse still.
     *
     * Always set through withinOperation(), which restores the previous value
     * in a `finally`, so a throw cannot leak an operation id into the next
     * request in a long-running worker or the next test in a suite.
     */
    private static ?int $currentOperationId = null;

    /**
     * Runs $work with every revision it causes stamped with $operationId.
     *
     * `TimelineReader` has folded revisions sharing an `operation_id` into one
     * entry since W48 — "several revisions sharing an operation_id were one
     * user action and read as one entry" — and until W61 nothing produced any,
     * because ordinary field edits are already one row per save. A snapshot
     * restore is the first thing that touches fifty nodes on one click, and
     * fifty timeline entries for it would be a log file rather than a history.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function withinOperation(?int $operationId, callable $work): mixed
    {
        $previous = self::$currentOperationId;
        self::$currentOperationId = $operationId;

        try {
            return $work();
        } finally {
            self::$currentOperationId = $previous;
        }
    }

    /**
     * Called from NodeObserver::updated(), which only fires once Eloquent
     * has already found at least one dirty attribute (Model::
     * performUpdate() skips the 'updated' event entirely otherwise) — but
     * that dirty set can include audit columns (`updated_by`) that changed
     * even though nothing a user would call "a field" did. Filtering to
     * trackedFields() below is what makes a true no-op save (the same
     * title re-saved, only the acting user differs) write nothing, per
     * this package's brief.
     *
     * Must run before the model's `updated_at`/original attributes are
     * resynced — NodeObserver's `updated` hook fires from inside
     * Model::performUpdate(), after syncChanges() but before
     * finishSave()'s syncOriginal(), so getChanges()/getOriginal() below
     * still read pre-save originals against post-save current values.
     */
    public function recordUpdate(Node $node, ?User $actor): ?NodeRevision
    {
        $changes = [];

        foreach ($this->trackedFields($node) as $field) {
            if (! array_key_exists($field, $node->getChanges())) {
                continue;
            }

            $changes[$field] = [
                'from' => $node->getOriginal($field),
                'to' => $node->getAttribute($field),
            ];
        }

        if ($changes === []) {
            return null;
        }

        return NodeRevision::create([
            'assessment_id' => $node->assessment_id,
            'node_id' => $node->id,
            'node_uuid' => $node->uuid,
            'user_id' => $actor?->id,
            'action' => 'update',
            'changes' => $changes,
            'operation_id' => self::$currentOperationId,
            'created_at' => now(),
        ]);
    }

    /**
     * Records a change to an attack-defence edge (W56, closing the first of
     * W48's audit gaps).
     *
     * Strength is a direct multiplicand in `ALE = ARO × Impact × Π(1 −
     * strength)` — one of the three numbers this tool computes with — so a
     * history that could not see it could not reconstruct a past total, and a
     * "revert" built on it would restore the graph's shape while leaving
     * today's strengths in place. That is why this is here and why it was
     * blocking D15.
     *
     * The `changes` shape is the one schema.md §3.9 already reserved for link
     * actions: `{"other_uuid":…, "other_title":…, "pivot": {field: {from, to}}}`.
     * The revision hangs off the **attack**, because that is where the editor
     * shows the link and where a reader looking for "why did this number
     * change" starts.
     *
     * @param  array<string, mixed>  $before  the pivot's attributes before the save
     */
    public function recordLinkUpdate(
        AttackDefence $link,
        Attack $attack,
        Defence $defence,
        array $before,
        ?User $actor,
    ): ?NodeRevision {
        $pivot = [];

        foreach (self::LINK_FIELDS as $field) {
            $from = $before[$field] ?? null;
            $to = $link->getAttribute($field);

            if ($this->same($from, $to)) {
                continue;
            }

            $pivot[$field] = ['from' => $from, 'to' => $to];
        }

        if ($pivot === []) {
            return null;
        }

        $existing = $this->openLinkRevision($attack, $defence, $actor);

        if ($existing !== null) {
            return $this->coalesceInto($existing, $pivot);
        }

        return NodeRevision::create([
            'assessment_id' => $attack->assessment_id,
            'node_id' => $attack->id,
            'node_uuid' => $attack->uuid,
            'user_id' => $actor?->id,
            'action' => 'link_update',
            'changes' => [
                'other_uuid' => $defence->uuid,
                'other_title' => (string) $defence->title,
                'pivot' => $pivot,
            ],
            'operation_id' => self::$currentOperationId,
            'created_at' => now(),
        ]);
    }

    /**
     * Records a change that is not a column on the node — a label attached, a
     * person assigned (W56, the second and third audit gaps).
     *
     * Written in the ordinary `{field: {from, to}}` shape under `action =
     * 'update'`, so the timeline renders it with no special case: "labels" and
     * "assignee" read as fields of the node, which is how a user thinks of
     * them even though the database keeps them in pivot tables.
     *
     * No coalescing. Neither of these is dragged or typed character by
     * character, so every one of them is a deliberate act worth its own row.
     */
    public function recordFieldChange(
        Node $node,
        string $field,
        mixed $from,
        mixed $to,
        ?User $actor,
    ): ?NodeRevision {
        if ($this->same($from, $to)) {
            return null;
        }

        return NodeRevision::create([
            'assessment_id' => $node->assessment_id,
            'node_id' => $node->id,
            'node_uuid' => $node->uuid,
            'user_id' => $actor?->id,
            'action' => 'update',
            'changes' => [$field => ['from' => $from, 'to' => $to]],
            'operation_id' => self::$currentOperationId,
            'created_at' => now(),
        ]);
    }

    /**
     * Records a change to the assessment's own settings (W60, closing the
     * fourth of W48's audit gaps).
     *
     * `amortization_years` divides every capital cost in the report, which is
     * why W44's controller bumps `calc_version` for it and for nothing else on
     * that screen. A history that could not see it would restore the nodes and
     * leave the divisor at today's value — the "looks restored and is quietly
     * wrong" failure the whole audit exists to prevent.
     *
     * ## Why this is a node revision and not a new table
     *
     * `node_revisions.node_id` is nullable, but `node_uuid` is not, and the
     * timeline, the node filter and every reader key on the uuid. So the row
     * hangs off the **root node** — the row that already carries the
     * assessment's title and description, and the closest thing this schema
     * has to "the assessment itself" — and carries a `scope` marker saying
     * what it is really about. No migration, no second table, no second
     * reader: `TimelineReader` renders it with one branch and the subject
     * reads "the assessment".
     *
     * The marker is a key beside `fields` rather than inside it, in the same
     * shape §3.9 already reserved for link actions, so a settings field can
     * never collide with a node field of the same name.
     *
     * **These revisions are not revertible**, and `RevisionReverter` refuses
     * them explicitly rather than by accident: nothing in `fields` is a column
     * on the node this row hangs from, so applying one would either fail on a
     * missing attribute or — worse, if a name ever collided — write an
     * assessment setting onto the root node.
     *
     * No coalescing. This is a form with a Save button, not a field saving on
     * `@input`, so every save is a deliberate act worth its own row.
     *
     * @param  array<string, mixed>  $before  the assessment's attributes before the save
     */
    public function recordSettingsUpdate(Assessment $assessment, array $before, ?User $actor): ?NodeRevision
    {
        $fields = [];

        foreach (self::SETTING_FIELDS as $field) {
            $from = $before[$field] ?? null;
            $to = $assessment->getAttribute($field);

            if ($this->same($from, $to)) {
                continue;
            }

            $fields[$field] = ['from' => $from, 'to' => $to];
        }

        if ($fields === []) {
            return null;
        }

        $root = $assessment->rootNode;

        // Every assessment is created with a root node (schema.md §3.2), so
        // this is an already-broken assessment rather than an ordinary state.
        // The settings save itself is allowed to stand — refusing it would
        // turn a missing history row into an outage — but there is nowhere to
        // hang the revision from.
        if ($root === null) {
            return null;
        }

        return NodeRevision::create([
            'assessment_id' => $assessment->id,
            'node_id' => $root->id,
            'node_uuid' => $root->uuid,
            'user_id' => $actor?->id,
            'action' => 'update',
            'changes' => [
                'scope' => self::ASSESSMENT_SCOPE,
                'fields' => $fields,
            ],
            'operation_id' => self::$currentOperationId,
            'created_at' => now(),
        ]);
    }

    /**
     * The same person's revision for the same edge, still inside the
     * coalescing window.
     *
     * The window exists because `LinkStrengthEditor` saves on `@input`:
     * typing `0.85` into the strength field is four PATCHes, and four rows
     * reading 0 → 0.8 → 0.85 is not history, it is keystroke logging that
     * buries the one edit a reader came to find.
     *
     * Scoped to one user deliberately. Two people editing the same link a
     * minute apart is exactly the collision a history exists to show, and
     * folding those two into one row would attribute both to whoever typed
     * last.
     */
    private function openLinkRevision(Attack $attack, Defence $defence, ?User $actor): ?NodeRevision
    {
        return NodeRevision::query()
            ->where('assessment_id', $attack->assessment_id)
            ->where('node_uuid', $attack->uuid)
            ->where('action', 'link_update')
            ->where('user_id', $actor?->id)
            ->where('created_at', '>=', now()->subSeconds(self::COALESCE_SECONDS))
            // Never across a saved version (2.5.1). The History page groups
            // changes by the version they went into; folding a later edit into
            // a row that version already contains would move that row — and
            // the edit it recorded — out of the version that holds it.
            ->whereNotExists(fn ($query) => $query->from('snapshots')
                ->whereColumn('snapshots.assessment_id', 'node_revisions.assessment_id')
                ->whereColumn('snapshots.created_at', '>=', 'node_revisions.created_at'))
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->first(fn (NodeRevision $revision): bool => ($revision->changes['other_uuid'] ?? null) === $defence->uuid);
    }

    /**
     * Folds a further edit into an open revision.
     *
     * The earliest `from` is kept and the latest `to` wins, so the row always
     * reads as the whole of what this person did to this link in one sitting.
     * A field edited back to where it started drops out entirely — and if that
     * empties the row, the revision is deleted rather than left saying
     * "changed 0.5 to 0.5".
     *
     * **This is the one place a revision row is written twice.** schema.md
     * §3.9's "revisions are immutable" is qualified to this window, and the
     * qualification is the price of a readable timeline; the alternative is a
     * log nobody reads because every entry is a keystroke.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $pivot
     */
    private function coalesceInto(NodeRevision $existing, array $pivot): ?NodeRevision
    {
        /** @var array<string, mixed> $changes */
        $changes = $existing->changes;
        /** @var array<string, array{from: mixed, to: mixed}> $merged */
        $merged = $changes['pivot'] ?? [];

        foreach ($pivot as $field => $change) {
            $merged[$field] = [
                'from' => array_key_exists($field, $merged) ? $merged[$field]['from'] : $change['from'],
                'to' => $change['to'],
            ];

            if ($this->same($merged[$field]['from'], $merged[$field]['to'])) {
                unset($merged[$field]);
            }
        }

        if ($merged === []) {
            $existing->delete();

            return null;
        }

        $changes['pivot'] = $merged;
        $existing->changes = $changes;
        $existing->created_at = now();
        $existing->save();

        return $existing;
    }

    /**
     * Whether two stored values are the same change.
     *
     * Loose on numbers and strict on everything else: `strength_fraction` is
     * DECIMAL(7,6) and arrives from PDO as a string on one side of a save and
     * as a float on the other, so `0.5` and `'0.500000'` are the same number
     * wearing two types. `null` and `0` are never the same thing here — an
     * unrecorded strength and a strength of zero are different claims
     * (calculation-engine.md).
     */
    private function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0000005;
        }

        return $a === $b;
    }

    /**
     * Node::$fillable minus `position`: position ordering is a structural
     * concern owned by ReorderService/ReorderPositionsApplier (schema.md's
     * `node_revisions.action` enum reserves a dedicated 'move' action for
     * it). Recording position drift here under 'update' would misfile it
     * — worse, it would fire on every drag-reorder with no operation_id to
     * group the resulting flood under. Reading the model's own $fillable
     * rather than duplicating a second hardcoded field list keeps this in
     * sync with whatever UpdateNodeRequest actually allows, the same
     * reasoning the W18 revert closure already used for its own allowlist
     * check (routes/web.php, now App\Domain\History\RevisionReverter).
     *
     * @return list<string>
     */
    private function trackedFields(Node $node): array
    {
        return array_values(array_diff($node->getFillable(), ['position']));
    }
}
