<?php

use App\Domain\References\ReferenceValidator;
use App\Domain\Reporting\Export\ExcelExporter;
use App\Domain\Reporting\Export\HtmlExporter;
use App\Domain\Reporting\Export\ReportSection;
use App\Domain\Reporting\Export\ReportSectionBuilder;
use App\Domain\Reporting\Export\WordExporter;
use App\Domain\Reporting\ReportBuilder;
use App\Domain\Reporting\ReportOptions;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The coverage view (PLAN-2.7 D65, D66). What it asserts is the rule, not
 * the markup: a control reads implemented when any citing defence is,
 * planned when none is but one is todo, shelved otherwise; only what is
 * cited is listed, grouped by framework in config order and controls in
 * natural order; a citing defence that counters no attack is flagged; and
 * `include_shelved` off leaves shelved defences out but counts the controls
 * that took with them.
 */
function cite(Node $defence, string $identifier): void
{
    $defence->references()->create([
        'kind' => 'control',
        'identifier' => $identifier,
        'url' => ReferenceValidator::deriveUrl('control', $identifier),
        'position' => $defence->references()->count(),
    ]);
}

function coverageAssessment(): Assessment
{
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);

    $defence = function (string $title, string $status, bool $linked = true) use ($assessment, $attack): Defence {
        $d = Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => $title, 'status' => $status, 'is_transfer' => false]);
        if ($linked) {
            $attack->defences()->attach($d->id, ['position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.5, 'reduction_target' => 'likelihood']);
        }

        return $d;
    };

    $cloud = $defence('Cloud security policy', 'implemented');
    cite($cloud, 'ISO27001-2022:A.5.23');
    cite($cloud, 'NIS2:ART.21(2)(D)');
    cite($defence('Approved cloud providers', 'todo'), 'ISO27001-2022:A.5.23');
    cite($defence('Acceptable use policy', 'todo'), 'ISO27001-2022:A.5.10');
    cite($defence('Scope statement', 'todo'), 'ISO27001-2022:4.3');
    cite($defence('Endpoint hardening', 'shelved'), 'ISO27001-2022:A.8.1');
    cite($defence('Full-disk encryption', 'shelved'), 'GDPR:ART.32');
    cite($defence('TLS everywhere', 'todo'), 'GDPR:ART.32');
    cite($defence('Asset inventory', 'todo', linked: false), 'CIS8:1.1');
    // A framework cited by shelved defences only.
    cite($defence('Access reviews', 'shelved'), 'SOC2:CC6.2');

    // Not a control citation, so not in the view.
    $risk->references()->create(['kind' => 'cve', 'identifier' => 'CVE-2024-3094', 'url' => 'https://nvd.nist.gov/vuln/detail/CVE-2024-3094', 'position' => 0]);

    return $assessment->fresh();
}

function coverageOptions(bool $includeShelved): ReportOptions
{
    return new ReportOptions(baseline: 'both', includeShelved: $includeShelved, includeComments: false, includeMethodology: false);
}

/** @return array<string, mixed> */
function coverageFramework(array $report, string $code): array
{
    return collect($report['controlCoverage']['frameworks'])->firstWhere('framework', $code);
}

test('frameworks come in config order, controls in natural order, and only cited ones', function () {
    $report = app(ReportBuilder::class)->build(coverageAssessment(), coverageOptions(true));

    expect(array_column($report['controlCoverage']['frameworks'], 'framework'))->toBe(['ISO27001-2022', 'GDPR', 'NIS2', 'SOC2', 'CIS8'])
        ->and(array_column(coverageFramework($report, 'ISO27001-2022')['controls'], 'label'))->toBe(['4.3', 'A.5.10', 'A.5.23', 'A.8.1'])
        ->and(coverageFramework($report, 'ISO27001-2022')['label'])->toBe('ISO/IEC 27001:2022')
        ->and(coverageFramework($report, 'ISO27001-2022')['url'])->toBe('https://www.iso.org/standard/82875.html')
        ->and(coverageFramework($report, 'NIS2')['controls'][0]['label'])->toBe('Art. 21(2)(d)');
});

test('a control is implemented when any citing defence is, planned when one is todo, shelved otherwise', function () {
    $report = app(ReportBuilder::class)->build(coverageAssessment(), coverageOptions(true));
    $iso = collect(coverageFramework($report, 'ISO27001-2022')['controls'])->keyBy('label');

    expect($iso['A.5.23']['status'])->toBe('implemented')
        ->and(array_column($iso['A.5.23']['defences'], 'title'))->toBe(['Approved cloud providers', 'Cloud security policy'])
        ->and(array_column($iso['A.5.23']['defences'], 'status'))->toBe(['todo', 'implemented'])
        ->and($iso['A.5.10']['status'])->toBe('planned')
        ->and($iso['A.8.1']['status'])->toBe('shelved')
        ->and(coverageFramework($report, 'GDPR')['controls'][0]['status'])->toBe('planned');
});

