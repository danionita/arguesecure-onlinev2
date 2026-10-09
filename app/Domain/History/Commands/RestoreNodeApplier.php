<?php

namespace App\Domain\History\Commands;

use App\Domain\History\Exceptions\UndoConflictException;
use App\Models\Node;

/**
 * Restores one or more soft-deleted nodes by uuid — the inverse of a
 * node.delete (undo). Refuses cleanly (schema.md §3.10) when a node was
 * hard-deleted in the interim: `withTrashed()` returns nothing at all for a
 * row that no longer exists in any state, which is exactly the "a
 * referenced node was hard-deleted" refusal case the spec calls out.
 *
 * Edges pointing at the node were never touched by its soft delete (see
 * DeleteNodeApplier's docblock), so restoring is the whole job — this is
 * what makes "undo restores a deleted node with its links intact" true
 * without any pivot-table bookkeeping here.
 */
class RestoreNodeApplier implements CommandApplier
{
    public function apply(array $command): array
    {
        /** @var list<string> $uuids */
        $uuids = $command['node_uuids'] ?? [];

        foreach ($uuids as $uuid) {
            $node = Node::withTrashed()->where('uuid', $uuid)->first();

            if ($node === null) {
                throw new UndoConflictException(
                    'Cannot undo: the deleted node no longer exists — it may have been permanently removed.'
                );
            }

            if (! $node->trashed()) {
                continue; // Already in the desired end state.
            }

            $node->restore();
        }

        return ['action' => 'delete_node', 'node_uuids' => $uuids];
    }
}
