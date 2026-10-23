<?php

use App\Models\Assessment;
use App\Models\Node;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Tests\Feature\Reporting\ExportFixture;

/*
 * W106: the Word, Excel and CSV exports write a title or a description as
 * text, whatever characters it holds.
 *
 * A node's text is whatever a collaborator typed, or what a model drafted and
 * a person accepted (D24). Each format has its own way of reading text as
 * something else — WordML in the `.docx`, a formula in a workbook cell, a
 * formula again when a spreadsheet opens the CSV — and each test below
 * asserts the text came out as the same text.
 */

const W106_AMPERSAND = 'R&D <x>';
const W106_RUN = 'Closes the run</w:t></w:r><w:r><w:t>INJECTED';
const W106_FORMULA = '=HYPERLINK("http://example.invalid/","click")';
const W106_PLUS = '+1+cmd';
const W106_MINUS = '-1+cmd';
const W106_AT = '@SUM(1+1)';
const W106_TAB = "\t=1+1";
const W106_SPACED = '  =1+1';

function w106Assessment(): Assessment
{
    $assessment = ExportFixture::assessment();

    $assessment->rootNode->update(['title' => W106_AMPERSAND, 'description' => W106_RUN]);

    $titles = [
        'Customer data breach' => W106_FORMULA,
        'Service outage' => W106_RUN,
        'Phishing of admin credentials' => W106_PLUS,
        'SQL injection on portal' => W106_AT,
        'Volumetric DDoS' => W106_TAB,
        'MFA on admin accounts' => W106_MINUS,
        'Web application firewall' => W106_AMPERSAND,
        'Legacy VPN hardening' => W106_SPACED,
    ];

    foreach ($titles as $from => $to) {
        expect(Node::where('assessment_id', $assessment->id)->where('title', $from)->update(['title' => $to]))->toBe(1);
    }

    return $assessment;
}

test('the Word export writes titles and descriptions as escaped text', function () {
    $assessment = w106Assessment();

    $docx = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-word', $assessment))->getContent();

    $path = tempnam(sys_get_temp_dir(), 'arsec-test-');
    file_put_contents($path, $docx);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($path);

    expect($xml)->toBeString();

    // A file Word refuses to open is what an unescaped `&` produces; a parse
    // is the check that stands in for opening it.
    $dom = new DOMDocument;
    expect(@$dom->loadXML($xml))->toBeTrue();

    expect($xml)->toContain('R&amp;D &lt;x&gt;')
        ->and($xml)->toContain('Closes the run&lt;/w:t&gt;&lt;/w:r&gt;&lt;w:r&gt;&lt;w:t&gt;INJECTED');

    // Every `<w:t>` that mentions the payload holds all of it: a run that was
    // closed and reopened would leave "INJECTED" in a text node of its own.
    $texts = [];
    foreach ($dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't') as $node) {
        $texts[] = $node->textContent;
    }
    expect($texts)->not->toContain('INJECTED')
        ->and($texts)->toContain(W106_RUN)
        ->and($texts)->toContain(W106_AMPERSAND);
});

test('the Excel export writes every title as a string cell, never a formula', function () {
    $assessment = w106Assessment();

    $xlsx = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-excel', $assessment))->getContent();

    $path = tempnam(sys_get_temp_dir(), 'arsec-test-');
    file_put_contents($path, $xlsx);
    try {
        $book = (new XlsxReader)->load($path);
    } finally {
        @unlink($path);
    }

    $payloads = [W106_FORMULA, W106_PLUS, W106_MINUS, W106_AT, W106_TAB, W106_SPACED, W106_RUN, W106_AMPERSAND];
    $seen = [];

    foreach ($book->getAllSheets() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                if (! in_array($value, $payloads, true)) {
                    continue;
                }

                $seen[$value] = true;
                expect($cell->getDataType())->toBe(DataType::TYPE_STRING, $sheet->getTitle().'!'.$cell->getCoordinate());
            }
        }
    }

    expect(array_keys($seen))->toEqualCanonicalizing($payloads);
});

test('the CSV export quotes every text cell a spreadsheet would read as a formula', function () {
    $assessment = w106Assessment();

    $csv = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.defences-csv', $assessment))->getContent();

    $cells = [];
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    while (($row = fgetcsv($handle, escape: '\\')) !== false) {
        array_push($cells, ...array_filter($row, fn ($cell) => $cell !== null));
    }
    fclose($handle);

    foreach ([W106_FORMULA, W106_PLUS, W106_MINUS, W106_AT, W106_TAB, W106_SPACED] as $payload) {
        expect($cells)->toContain("'".$payload)
            ->and($cells)->not->toContain($payload);
    }

    // Text that starts with anything else is left as it was, and so are the
    // figures: 187000 is a number, not text to be quoted.
    expect($cells)->toContain(W106_AMPERSAND)
        ->and($cells)->toContain(W106_RUN)
        ->and($cells)->toContain('187000');
});
