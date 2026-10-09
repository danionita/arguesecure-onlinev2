<?php

use App\Domain\Reporting\Export\AssessmentCsvExporter;
use App\Domain\Reporting\Export\ExcelExporter;
use App\Domain\Reporting\Export\HtmlExporter;
use App\Domain\Reporting\Export\WordExporter;
use App\Domain\Reporting\ReportBuilder;
use App\Domain\Reporting\ReportOptions;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;

/*
 * "Generation is not synchronous if it is slow."
 *
 * The brief sets the test: a 40-control assessment rendering five formats is
 * fine; measure it, and if any format exceeds a second or two it goes to the
 * queue and reports back through the W47 notification path. This file is the
 * measurement, kept rather than run once and thrown away — the number is
 * what decides the architecture, and a decision made on a number nobody can
 * reproduce is a decision that gets re-litigated every time somebody adds a
 * section.
 *
 * The ceiling below is deliberately loose (5s for all five formats of a
 * 40-control assessment). This is a wall-clock assertion on shared CI, where
 * a strict bound is a flaky test, and its job is not to police milliseconds
 * — it is to fail loudly if a format ever becomes *seconds* slow, which is
 * the threshold at which the answer stops being "render it in the request"
 * and becomes "queue it". `docs/RELEASE` carries the measured figures.
 */

function performanceAssessment(int $controls): Assessment
{
    $assessment = Assessment::factory()->create(['currency' => 'EUR', 'amortization_years' => 3]);

    // 8 risks x 5 attacks each, with `$controls` defences spread across them:
    // roughly the shape of a real assessment of that size rather than one
    // risk carrying forty controls, which would be a cheaper graph.
    $risks = [];
    for ($r = 0; $r < 8; $r++) {
        $risks[] = Risk::factory()->create([
            'assessment_id' => $assessment->id,
            'title' => "Risk {$r}",
            'impact_mode' => 'quantitative',
            'impact_amount' => 10000 * ($r + 1),
            'impact_level_id' => null,
        ]);
    }

    $attacks = [];
    for ($a = 0; $a < 40; $a++) {
        $attack = Attack::factory()->create([
            'assessment_id' => $assessment->id,
            'title' => "Attack {$a}",
            'likelihood_mode' => 'quantitative',
            'likelihood_aro' => 0.25,
            'likelihood_level_id' => null,
        ]);
        $risks[$a % 8]->attacks()->attach($attack->id, ['position' => intdiv($a, 8)]);
        $attacks[] = $attack;
    }

    for ($d = 0; $d < $controls; $d++) {
        $defence = Defence::factory()->create([
            'assessment_id' => $assessment->id,
            'title' => "Control {$d}",
            'status' => ['implemented', 'todo', 'shelved'][$d % 3],
            'capex_amount' => 1000 * $d,
            'opex_annual_amount' => 500 * $d,
            'is_transfer' => $d % 7 === 0,
        ]);

        // Two attacks each, at different strengths — the edge property that
        // makes this a DAG rather than a tree, and the reason the tree
        // section is the widest table in the report.
        $attacks[$d % 40]->defences()->attach($defence->id, [
            'position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.4, 'reduction_target' => 'likelihood',
        ]);
        $attacks[($d + 13) % 40]->defences()->attach($defence->id, [
            'position' => 1, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.2, 'reduction_target' => 'impact',
        ]);
    }

    return $assessment->fresh();
}

test('a 40-control assessment renders all five formats well inside the synchronous budget', function () {
    $assessment = performanceAssessment(40);
    $options = ReportOptions::forDownload();

    $start = microtime(true);
    $report = app(ReportBuilder::class)->build($assessment, $options);
    $assembleMs = (microtime(true) - $start) * 1000;

    $timings = ['assemble' => $assembleMs];

    foreach ([
        'html' => fn () => (new HtmlExporter)->export($report, $options),
        'csv' => fn () => (new AssessmentCsvExporter)->export($report, $options),
        'json' => fn () => json_encode($report),
        'xlsx' => fn () => (new ExcelExporter)->export($report, $options),
        'docx' => fn () => (new WordExporter)->export($report, $options),
    ] as $format => $render) {
        $started = microtime(true);
        $bytes = $render();
        $timings[$format] = (microtime(true) - $started) * 1000;

        expect($bytes)->not->toBeEmpty();
    }

    // Printed rather than only asserted: the brief asks for the measurement,
    // and `--display-output` (or a failure) shows it. The assertion is the
    // guard; the numbers are the evidence.
    fwrite(STDERR, "\n  export timings (ms): ".json_encode(array_map(
        fn (float $ms): float => round($ms, 1),
        $timings,
    ))."\n");

    $renderTotal = array_sum($timings) - $assembleMs;

    // If this ever fails, the answer is not a bigger number here. It is the
    // queue: dispatch the render as a job and deliver it through the W47
    // notification path, which exists for exactly this.
    expect($renderTotal)->toBeLessThan(5000.0);
})->group('performance');

test('the report loads in a fixed number of queries regardless of how big it is', function () {
    // The N+1 CLAUDE.md names as the legacy tree render's defining defect
    // would not show up as a failure above — it would show up as a report
    // that is fine at 40 controls and unusable at 400. Counting queries
    // catches it at any size.
    $small = performanceAssessment(4);
    $large = performanceAssessment(40);

    $count = function (Assessment $assessment): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReportBuilder::class)->build($assessment, ReportOptions::forDownload());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count($large))->toBe($count($small));
})->group('performance');
