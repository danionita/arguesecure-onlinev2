<?php

namespace App\Domain\Reporting\Export;

use App\Domain\Reporting\Money;
use App\Domain\Reporting\ReportOptions;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The report as a real `.xlsx` workbook (D34, W74 item 25): one sheet per
 * section, headers frozen, the same figures the report shows.
 *
 * **Numbers are written as numbers.** A money column written as the string
 * "€135,000" is text, and a spreadsheet whose totals row does not work is a
 * spreadsheet nobody asked for — which is the whole reason `Cell` carries
 * the float and the kind rather than a formatted string. The currency lives
 * in the cell's *format code*, built from the assessment's currency symbol,
 * so what a reader sees matches the report page while what a formula reads
 * is the figure the engine produced.
 *
 * The one exception is a missing value, which is written as the dash — as
 * text, deliberately. There is no number that means "nobody entered this",
 * and `0` is the one wrong answer: calculation-engine.md excludes an unvalued
 * pair from the total rather than counting it as zero, precisely so that a
 * gap cannot be read as a measurement.
 *
 * Prose sits above the table rather than being dropped. The honest-numbers
 * statements ui.md §9 requires — that a partial total is a lower bound, that
 * unpriced controls are counted and never folded in — are not decoration
 * around the figures; they are the conditions under which the figures mean
 * anything, and a workbook read six months later has nothing else to say so.
 */
final class ExcelExporter
{
    /** Excel's own limit. Longer titles are truncated, not rejected. */
    private const MAX_SHEET_TITLE = 31;

    /**
     * @param  array<string, mixed>  $report  the whole of ReportAssembler::assemble()
     */
    public function export(array $report, ReportOptions $options): string
    {
        $sections = (new ReportSectionBuilder)->build($report, $options);
        $money = new Money($this->currency($report));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $used = [];

        foreach ($sections as $section) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->sheetTitle($section->title, $used));
            $this->fill($sheet, $section, $money);
        }

        if ($spreadsheet->getSheetCount() === 0) {
            $spreadsheet->createSheet()->setTitle('Report');
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $this->serialise($spreadsheet);
    }

    private function fill(Worksheet $sheet, ReportSection $section, Money $money): void
    {
        $row = 1;

        foreach ($section->prose as $paragraph) {
            $sheet->setCellValue([1, $row], $paragraph);
            $sheet->getStyle([1, $row])->getAlignment()->setWrapText(false);
            $row++;
        }

        if ($section->prose !== []) {
            $row++;
        }

        if (! $section->hasTable()) {
            return;
        }

        $headerRow = $row;
        foreach ($section->headers as $index => $header) {
            $sheet->setCellValue([$index + 1, $headerRow], $header);
        }
        $sheet->getStyle([1, $headerRow, count($section->headers), $headerRow])
            ->getFont()->setBold(true);

        $row++;

        foreach ($section->rows as $dataRow) {
            foreach ($dataRow as $index => $cell) {
                $this->writeCell($sheet, $index + 1, $row, $cell, $money);
            }
            $row++;
        }

        // Immediately below the header, whatever the prose pushed it to.
        // "Headers frozen" is about the reader being able to see which column
        // they are in on row 400, not about the header being on row 1.
        $sheet->freezePane([1, $headerRow + 1]);

        foreach (array_keys($section->headers) as $index) {
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
        }
    }

    private function writeCell(Worksheet $sheet, int $column, int $row, Cell $cell, Money $money): void
    {
        if (! $cell->isNumeric()) {
            $sheet->setCellValue([$column, $row], $cell->display($money));

            return;
        }

        $sheet->setCellValue([$column, $row], $cell->value);
        $sheet->getStyle([$column, $row])
            ->getNumberFormat()
            ->setFormatCode($this->formatCode($cell, $money));
    }

    private function formatCode(Cell $cell, Money $money): string
    {
        return match ($cell->kind) {
            // The symbol is quoted so Excel treats it as a literal rather
            // than as one of its own currency tokens, which vary by the
            // *reader's* locale — the one place this could still drift.
            Cell::MONEY => '"'.$money->symbol().'"#,##0',
            Cell::PERCENT => '0%',
            Cell::RATE => '0.00"/yr"',
            default => $cell->decimals === 0 ? '#,##0' : '#,##0.'.str_repeat('0', $cell->decimals),
        };
    }

    /**
     * Excel rejects `[]:*?/\` in a sheet name, caps it at 31 characters and
     * refuses duplicates — and it does all three by failing to open the file
     * rather than by complaining at write time.
     *
     * @param  array<string, true>  $used
     */
    private function sheetTitle(string $title, array &$used): string
    {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $title) ?? $title;
        $clean = trim(mb_substr($clean, 0, self::MAX_SHEET_TITLE));
        if ($clean === '') {
            $clean = 'Section';
        }

        $candidate = $clean;
        $suffix = 2;
        while (isset($used[mb_strtolower($candidate)])) {
            $tail = ' '.$suffix;
            $candidate = mb_substr($clean, 0, self::MAX_SHEET_TITLE - mb_strlen($tail)).$tail;
            $suffix++;
        }

        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * PhpSpreadsheet's Xlsx writer builds a zip, and its zip backend needs a
     * seekable stream it owns — `php://memory` is not reliably one across
     * platforms. A temp file it is, removed on the way out including when the
     * write throws, so a failed export cannot leave the queue worker's disk
     * filling up one abandoned workbook at a time.
     */
    private function serialise(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arsec-xlsx-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary file for the Excel export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($path);
            $contents = file_get_contents($path);
        } finally {
            @unlink($path);
            $spreadsheet->disconnectWorksheets();
        }

        return $contents === false ? '' : $contents;
    }

    /** @param array<string, mixed> $report */
    private function currency(array $report): string
    {
        $assessment = is_array($report['assessment'] ?? null) ? $report['assessment'] : [];

        return is_string($assessment['currency'] ?? null) && $assessment['currency'] !== ''
            ? $assessment['currency']
            : 'EUR';
    }
}
