<?php

namespace App\Domain\Structure;

use RuntimeException;

/** Thrown by ScaleValidator when a scale's bands violate schema.md §3.2's invariants. */
class ScaleValidationException extends RuntimeException {}
