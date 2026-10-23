<?php

namespace App\Domain\History\Commands;

use App\Domain\History\Exceptions\UndoConflictException;

/**
 * One reversible unit of graph mutation (schema.md §3.10's `inverse`
 * column). Each Applier both performs its command against current DB state
 * *and* returns the command that would exactly undo what it just did — that
 * returned command becomes the new `inverse` stored on the operation row,
 * which is what lets UndoService::redo() work without a second stored
 * payload (see its docblock).
 */
interface CommandApplier
{
    /**
     * @param  array<string, mixed>  $command
     * @param  int  $assessmentId  the operation's assessment: every uuid in the command is resolved inside it (D82)
     * @return array<string, mixed> the command that reverses what was just applied
     *
     * @throws UndoConflictException the command's preconditions no longer hold against current state
     */
    public function apply(array $command, int $assessmentId): array;
}
