<?php

namespace App\Domain\Reporting\Export;

use App\Domain\Reporting\ReportOptions;

/**
 * CSV of the whole report (W74 item 25).
 *
 * This is W17's `DefenceCsvExporter`, widened — and renamed with it. The
 * report is risks, attacks, defences *and the edges between them*; a CSV
 * that carried the ranked defence list alone was a quarter of it under a
 * name that promised the whole, and the class was mislabelled for exactly
 * the same reason the file was. The edges matter especially: strength lives
 * on the `attack_defence` pivot, not on the defence, so a defence-only
 * export cannot say that one control is 80% effective against one attack and
 * 20% against another.
 *
 * One file, every section, with the section name in the first column so a
 * reader can filter or split it. Ragged rows are the price — sections have
 * different column counts — and are preferable to eleven downloads.
 *
 * Numbers are written with `raw()`: no thousands separator, no currency
 * symbol, `.` as the decimal point. A grouped "135,000" inside a
 * comma-separated file is a parsing accident, and the currency belongs in
 * the header row where it is stated once. `—` for anything missing, never
 * `0` and never `INF`.
 *
 * **Text is written as text.** A spreadsheet opening this file reads a cell
 * starting with `=`, `+`, `-` or `@` — or a tab or carriage return, which
 * some strip before looking — as a formula. Every text cell, header and
 * section name starting with one gets a leading `'`, OWASP's CSV-injection
 * advice (W106). Numbers come from `raw()` and are left alone: a negative
 * figure is a number, and quoting it would make it text.
 */
final class AssessmentCsvExporter
{
    /**
     * @param  array<string, mixed>  $report  the whole of ReportAssembler::assemble()
     */
    public function export(array $report, ReportOptions $options): string
    {
        $sections = (new ReportSectionBuilder)->build($report, $options);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open a temporary stream for CSV export.');
        }

        foreach ($sections as $section) {
            if (! $section->hasTable()) {
                continue;
            }

            fputcsv($handle, array_map($this->text(...), ['Section', ...$section->headers]));

            foreach ($section->rows as $row) {
                fputcsv($handle, [$this->text($section->title), ...array_map(
                    fn (Cell $cell): string => $cell->kind === Cell::TEXT ? $this->text($cell->raw()) : $cell->raw(),
                    $row,
                )]);
            }

            // A blank line between sections. Every spreadsheet treats it as a
            // row break rather than as data, and without it eleven tables of
            // different widths run together into one unreadable block.
            fputcsv($handle, []);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    private function text(string $value): string
    {
        // Past leading whitespace and control characters too: some importers
        // trim them and then evaluate what is left.
        return preg_match('/^[\x00-\x20]*[=+\-@]|^[\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
