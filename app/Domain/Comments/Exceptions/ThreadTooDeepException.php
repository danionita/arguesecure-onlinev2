<?php

namespace App\Domain\Comments\Exceptions;

/**
 * Thrown by CommentService::create() when a reply targets a comment that
 * is itself already a reply (schema.md §3.8: "Threading depth is exactly
 * two levels — a top-level comment plus replies"). CommentController turns
 * this into a 422, matching how UndoConflictException/NothingToUndoException
 * are turned into responses by their own controllers.
 */
class ThreadTooDeepException extends \RuntimeException {}
