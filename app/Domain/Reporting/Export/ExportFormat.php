<?php

namespace App\Domain\Reporting\Export;

/**
 * The five formats behind the one export menu (D34).
 *
 * PDF is deliberately absent and is not an omission: it is the browser's own
 * print pipeline (D6, not reopened), which is why `dompdf` — the most
 * dangerous dependency in the legacy repository — stays gone and no
 * headless-Chromium sidecar joins the deployment.
 *
 * Faking `.xlsx` with a CSV and `.docx` with an HTML file is the other thing
 * not done here. Both open, both raise a repair dialog, and a file that
 * warns the recipient before it opens is worse than a format not offered.
 */
enum ExportFormat: string
{
    case Html = 'html';
    case Csv = 'csv';
    case Json = 'json';
    case Excel = 'xlsx';
    case Word = 'docx';

    /** What the menu calls it. */
    public function label(): string
    {
        return match ($this) {
            self::Html => 'Web page (.html)',
            self::Csv => 'Spreadsheet data (.csv)',
            self::Json => 'Data interchange (.json)',
            self::Excel => 'Excel workbook (.xlsx)',
            self::Word => 'Word document (.docx)',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    /**
     * What the downloaded file is called, before the uuid.
     *
     * CSV keeps `defences-` even though it now carries the whole report, for
     * the same reason its route path is still `defences.csv`: it has been
     * that since W17, it is asserted in test files this package does not own,
     * and whatever scripts and bookmarks exist point at it. A filename is a
     * weaker claim than a class name — nobody reads `defences-<uuid>.csv` and
     * concludes the risk register is missing from a file whose first column
     * says "Risk register" on every row.
     */
    public function filenamePrefix(): string
    {
        return $this === self::Csv ? 'defences' : 'assessment';
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Html => 'text/html; charset=UTF-8',
            self::Csv => 'text/csv; charset=UTF-8',
            self::Json => 'application/json',
            self::Excel => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Word => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        };
    }
}
