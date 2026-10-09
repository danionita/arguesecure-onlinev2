<?php

namespace App\Domain\History\Commands;

use App\Domain\History\Exceptions\UndoConflictException;

/**
 * Resolves a command's `action` to its Applier through a fixed whitelist —
 * never a class name built from stored input (CLAUDE.md, security.md §7
 * defect #7). An operation's `inverse` JSON is written only by this
 * package's own code (never by request input directly), but the same
 * discipline applies regardless of where a string ends up stored.
 */
final class CommandDispatcher
{
    /** @var array<string, class-string<CommandApplier>> */
    private const APPLIERS = [
        'delete_node' => DeleteNodeApplier::class,
        'restore_node' => RestoreNodeApplier::class,
        'unlink' => UnlinkApplier::class,
        'relink' => RelinkApplier::class,
        'reorder_positions' => ReorderPositionsApplier::class,
        'reparent_edge' => ReparentEdgeApplier::class,
        'restore_snapshot' => RestoreSnapshotApplier::class,
    ];

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed> the command that reverses what was just applied
     */
    public function apply(array $command): array
    {
        $action = (string) ($command['action'] ?? '');
        $applierClass = self::APPLIERS[$action] ?? null;

        if ($applierClass === null) {
            throw new UndoConflictException("Cannot undo: unrecognised operation '{$action}'.");
        }

        $applier = new $applierClass;

        return $applier->apply($command);
    }
}