test('a citing defence that counters no attack says so', function () {
    $report = app(ReportBuilder::class)->build(coverageAssessment(), coverageOptions(true));

    expect(coverageFramework($report, 'CIS8')['controls'][0]['defences'][0]['attackCount'])->toBe(0)
        ->and(coverageFramework($report, 'NIS2')['controls'][0]['defences'][0]['attackCount'])->toBe(1);
});

test('without shelved defences, a shelved-only control is counted rather than listed', function () {
    $report = app(ReportBuilder::class)->build(coverageAssessment(), coverageOptions(false));
    $iso = coverageFramework($report, 'ISO27001-2022');
    $gdpr = coverageFramework($report, 'GDPR');

    expect(array_column($iso['controls'], 'label'))->toBe(['4.3', 'A.5.10', 'A.5.23'])
        // Cited by a shelved and a todo defence: still listed, without the shelved one.
        ->and(array_column($gdpr['controls'][0]['defences'], 'title'))->toBe(['TLS everywhere'])
        // SOC 2 is cited by a shelved defence only: no empty framework card.
        ->and(array_column($report['controlCoverage']['frameworks'], 'framework'))->toBe(['ISO27001-2022', 'GDPR', 'NIS2', 'CIS8'])
        ->and($report['controlCoverage']['hiddenShelved'])->toBe(2);

    $section = collect((new ReportSectionBuilder)->build($report, coverageOptions(false)))
        ->firstWhere('key', 'control-coverage');
    expect($section->prose[1])->toBe('2 controls cited only by shelved defences are not listed; include shelved defences to see them.');
});

test('when every citation is shelved and left out, the section is only the count', function () {
    $assessment = Assessment::factory()->create();
    cite(Defence::factory()->create(['assessment_id' => $assessment->id, 'status' => 'shelved']), 'GDPR:ART.32');
    $report = app(ReportBuilder::class)->build($assessment->fresh(), coverageOptions(false));

    expect($report['controlCoverage'])->toBe(['frameworks' => [], 'hiddenShelved' => 1]);

    $section = collect((new ReportSectionBuilder)->build($report, coverageOptions(false)))
        ->firstWhere('key', 'control-coverage');
    expect($section->rows)->toBe([])
        ->and($section->prose[1])->toBe('1 control cited only by shelved defences is not listed; include shelved defences to see it.');
});

test('the export section has one row per citing defence, and the same statuses', function () {
    $report = app(ReportBuilder::class)->build(coverageAssessment(), coverageOptions(true));

    /** @var ReportSection $section */
    $section = collect((new ReportSectionBuilder)->build($report, coverageOptions(true)))
        ->firstWhere('key', 'control-coverage');
    $rows = array_map(fn (array $row): array => array_map(fn ($cell) => $cell->value, $row), $section->rows);

    expect($section->headers)->toBe(['Framework', 'Control', 'Control status', 'Cited by', 'Defence status', 'Attacks it counters'])
        ->and($rows)->toHaveCount(10)
        ->and($rows)->toContain(['ISO/IEC 27001:2022', 'A.5.23', 'Implemented', 'Approved cloud providers', 'todo', '1'])
        ->and($rows)->toContain(['ISO/IEC 27001:2022', 'A.8.1', 'Shelved', 'Endpoint hardening', 'shelved', '1'])
        ->and($rows)->toContain(['CIS Controls v8.1', '1.1', 'Planned', 'Asset inventory', 'todo', 'None'])
        ->and($section->prose[0])->toContain('not a statement of compliance');

    expect((new HtmlExporter)->export($report, coverageOptions(true)))->toContain('Control coverage')->toContain('Art. 21(2)(d)');
    expect((new WordExporter)->export($report, coverageOptions(true)))->toStartWith('PK');
    expect((new ExcelExporter)->export($report, coverageOptions(true)))->toStartWith('PK');
});

test('an assessment that cites no control has no coverage section at all', function () {
    $assessment = Assessment::factory()->create();
    $report = app(ReportBuilder::class)->build($assessment, coverageOptions(true));

    expect($report['controlCoverage'])->toBe(['frameworks' => [], 'hiddenShelved' => 0])
        ->and(collect((new ReportSectionBuilder)->build($report, coverageOptions(true)))->pluck('key'))->not->toContain('control-coverage');
});

test('the report page carries the coverage to the reader', function () {
    $assessment = coverageAssessment();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Report/Show')
            ->where('report.controlCoverage.frameworks.0.framework', 'ISO27001-2022')
            ->where('report.controlCoverage.frameworks.1.controls.0.status', 'planned'));
});
