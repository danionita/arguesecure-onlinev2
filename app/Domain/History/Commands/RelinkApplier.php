<?php

namespace App\Domain\History\Commands;

use App\Domain\History\Exceptions\UndoConflictException;

/**
 * Recreates one edge row with its captured fields — the inverse of a
 * link.delete (undo), or of an unlink (redoing a link.create). Refuses
 * cleanly if either endpoint has since been deleted (a live link cannot
 * point at a dead node) or if the link already exists (someone recreated it
 * independently) — both are "state has diverged" cases, not
 * partially-applicable ones.
 */
class RelinkApplier implements CommandApplier
{
    public function apply(array $command, int $assessmentId): array
    {
        $edge = (string) $command['edge'];
        $parentUuid = (string) $command['parent_uuid'];
        $childUuid = (string) $command['child_uuid'];
        $position = (int) ($command['position'] ?? 0);
        /** @var array<string, mixed> $extra */
        $extra = $command['extra'] ?? [];

        $meta = EdgeResolver::meta($edge);
        $parent = EdgeResolver::findNodeIn($assessmentId, $parentUuid);
        $child = EdgeResolver::findNodeIn($assessmentId, $childUuid);

        if ($parent === null || $parent->trashed() || $child === null || $child->trashed()) {
            throw new UndoConflictException('Cannot undo: an endpoint of this link no longer exists.');
        }

        $modelClass = $meta['model'];
        $exists = $modelClass::query()
            ->where($meta['parentColumn'], $parent->id)
            ->where($meta['childColumn'], $child->id)
            ->exists();

        if ($exists) {
            throw new UndoConflictException('Cannot undo: this link already exists.');
        }

        $row = new $modelClass;
        $row->setAttribute($meta['parentColumn'], $parent->id);
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
            'action' => 'unlink',
            'edge' => $edge,
            'parent_uuid' => $parentUuid,
            'child_uuid' => $childUuid,
        ];
    }
}
