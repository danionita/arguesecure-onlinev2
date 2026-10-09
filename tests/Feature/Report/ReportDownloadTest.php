<?php

use App\Domain\Reporting\ReportOptions;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * W33 — the programme summary and the one-click download.
 *
 * The same worked example ReportContentTest builds (calculation-engine.md
 * §14: inherent €187,000, current €65,500, target €36,700), rebuilt here
 * under its own name rather than shared: a Pest helper defined in one test
 * file is only guaranteed to exist while that file is the one running.
 *
 * The only planned control is the WAF at €10,000/yr, so the plan costs
 * €10,000 and moves the total by €28,800 — which is the figure a reader
 * actually arrives with a question about, and the one the 2.0 report never
 * showed anywhere.
 */
function buildProgrammeExample(): Assessment
{
    $assessment = Assessment::factory()->create(['currency' => 'EUR', 'amortization_years' => 3]);

    $r1 = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Customer data breach', 'impact_mode' => 'quantitative', 'impact_amount' => 200000, 'impact_level_id' => null]);
    $r2 = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Service outage', 'impact_mode' => 'quantitative', 'impact_amount' => 30000, 'impact_level_id' => null]);

    $a1 = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing of admin credentials', 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 0.5, 'likelihood_level_id' => null]);
    $a2 = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'SQL injection on portal', 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 0.06, 'likelihood_level_id' => null]);
    $a3 = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Volumetric DDoS', 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 2.0, 'likelihood_level_id' => null]);

    $d1 = Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => 'MFA on admin accounts', 'status' => 'implemented', 'capex_amount' => 0, 'opex_annual_amount' => 4000, 'is_transfer' => false]);
    $d2 = Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Cyber insurance', 'status' => 'implemented', 'capex_amount' => 0, 'opex_annual_amount' => 12000, 'is_transfer' => true]);
    $d3 = Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Web application firewall', 'status' => 'todo', 'capex_amount' => 0, 'opex_annual_amount' => 10000, 'is_transfer' => false]);
    $d4 = Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Legacy VPN hardening', 'status' => 'shelved', 'capex_amount' => 0, 'opex_annual_amount' => 2000, 'is_transfer' => false]);

    $r1->attacks()->attach($a1->id, ['position' => 0]);
    $r2->attacks()->attach($a1->id, ['position' => 0]);
    $r1->attacks()->attach($a2->id, ['position' => 1]);
    $r2->attacks()->attach($a3->id, ['position' => 1]);

    $a1->defences()->attach($d1->id, ['position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.80, 'reduction_target' => 'likelihood']);
    $a1->defences()->attach($d2->id, ['position' => 1, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.50, 'reduction_target' => 'impact']);
    $a3->defences()->attach($d2->id, ['position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.30, 'reduction_target' => 'impact']);
    $a2->defences()->attach($d3->id, ['position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.65, 'reduction_target' => 'likelihood', 'rationale' => 'Vendor benchmark against the OWASP top ten.']);
    $a3->defences()->attach($d3->id, ['position' => 1, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.50, 'reduction_target' => 'likelihood']);
    $a1->defences()->attach($d4->id, ['position' => 2, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.40, 'reduction_target' => null]);

    return $assessment->fresh();
}

/** JSON round-trips whole floats as ints; compare numerically, not strictly. */
function programmeCloseTo(float $expected, float $delta = 1e-6): Closure
{
    return fn ($actual): bool => is_numeric($actual) && abs(((float) $actual) - $expected) < $delta;
}

test('the programme summary costs the plan and states what it buys, in currency', function () {
    $assessment = buildProgrammeExample();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.programme.planned.count', 1)
            ->where('report.programme.planned.annualisedCost', programmeCloseTo(10000.0))
            ->where('report.programme.planned.recurringCost', programmeCloseTo(10000.0))
            ->where('report.programme.planned.oneOffCost', programmeCloseTo(0.0))
            ->where('report.programme.riskReduced.inherentToCurrent', programmeCloseTo(121500.0))
            ->where('report.programme.riskReduced.currentToTarget', programmeCloseTo(28800.0))
            ->where('report.programme.riskReduced.inherentToTarget', programmeCloseTo(150300.0))
            ->where('report.programme.returnOnPlanned', programmeCloseTo(2.88))
        );
});

test('a one-off cost is amortised into the annualised figure and also stated on its own', function () {
    $assessment = buildProgrammeExample();

    // Through the model, not the query builder: TriggersRecalculation is a
    // model observer, so a builder-level update writes the row without
    // bumping assessments.calc_version — and CalculationCache keys on that
    // version, so the report would then be served pre-change figures. That
    // is the documented design (calculation-engine.md §11.4), and it is a
    // real trap for a test that edits data behind the application's back.
    //
    // amortization_years is 3, so EUR 30,000 up front adds 10,000/yr to the
    // 10,000 already recurring.
    $waf = Defence::query()->where('title', 'Web application firewall')->firstOrFail();
    $waf->capex_amount = 30000;
    $waf->save();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.programme.planned.oneOffCost', programmeCloseTo(30000.0))
            ->where('report.programme.planned.recurringCost', programmeCloseTo(10000.0))
            ->where('report.programme.planned.annualisedCost', programmeCloseTo(20000.0))
        );
});

test('a plan with no cost recorded is counted, never totalled, and returns null rather than infinity', function () {
    $assessment = buildProgrammeExample();

    $waf = Defence::query()->where('title', 'Web application firewall')->firstOrFail();
    $waf->capex_amount = null;
    $waf->opex_annual_amount = null;
    $waf->save();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.programme.planned.unpricedCount', 1)
            ->where('report.programme.planned.annualisedCost', programmeCloseTo(0.0))
            ->where('report.programme.returnOnPlanned', null)
        );
});

/*
 * The download path. `download=1` overriding the rest of the query string is
 * the assertion that matters most in this package: a link carrying
 * `baseline=current` that merged instead would produce a document which looks
 * complete while silently omitting the target state and every shelved
 * control — invisible on screen, and wrong in a client's hands.
 */

test('download=1 forces the whole report at the options level, ignoring a stale query string', function () {
    $options = ReportOptions::fromQuery([
        'download' => '1',
        'baseline' => 'current',
        'include_shelved' => '0',
        'include_comments' => '0',
        'include_methodology' => '0',
        // W80 removed `figures`. The key stays in this fixture because the
        // test is about a *stale* query string, and an unknown parameter
        // being ignored rather than erroring is part of what it asserts.
        'figures' => 'qualitative',
    ]);

    expect($options->download)->toBeTrue()
        ->and($options->baseline)->toBe('both')
        ->and($options->includeShelved)->toBeTrue()
        ->and($options->includeComments)->toBeTrue()
        ->and($options->includeMethodology)->toBeTrue();
});

test('the download route serves that forced set to the page', function () {
    $assessment = buildProgrammeExample();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment).'?download=1&baseline=current&include_shelved=0')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.options.download', true)
            ->where('report.options.baseline', 'both')
            ->where('report.options.includeShelved', true)
        );
});

test('a report requested without download=1 keeps the options the reader chose', function () {
    $assessment = buildProgrammeExample();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment).'?baseline=current')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.options.download', false)
            ->where('report.options.baseline', 'current')
        );
});

test('the full-assessment tree carries every figure, including inherent and the per-link rationale', function () {
    $assessment = buildProgrammeExample();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment).'?download=1')
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.tree.risks', 2)
            ->where('report.tree.risks.0.title', 'Customer data breach')
            ->where('report.tree.risks.0.inherent.ale', programmeCloseTo(112000.0))
            ->where('report.tree.risks.0.attacks.1.title', 'SQL injection on portal')
            ->where('report.tree.risks.0.attacks.1.defences.0.title', 'Web application firewall')
            ->where('report.tree.risks.0.attacks.1.defences.0.strength', programmeCloseTo(0.65))
            ->where('report.tree.risks.0.attacks.1.defences.0.rationale', 'Vendor benchmark against the OWASP top ten.')
            ->where('report.tree.risks.0.attacks.1.defences.0.target.annualCost', programmeCloseTo(10000.0))
        );
});
