<?php

namespace App\Domain\History\Commands;

use App\Domain\History\Exceptions\UndoConflictException;
use App\Models\Node;

/**
 * Restores a batch of sibling positions in one shot — the inverse of a
 * same-parent drag reorder (ui.md §3, "ordering lives on the edge"). Covers
 * all three position homes: `nodes.position` (risks under root, defences in
 * the library) and the two edge pivots' own `position` column.
 */
class ReorderPositionsApplier implements CommandApplier
{
    public function apply(array $command, int $assessmentId): array
    {
        $scope = (string) $command['scope'];
        /** @var list<array{key: array<string, string>, position: int}> $entries */
        $entries = $command['entries'] ?? [];

        $reverseEntries = [];

        foreach ($entries as $entry) {
            $key = $entry['key'];
            $position = (int) $entry['position'];

            if ($scope === 'nodes') {
                $node = Node::where('assessment_id', $assessmentId)->where('uuid', $key['node_uuid'])->first();

                if ($node === null) {
                    throw new UndoConflictException('Cannot undo: a node in this reorder no longer exists.');
                }

                $reverseEntries[] = ['key' => $key, 'position' => $node->position];
                $node->update(['position' => $position]);

                continue;
            }

            $meta = EdgeResolver::meta($scope);
            $parent = EdgeResolver::findNodeIn($assessmentId, $key['parent_uuid']);
            $child = EdgeResolver::findNodeIn($assessmentId, $key['child_uuid']);

            if ($parent === null || $child === null) {
                throw new UndoConflictException('Cannot undo: an endpoint in this reorder no longer exists.');
            }

            $modelClass = $meta['model'];
            $row = $modelClass::query()
                ->where($meta['parentColumn'], $parent->id)
                ->where($meta['childColumn'], $child->id)
                ->first();

            if ($row === null) {
                throw new UndoConflictException('Cannot undo: a link in this reorder no longer exists.');
            }

            // getAttribute(): $row is the base Pivot type (EdgeResolver
            // erases which of the two typed pivots it is).
            $reverseEntries[] = ['key' => $key, 'position' => $row->getAttribute('position')];
            $row->update(['position' => $position]);
        }

        return ['action' => 'reorder_positions', 'scope' => $scope, 'entries' => $reverseEntries];
    }
}
