<?php

namespace App\Domain\History\Commands;

use App\Domain\History\Exceptions\UndoConflictException;
use App\Models\Node;

/**
 * Soft-deletes one or more nodes by uuid — the inverse of a node.create
 * (undo) or the forward action of redoing a node.delete. Also reused for
 * subtree.clone / catalog.insert's inverse: deleting every node the clone
 * created is the same operation over a longer uuid list.
 *
 * Node soft-delete does not cascade to `risk_attack`/`attack_defence` (the
 * pivot FKs are `cascadeOnDelete()` at the DB level, which only fires on a
 * real DELETE — a soft delete is an UPDATE). Edge rows referencing a
 * soft-deleted node are simply left in place, invisible to the graph loader
 * until the node is restored — this is exactly what makes RestoreNodeApplier
 * a clean, links-intact restore with no edge bookkeeping of its own.
 */
class DeleteNodeApplier implements CommandApplier
{
    public function apply(array $command, int $assessmentId): array
    {
        /** @var list<string> $uuids */
        $uuids = $command['node_uuids'] ?? [];

        foreach ($uuids as $uuid) {
            $node = Node::withTrashed()->where('assessment_id', $assessmentId)->where('uuid', $uuid)->first();

            if ($node === null) {
                throw new UndoConflictException('Cannot undo: a node in this change no longer exists.');
            }

            if ($node->trashed()) {
                continue; // Already in the desired end state.
            }

            $node->delete();
        }

        return ['action' => 'restore_node', 'node_uuids' => $uuids];
    }
}
