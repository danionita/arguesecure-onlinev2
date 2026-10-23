<?php

namespace App\Domain\History\Commands;

use App\Domain\History\Exceptions\UndoConflictException;

/**
 * Removes one edge row — the inverse of a link.create (undo), or of a
 * relink (redoing an unlink). Captures the row's own fields (position, and
 * for attack_defence the strength/reduction-target/rationale columns)
 * before deleting, so RelinkApplier can restore them exactly.
 */
class UnlinkApplier implements CommandApplier
{
    public function apply(array $command, int $assessmentId): array
    {
        $edge = (string) $command['edge'];
        $parentUuid = (string) $command['parent_uuid'];
        $childUuid = (string) $command['child_uuid'];

        $meta = EdgeResolver::meta($edge);
        $parent = EdgeResolver::findNodeIn($assessmentId, $parentUuid);
        $child = EdgeResolver::findNodeIn($assessmentId, $childUuid);

        if ($parent === null || $child === null) {
            throw new UndoConflictException('Cannot undo: an endpoint of this link no longer exists.');
        }

        $modelClass = $meta['model'];
        $row = $modelClass::query()
            ->where($meta['parentColumn'], $parent->id)
            ->where($meta['childColumn'], $child->id)
            ->first();

        if ($row === null) {
            throw new UndoConflictException('Cannot undo: this link no longer exists — it may already have been removed.');
        }

        // getAttribute(), not the ->position magic-property syntax: $row is
        // typed as the base Pivot class (EdgeResolver::meta() erases which
        // of the two typed pivots it is), and Larastan's model property
        // inference only understands literal ->prop access on a concrete
        // subclass — getAttribute() is the untyped escape hatch for a
        // genuinely polymorphic row.
        $position = (int) $row->getAttribute('position');
        $extra = $edge === 'attack_defence' ? [
            'strength_mode' => $row->getAttribute('strength_mode'),
            'strength_fraction' => $row->getAttribute('strength_fraction'),
            'strength_level_id' => $row->getAttribute('strength_level_id'),
            'reduction_target' => $row->getAttribute('reduction_target'),
            'rationale' => $row->getAttribute('rationale'),
        ] : [];

        $row->delete();

        return [
            'action' => 'relink',
            'edge' => $edge,
            'parent_uuid' => $parentUuid,
            'child_uuid' => $childUuid,
            'position' => $position,
            'extra' => $extra,
        ];
    }
}
