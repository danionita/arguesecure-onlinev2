<?php

namespace App\Domain\Assistant\Generation;

use RuntimeException;

/**
 * A generation stopped because a person asked it to.
 *
 * Extends RuntimeException so it unwinds through the same paths every other
 * runtime failure does — `SingleFlight`'s `finally` still releases the lock,
 * the job's `finally` still clears its in-progress flag — but it is a distinct
 * type because **a cancellation is not a failure** and must not be reported as
 * one. Callers catch this *before* RuntimeException; catching it after would
 * log a fault, notify the user that the assistant broke, and be wrong on both
 * counts.
 */
class GenerationCancelled extends RuntimeException
{
    public function __construct(string $message = 'The generation was cancelled.')
    {
        parent::__construct($message);
    }
}
