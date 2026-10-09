<?php

namespace App\Domain\Reporting\Export;

use App\Domain\Reporting\Money;
use App\Domain\Reporting\ReportOptions;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Writer\Word2007;

/**
 * The report as a real `.docx` (D34, W74 item 25): the prose sections in
 * order, with the tables.
 *
 * This is the format for the reader who has to *change* it — add a covering
 * paragraph, cut a section, hand it to someone who edits in Word and does
 * not have an ArgueSecure account. That is why it is a genuine Word document
 * rather than an HTML file with a `.doc` extension: the fake opens, warns
 * the recipient that the file is not what it claims, and then loses the
 * styles anyway.
 *
 * **Every string goes in as text.** `addText()` and `addCell()->addText()`
 * escape their content; nothing here builds markup from a title, a rationale
 * or the written summary. The summary in particular may be model-drafted
 * text a person accepted (W54, D24) — the accept step is the guardrail, and
 * a renderer that interpreted what it carried would be a second surface for
 * exactly what D24 exists to stop.
 *
 * Tables repeat their header row across pages (`tblHeader`). The
 * full-assessment section is 14 columns of a 200-row assessment; a header
 * that appears once on page 1 makes every page after it unreadable.
 */
final class WordExporter
{
    /**
     * @param  array<string, mixed>  $report  the whole of ReportAssembler::assemble()
     */
    public function export(array $report, ReportOptions $options): string
    {
        $sections = (new ReportSectionBuilder)->build($report, $options);
        $money = new Money($this->currency($report));

        $word = new PhpWord;
        $word->getSettings()->setUpdateFields(true);

        $word->addTitleStyle(1, ['size' => 20, 'bold' => true]);
        $word->addTitleStyle(2, ['size' => 14, 'bold' => true]);
        $word->addTableStyle('report', [
            'borderSize' => 6,
            'borderColor' => 'D1D5DB',
            'cellMargin' => 60,
        ], [
            'bgColor' => 'F3F4F6',
        ]);

        // Landscape, because the full-assessment section is the widest thing
        // in the document and portrait would either clip it or shrink it past
        // reading size. The rest of the sections lose nothing by the choice.
        $documentSection = $word->addSection(['orientation' => 'landscape']);

        $documentSection->addTitle($this->documentTitle($report), 1);

        foreach ($sections as $section) {
            $documentSection->addTitle($section->title, 2);

            foreach ($section->prose as $paragraph) {
                $documentSection->addText($paragraph, ['size' => 10], ['spaceAfter' => 120]);
            }

            if (! $section->hasTable()) {
                continue;
            }

            if ($section->rows === []) {
                $documentSection->addText('Nothing in this section.', ['size' => 10, 'italic' => true]);

                continue;
            }

            $table = $documentSection->addTable('report');

            $table->addRow(null, ['tblHeader' => true]);
            foreach ($section->headers as $header) {
                $table->addCell(null, ['bgColor' => 'F3F4F6'])
                    ->addText($header, ['size' => 8, 'bold' => true]);
            }

            foreach ($section->rows as $row) {
                $table->addRow(null, ['cantSplit' => true]);
                foreach ($row as $cell) {
                    $table->addCell()->addText(
                        $cell->display($money),
                        ['size' => 8],
                        ['alignment' => $cell->kind === Cell::TEXT ? Jc::START : Jc::END],
                    );
                }
            }

            $documentSection->addTextBreak(1);
        }

        return $this->serialise($word);
    }

    /**
     * PhpWord's Word2007 writer, like the spreadsheet one, builds a zip
     * through a stream it owns. Same reasoning, same cleanup: the temp file
     * goes even when the write throws.
     */
    private function serialise(PhpWord $word): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arsec-docx-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary file for the Word export.');
        }

        try {
            (new Word2007($word))->save($path);
            $contents = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        return $contents === false ? '' : $contents;
    }

    /** @param array<string, mixed> $report */
    private function documentTitle(array $report): string
    {
        $cover = is_array($report['cover'] ?? null) ? $report['cover'] : [];
        $name = is_string($cover['title'] ?? null) && $cover['title'] !== ''
            ? $cover['title']
            : 'Assessment';

        return 'ArgueSecure — '.$name.' — '.now()->format('Y-m-d');
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
