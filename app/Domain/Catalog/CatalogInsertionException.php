<?php

namespace App\Domain\Catalog;

/**
 * Thrown by CatalogInserter for any condition the spec requires to fail
 * loudly rather than degrade silently (schema.md §3.14): a level code that does not exist on the target assessment's
 * scale, or a bundle edge whose parent/child kinds do not form a valid
 * risk->attack or attack->defence pair.
 */
class CatalogInsertionException extends \RuntimeException {}
