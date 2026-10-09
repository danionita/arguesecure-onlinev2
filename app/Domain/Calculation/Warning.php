<?php

namespace App\Domain\Calculation;

/**
 * One engine-emitted warning (calculation-engine.md §12). `nodeUuid` is null
 * for assessment-level warnings that do not point at a single node — e.g.
 * `missing_values`, which summarises coverage across the whole graph.
 */
final readonly class Warning
{
    public function __construct(
        public string $code,
        public ?string $nodeUuid,
        public string $message,
    ) {}
}
