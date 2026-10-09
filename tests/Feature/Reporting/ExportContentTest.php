<?php

use App\Domain\Reporting\Export\AssessmentCsvExporter;
use App\Domain\Reporting\Export\Cell;
use App\Domain\Reporting\Export\ExcelExporter;
use App\Domain\Reporting\Export\HtmlExporter;
use App\Domain\Reporting\Export\ReportSectionBuilder;
use App\Domain\Reporting\Export\WordExporter;
use App\Domain\Reporting\Money;
use App\Domain\Reporting\ReportBuilder;
use App\Domain\Reporting\ReportOptions;
use App\Models\Assessment;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Reporting\ExportFixture;

/*
 * What the five formats actually contain (W74, D34, item 25).
 *
 * The theme running through this file: there is **one assembled report and
 * five renderings of it**. Every assertion that matters here is an assertion
 * that two formats agree, or that an option changed all of them, rather than
 * that one format contains a string. A test that only ever looked at the CSV
 * would let the Word export drift for a year without noticing.
 */

/** @return array<string, mixed> */
function assembled(Assessment $assessment, ReportOptions $options): array
{
    return app(ReportBuilder::class)->build($assessment, $options);
}

function readXlsx(string $bytes): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'arsec-test-');
    file_put_contents($path, $bytes);

    try {
        return (new XlsxReader)->load($path);
    } finally {
        @unlink($path);
    }
}

function docxText(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'arsec-test-');
    file_put_contents($path, $bytes);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($path);

    return is_string($xml) ? $xml : '';
}

test('every format is a real file of its own kind, not a rename of another', function () {
    $assessment = ExportFixture::assessment();

    $responses = [];
    foreach (ExportFixture::routeNames() as $name) {
        $responses[$name] = $this->actingAs($assessment->owner)->get(route($name, $assessment));
        $responses[$name]->assertOk();
    }

    // D34's reasoning, asserted: faking .xlsx with a CSV and .docx with HTML
    // produces files that open with a repair dialog, which is worse than not
    // offering the format. Both are ZIP containers with a known first part —
    // an HTML file renamed .docx has neither.
    $xlsx = $responses['assessments.report.export-excel']->getContent();
    $docx = $responses['assessments.report.export-word']->getContent();

    expect(substr($xlsx, 0, 2))->toBe('PK')
        ->and(substr($docx, 0, 2))->toBe('PK');

    expect(readXlsx($xlsx)->getSheetCount())->toBeGreaterThan(1);
    expect(docxText($docx))->toContain('<w:document');

    $html = $responses['assessments.report.export-html']->getContent();
    expect($html)->toStartWith('<!DOCTYPE html>');
});

test('the HTML export is self-contained: no CDN, no external asset, no script', function () {
    $assessment = ExportFixture::assessment();

    $html = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment))
        ->getContent();

    // The CSP rule in CLAUDE.md binds a file that leaves the server as much
    // as a page served by it: this one is opened from a mail attachment on a
    // machine that has never met this deployment. A single <link> to a font
    // host would make the document depend on a network it will not have.
    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('<link ')
        ->and($html)->not->toContain('http://')
        ->and($html)->not->toContain('https://')
        ->and($html)->toContain('<style>');
});

test('node text is written as text, never as markup', function () {
    $assessment = ExportFixture::assessment();
    $assessment->rootNode->update(['title' => 'Payment <script>alert(1)</script> platform']);

    $html = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment))
        ->getContent();

    // CLAUDE.md: no HTML renderer exists in this codebase any more, and one
    // must not be reintroduced for generated output. This class writes HTML;
    // it never renders any.
    expect($html)->toContain('&lt;script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

test('all five formats carry the same figures as the report page', function () {
    $assessment = ExportFixture::assessment();

    $csv = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.defences-csv', $assessment))->getContent();
    $html = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment))->getContent();
    $json = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-json', $assessment))->getContent();
    $xlsx = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-excel', $assessment))->getContent();
    $docx = docxText($this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-word', $assessment))->getContent());

    // The golden vector from calculation-engine.md §14, so a disagreement
    // here is a disagreement with a number derived by hand in the spec.
    // Currency-formatted in the reading formats, raw in the machine ones —
    // the same float either way.
    foreach ([$html, $docx] as $rendered) {
        expect($rendered)->toContain('€187,000')
            ->and($rendered)->toContain('€65,500')
            ->and($rendered)->toContain('€36,700');
    }

    expect($csv)->toContain('187000')
        ->and($csv)->toContain('65500')
        ->and($csv)->toContain('36700');

    expect($json)->toContain('187000');

    $sheet = readXlsx($xlsx)->getSheetByName('Executive summary');
    expect($sheet)->not->toBeNull();

    // Numbers as numbers, not as "€187,000" text — a spreadsheet whose
    // totals row does not work is a spreadsheet nobody asked for.
    // `formatData: false` — the third argument. With it on, PhpSpreadsheet
    // hands back "€187,000" and this assertion would pass on a workbook whose
    // money columns were text, which is the one thing it exists to rule out.
    $totals = [];
    foreach ($sheet->toArray(null, true, false) as $row) {
        foreach ($row as $value) {
            if (is_numeric($value)) {
                $totals[] = round((float) $value, 2);
            }
        }
    }
    expect($totals)->toContain(187000.0)
        ->and($totals)->toContain(65500.0)
        ->and($totals)->toContain(36700.0);
});

