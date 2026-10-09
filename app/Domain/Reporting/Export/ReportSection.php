<?php

namespace App\Domain\Reporting\Export;

/**
 * One section of the report, in the shape all five exports render from
 * (W74 item 25): a heading, the prose that explains what the reader is
 * looking at, and — for most of them — one table.
 *
 * Prose without a table is legal and used: the cover, the written executive
 * summary and the methodology appendix are paragraphs, not columns. A table
 * without prose is legal too. A section with neither is not built at all —
 * the builder omits it, so an export never carries an empty heading for a
 * section the options excluded.
 */
final readonly class ReportSection
{
    /**
     * @param  string  $key  stable identifier: the worksheet name, the HTML anchor, the CSV's first column
     * @param  list<string>  $prose  paragraphs, in order
     * @param  list<string>  $headers  empty when this section has no table
     * @param  list<list<Cell>>  $rows
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $prose = [],
        public array $headers = [],
        public array $rows = [],
    ) {}

    public function hasTable(): bool
    {
        return $this->headers !== [];
    }
}
