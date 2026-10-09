<?php

namespace App\Domain\History\Exceptions;

/**
 * Thrown by RevisionReverter for every "this revert cannot be applied"
 * case (schema.md §3.9): a link-shaped revision, a field with no recorded
 * prior value, a non-fillable field, or a revert that would make no
 * change. NodeRevisionController maps this to a 422 — the same status the
 * original W18 closure returned for each of these cases.
 */
class RevisionNotRevertibleException extends \RuntimeException {}
