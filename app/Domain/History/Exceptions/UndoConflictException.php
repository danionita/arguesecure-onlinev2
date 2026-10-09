<?php

namespace App\Domain\History\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation's inverse cannot be applied cleanly — a
 * referenced node was hard-deleted, a link was already removed by someone
 * else, or an endpoint has since been soft-deleted (schema.md §3.10, ui.md
 * §3.6: "If the inverse cannot be applied cleanly ... refuse with an
 * explicit message rather than half-applying").
 *
 * Always thrown from inside an Applier running under UndoService::undo()'s
 * or ::redo()'s DB transaction, so the transaction rolls back whatever
 * partial writes that Applier made before hitting this — nothing is ever
 * half-applied. The message is written to be shown to the user verbatim.
 */
class UndoConflictException extends RuntimeException {}