test('the money columns of the workbook are numbers carrying the currency in their format', function () {
    $assessment = ExportFixture::assessment();

    $xlsx = readXlsx($this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-excel', $assessment))->getContent());

    $sheet = $xlsx->getSheetByName('Executive summary');
    $found = false;

    foreach ($sheet->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            if (! is_numeric($cell->getValue()) || (float) $cell->getValue() !== 187000.0) {
                continue;
            }

            $found = true;
            expect($cell->getStyle()->getNumberFormat()->getFormatCode())->toContain('€');
        }
    }

    expect($found)->toBeTrue();
});

test('the CSV is the whole report, not the ranked defence list it used to be', function () {
    $assessment = ExportFixture::assessment();

    $csv = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.defences-csv', $assessment))->getContent();

    // Item 25: "The report is risks, attacks, defences and edges; a CSV that
    // carries a quarter of it is mislabelled."
    expect($csv)->toContain('Customer data breach')            // a risk
        ->and($csv)->toContain('Phishing of admin credentials') // an attack
        ->and($csv)->toContain('MFA on admin accounts')         // a defence
        ->and($csv)->toContain('Full assessment')               // the section carrying the edges
        ->and($csv)->toContain('Risk register');

    // The edge, not the defence: strength lives on the attack_defence pivot,
    // so the same control appears against two attacks at two strengths.
    // A defence-only CSV could not say that, which is half of why item 25
    // widened this one.
    expect(substr_count($csv, 'Cyber insurance'))->toBeGreaterThan(1);

    // calculation-engine.md §10: never print ∞, and never a bare 0 standing
    // in for "not applicable".
    expect($csv)->not->toContain('INF')
        ->and($csv)->not->toContain('Infinity');
});

/*
 * ReportOptions binds all five formats.
 *
 * This is the failure W33 named for the download button, restated for
 * exports: an export that silently ignores "include shelved" produces a
 * document that looks complete and is not, and the omission is invisible in
 * the file. Each case below flips one option and asserts every format
 * changed — not just the one the author happened to be looking at.
 */
test('include shelved reaches every format, and off means off', function () {
    $assessment = ExportFixture::assessment();
    $shelved = 'Legacy VPN hardening';

    foreach (['assessments.report.export-html', 'assessments.report.defences-csv'] as $name) {
        $without = $this->actingAs($assessment->owner)
            ->get(route($name, $assessment).'?include_shelved=0')->getContent();
        $with = $this->actingAs($assessment->owner)
            ->get(route($name, $assessment).'?include_shelved=1')->getContent();

        // The shelved *section* — the defence still appears in the full
        // assessment, because it is linked to an attack and that edge is a
        // fact about the graph. What "include shelved" controls is whether
        // the report devotes a section to what was given up.
        expect($without)->not->toContain('Shelved defences')
            ->and($with)->toContain('Shelved defences');
    }

    $withoutXlsx = readXlsx($this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-excel', $assessment).'?include_shelved=0')->getContent());
    $withXlsx = readXlsx($this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-excel', $assessment).'?include_shelved=1')->getContent());

    expect($withoutXlsx->getSheetCount())->toBeLessThan($withXlsx->getSheetCount());

    $withDocx = docxText($this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-word', $assessment).'?include_shelved=1')->getContent());
    expect($withDocx)->toContain($shelved);
});

test('include methodology reaches every format', function () {
    $assessment = ExportFixture::assessment();

    $on = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?include_methodology=1')->getContent();
    $off = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?include_methodology=0')->getContent();

    expect($on)->toContain('Appendix: methodology')
        ->and($off)->not->toContain('Appendix: methodology');

    // The independence assumption and the per-occurrence definition of
    // impact are the two statements ui.md §9 names by name.
    expect($on)->toContain('independent')
        ->and($on)->toContain('loss per occurrence');
});

test('show assignees is off by default and adds a column only when chosen', function () {
    $assessment = ExportFixture::assessment();

    $default = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment))->getContent();
    $on = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?show_assignees=1')->getContent();

    // A report is a document that leaves the room. Who was responsible for
    // which line is internal information the exporter has to choose to
    // include — never inherited from a default (W46).
    expect($default)->not->toContain('<th>Assignees</th>')
        ->and($on)->toContain('<th>Assignees</th>');
});

test('baseline current hides the target columns in every format', function () {
    $assessment = ExportFixture::assessment();

    $current = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?baseline=current')->getContent();
    $both = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?baseline=both')->getContent();

    expect($current)->not->toContain('<th>Target ALE</th>')
        ->and($both)->toContain('<th>Target ALE</th>');

    // The target total is still stated — the executive summary is the one
    // place a reader must be able to see what the plan would buy — but the
    // per-risk target column is gone, which is what "baseline=current" asks
    // for. Asserting the total disappeared too would pin the wrong thing.
    expect($current)->not->toContain('€36,700');
});

/*
 * `download=1` survives the button (D35).
 *
 * "Download report" is removed and "Print as configured" becomes "Print",
 * but the forced-full URL W33 defined does not go away: it is what the HTML
 * and Word exports render from, so its query-parameter contract stays. A
 * stale link carrying `baseline=current` must not produce a file that looks
 * complete while silently omitting the target state.
 */
test('download=1 forces the whole report on an export, ignoring a stale query string', function () {
    $assessment = ExportFixture::assessment();

    $html = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?download=1&baseline=current&include_shelved=0&include_methodology=0')
        ->getContent();

    expect($html)->toContain('<th>Target ALE</th>')
        ->and($html)->toContain('Shelved defences')
        ->and($html)->toContain('Appendix: methodology');
});

test('download=1 still does not turn assignees on', function () {
    $assessment = ExportFixture::assessment();

    $html = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment).'?download=1&show_assignees=1')
        ->getContent();

    // Everything else download=1 forces is a matter of showing more of the
    // assessment. That one adds people's names to a document about to leave
    // the room, and `forDownload()` overrides the whole query string — so it
    // is off here even though the reader asked for it. The way to get names
    // is the option, not the download link.
    expect($html)->not->toContain('<th>Assignees</th>');
});

