<?php

namespace App\Domain\Structure;

use RuntimeException;

/**
 * Thrown by LinkService when an edge would cross assessment boundaries.
 * Wrong-endpoint-type is rejected by PHP's own type system instead (the
 * service methods are typed to the concrete STI subclass), so no separate
 * exception exists for that case.
 */
class LinkValidationException extends RuntimeException {}
