<?php

namespace App\Domain\History\Commands;

use App\Domain\History\Exceptions\UndoConflictException;

/**
 * Moves one edge from its current parent back to a prior parent — the
 * inverse of a cross-parent drag (re-parenting a shared attack under a
 * different risk, or a shared defence under a different attack).
 * `parent_uuid` is where the edge should end up; `current_parent_uuid` is
 * where it is now expected to be. Reversing simply swaps the two.
 *
 * Only the dragged occurrence's own edge is touched — a shared node's other
 * occurrences live on entirely separate edge rows and are never queried
 * here, which is what keeps them undisturbed (ui.md §3.1, schema.md
 * "ordering lives on the edge").
 */
class ReparentEdgeApplier implements CommandApplier
{
    public function apply(array $command): array
    {
        $edge = (string) $command['edge'];
        $targetParentUuid = (string) $command['parent_uuid'];
        $currentParentUuid = (string) $command['current_parent_uuid'];
        $childUuid = (string) $command['child_uuid'];
        $position = (int) ($command['position'] ?? 0);
        /** @var array<string, mixed> $extra */
        $extra = $command['extra'] ?? [];

        $meta = EdgeResolver::meta($edge);
        $modelClass = $meta['model'];

        $targetParent = EdgeResolver::findNode($targetParentUuid);
        $currentParent = EdgeResolver::findNode($currentParentUuid);
        $child = EdgeResolver::findNode($childUuid);

        if ($targetParent === null || $targetParent->trashed() || $child === null || $child->trashed()) {
            throw new UndoConflictException('Cannot undo: an endpoint of this move no longer exists.');
        }

        $currentRow = $currentParent === null ? null : $modelClass::query()
            ->where($meta['parentColumn'], $currentParent->id)
            ->where($meta['childColumn'], $child->id)
            ->first();

        if ($currentRow === null) {
            throw new UndoConflictException('Cannot undo: this link has changed since the move.');
        }

        // getAttribute(): $currentRow is the base Pivot type.
        $currentPosition = (int) $currentRow->getAttribute('position');

        $alreadyThere = $modelClass::query()
            ->where($meta['parentColumn'], $targetParent->id)
            ->where($meta['childColumn'], $child->id)
            ->exists();

        if ($alreadyThere) {
            throw new UndoConflictException('Cannot undo: a link to the original parent already exists.');
        }

        $currentRow->delete();

        $row = new $modelClass;
        $row->setAttribute($meta['parentColumn'], $targetParent->id);
        $row->setAttribute($meta['childColumn'], $child->id);
        $row->setAttribute('position', $position);

        if ($edge === 'attack_defence') {
            $row->setAttribute('strength_mode', $extra['strength_mode'] ?? null);
            $row->setAttribute('strength_fraction', $extra['strength_fraction'] ?? null);
            $row->setAttribute('strength_level_id', $extra['strength_level_id'] ?? null);
            $row->setAttribute('reduction_target', $extra['reduction_target'] ?? null);
            $row->setAttribute('rationale', $extra['rationale'] ?? null);
        }

        $row->save();

        return [
            'action' => 'reparent_edge',
            'edge' => $edge,
            'parent_uuid' => $currentParentUuid,
            'current_parent_uuid' => $targetParentUuid,
            'child_uuid' => $childUuid,
            'position' => $currentPosition,
            'extra' => $extra,
        ];
    }
}
