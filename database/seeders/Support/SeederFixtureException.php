<?php

namespace Database\Seeders\Support;

use RuntimeException;

/**
 * Thrown by the fixture validators (schema.md §3.14, W22 non-negotiables)
 * whenever a `database/seeders/templates/*.json` file is malformed, or a
 * scale level *code* it references does not resolve against the target's
 * calibration. Per the package brief: "a code that does not resolve must
 * fail loudly ... never silently fall back to a default or to null" — the
 * same rule `CatalogInserter` already enforces at insert time, applied here
 * at seed time instead.
 */
final class SeederFixtureException extends RuntimeException {}
