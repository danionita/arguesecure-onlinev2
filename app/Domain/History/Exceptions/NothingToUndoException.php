<?php

namespace App\Domain\History\Exceptions;

use RuntimeException;

/**
 * Thrown when there is no undoable operation — either the stack is empty,
 * or the operation the caller targeted has already been undone by someone
 * else in the interim (schema.md §3.10: "Only the newest not-yet-undone
 * operation is undoable"). Distinct from UndoConflictException: this is not
 * a data-integrity problem, just a stale client re-sending an undo that
 * already happened.
 */
class NothingToUndoException extends RuntimeException {}
