<?php

namespace App\Domain\History;

use App\Domain\History\Exceptions\RevisionNotRevertibleException;
use App\Models\Node;
use App\Models\NodeRevision;
use App\Models\User;

/**
 * "Revert this field" (PLAN.md Phase 8 item 2, schema.md §3.9: "a 'revert
 * this field' action recorded as a normal edit"). Applies one field from
 * one historical revision back onto its node through an ordinary
 * `Node::save()` — deliberately NOT App\Domain\History\UndoService and NOT
 * `assessment_operations` (schema.md §3.10: "field edits are recovered
 * through node_revisions 'revert this field', which is recorded as a
 * normal edit, not an undo").
 *
 * Writes no `node_revisions` row itself: the `save()` call below fires
 * Node's `updated` model event, which NodeObserver forwards to
 * RevisionRecorder::recordUpdate() — the single writer of that table
 * (W18b). This keeps "exactly one writer of node_revisions" true rather
 * than having the revert path duplicate what the recorder already does.
 */
class RevisionReverter
{
    /**
     * @throws RevisionNotRevertibleException the revision is not
     *                                        field-shaped, recorded no prior value for $field, $field is
     *                                        not a revertible node column, or reverting would make no
     *                                        change (the node already holds the target value, so no
     *                                        `updated` event — and therefore no new revision — would
     *                                        fire).
     */
    public function revert(Node $node, NodeRevision $revision, string $field, User $actor): NodeRevision
    {
        // Only a genuine field-level change carries a {from, to} pair per
        // field — 'create'/'update' are the only two actions whose
        // `changes` shape is field-keyed (schema.md §3.9: link actions use
        // a different {other_uuid, other_title, pivot} shape).
        if (! in_array($revision->action, ['create', 'update'], true)) {
            throw new RevisionNotRevertibleException('Only a create or update revision can be reverted by field.');
        }

        // `changes` is cast to `array` on the model (NodeRevision::
        // casts()), but that method-based cast declaration is not picked
        // up by static analysis here (the same larastan gap UndoService::
        // inverseOf() documents for AssessmentOperation's identically
        // shaped `inverse` column) — getAttribute() (untyped) plus this
        // @var sidesteps that without touching app/Models/**.
        /** @var array<string, mixed> $changes */
        $changes = $revision->getAttribute('changes');

        // An assessment-settings revision (W60) hangs off the root node but is
        // not *about* it: `currency` and `amortization_years` are columns on
        // `assessments`, not on `nodes`. Refused here, explicitly, rather than
        // left to fail further down on the $fillable check — that check would
        // reject these today only because no node column happens to share a
        // name with a setting, and "correct by coincidence" is not a guard.
        // Reverting a setting would need to write the assessment row and bump
        // `calc_version`, which is a different operation from this one.
        if (($changes['scope'] ?? null) === RevisionRecorder::ASSESSMENT_SCOPE) {
            throw new RevisionNotRevertibleException('An assessment setting cannot be reverted from a node’s history.');
        }

        if (! array_key_exists($field, $changes) || ! is_array($changes[$field]) || ! array_key_exists('from', $changes[$field])) {
            throw new RevisionNotRevertibleException('This revision recorded no prior value for that field.');
        }

        // Fixed allowlist read from Node's own $fillable (never a dynamic
        // column name built from request input, security.md defect #7 /
        // CLAUDE.md) — reusing the model's own contract rather than
        // duplicating a second hardcoded list, matching RevisionRecorder::
        // trackedFields()'s identical reasoning.
        if (! in_array($field, $node->getFillable(), true)) {
            throw new RevisionNotRevertibleException('That field cannot be reverted.');
        }

        $target = $changes[$field]['from'];
        $previousRevisionId = $node->revisions()->value('id');

        $node->setAttribute($field, $target);
        $node->updated_by = $actor->id;
        $node->save();

        /** @var NodeRevision|null $newRevision */
        $newRevision = $node->revisions()->first();

        // If the target value already matched what the node held, save()
        // found nothing dirty, 'updated' never fired, and RevisionRecorder
        // never ran — the same "no-op saves write nothing" contract W18b's
        // brief requires of ordinary edits applies here too, so this is
        // reported as "nothing to revert" rather than silently returning
        // the old, unrelated revision.
        if ($newRevision === null || $newRevision->id === $previousRevisionId) {
            throw new RevisionNotRevertibleException('Reverting to the current value would make no change.');
        }

        return $newRevision;
    }
}