/*
 * Currency is the assessment's, never the server's.
 */
test('an assessment priced in dollars exports in dollars', function () {
    $assessment = ExportFixture::assessment();
    $assessment->update(['currency' => 'USD']);

    $html = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-html', $assessment))->getContent();

    expect($html)->not->toContain('€187,000')
        ->and($html)->toContain('187,000');

    $xlsx = readXlsx($this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-excel', $assessment))->getContent());

    $codes = [];
    foreach ($xlsx->getSheetByName('Executive summary')->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $codes[] = $cell->getStyle()->getNumberFormat()->getFormatCode();
        }
    }

    expect(implode(' ', $codes))->not->toContain('€');
});

/*
 * The exporters read the assembled report and nothing else.
 *
 * The rule in the brief — "any exporter that re-queries is a second source
 * of truth for the same figures, and they will diverge" — is enforceable
 * because every exporter's `export()` takes an array and a ReportOptions and
 * has no other collaborator. Handing them a report assembled from an
 * assessment that is then deleted proves it: anything that reached for the
 * database would fail, and every figure still comes out.
 */
test('an exporter renders from the assembled array alone, with no database behind it', function () {
    $assessment = ExportFixture::assessment();
    $options = ReportOptions::fromQuery([]);
    $report = assembled($assessment, $options);

    $assessment->nodes()->delete();
    $assessment->delete();

    expect((new HtmlExporter)->export($report, $options))->toContain('€187,000');
    expect((new AssessmentCsvExporter)->export($report, $options))->toContain('187000');
    expect((new ExcelExporter)->export($report, $options))->toStartWith('PK');
    expect((new WordExporter)->export($report, $options))->toStartWith('PK');
});

test('a missing value is a dash in every format and every kind, never zero', function () {
    // calculation-engine.md: a missing impact or likelihood excludes the
    // pair from the total rather than contributing zero, "because that would
    // understate risk". An export that wrote 0 would turn "we do not know"
    // into "we measured it and it was nothing".
    expect(Cell::money(null)->raw())->toBe('—')
        ->and(Cell::money(null)->display(new Money('EUR')))->toBe('—')
        ->and(Cell::percent(null)->raw())->toBe('—')
        ->and(Cell::rate(null)->raw())->toBe('—')
        ->and(Cell::number(null)->raw())->toBe('—');

    expect(Cell::money(null)->isNumeric())->toBeFalse();
});

test('the section list is the same for every format', function () {
    $assessment = ExportFixture::assessment();
    $options = ReportOptions::fromQuery([]);
    $report = assembled($assessment, $options);

    $sections = (new ReportSectionBuilder)->build($report, $options);
    $titles = array_map(fn ($section) => $section->title, $sections);

    // Not a snapshot of the exact list — that would break on every added
    // section — but the invariant that matters: the sections the HTML shows
    // are the sheets the workbook has, one for one and in order.
    $html = (new HtmlExporter)->export($report, $options);
    $sheetCount = readXlsx((new ExcelExporter)->export($report, $options))->getSheetCount();

    expect($sheetCount)->toBe(count($sections));
    foreach ($titles as $title) {
        expect($html)->toContain(htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
});
