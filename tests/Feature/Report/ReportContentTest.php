<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Builds calculation-engine.md §14's worked example (the first golden
 * vector: inherent €187,000, current €65,500, target €36,700) through
 * factories and asserts the report page renders exactly the figures
 * RiskCalculator already computed — never a re-derived one. Qualitative
 * inputs in the original example (R2's "moderate" impact resolving to
 * 30,000; A2's "unlikely" likelihood resolving to 0.06; D3's "strong"
 * strength resolving to 0.65) are entered here as their already-resolved
 * quantitative numbers instead of through a custom scale, since the report
 * layer never re-derives a scale resolution either — RiskCalculatorTest
 * (tests/Unit/Calculation, W7) is the place that exercises the qualitative
 * path itself.
 *
 * Rows/risks are created and linked in the same order the worked example
 * lists them, and AssessmentGraphLoader/ReportAssembler both preserve node
 * creation (position) order, so index-based assertions below (risk 0 = R1,
 * risk 1 = R2, etc.) are deterministic rather than order-dependent luck.
 */
function buildWorkedExampleAssessment(): Assessment
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
    $a2->defences()->attach($d3->id, ['position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.65, 'reduction_target' => 'likelihood']);
    $a3->defences()->attach($d3->id, ['position' => 1, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.50, 'reduction_target' => 'likelihood']);
    $a1->defences()->attach($d4->id, ['position' => 2, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.40, 'reduction_target' => null]);

    return $assessment->fresh();
}

/**
 * JSON round-trips a whole-number PHP float as a bare integer (PHP's
 * json_encode omits ".0" without JSON_PRESERVE_ZERO_FRACTION, and the
 * arithmetic behind mcAdd/attribution is not always exactly whole to begin
 * with — see calculation-engine.md §11.3 "never round intermediates").
 * `where()`'s strict assertSame() would fail on either an int-vs-float type
 * mismatch or a float epsilon, so numeric assertions in this file compare
 * via a closure instead of a literal.
 */
function closeTo(float $expected, float $delta = 1e-6): Closure
{
    return fn ($actual): bool => is_numeric($actual) && abs(((float) $actual) - $expected) < $delta;
}

test('the report renders the exact golden-vector totals for all three baselines', function () {
    $assessment = buildWorkedExampleAssessment();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Report/Show')
            ->where('report.executiveSummary.inherent.total', closeTo(187000.0))
            ->where('report.executiveSummary.current.total', closeTo(65500.0))
            ->where('report.executiveSummary.target.total', closeTo(36700.0))
            ->where('report.executiveSummary.annualCostOfControls.priced', closeTo(16000.0))
        );
});

test('the risk register reconciles risk-view ALE to the golden vector', function () {
    $assessment = buildWorkedExampleAssessment();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.riskRegister', 2)
            ->where('report.riskRegister.0.title', 'Customer data breach')
            ->where('report.riskRegister.0.current.ale', closeTo(22000.0))
            ->where('report.riskRegister.1.title', 'Service outage')
            ->where('report.riskRegister.1.current.ale', closeTo(43500.0))
        );
});

test('defences ranked by cost benefit match the closed-form marginal contribution', function () {
    $assessment = buildWorkedExampleAssessment();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.defencesByImportance.priced', 2)
            ->where('report.defencesByImportance.priced.0.title', 'MFA on admin accounts')
            ->where('report.defencesByImportance.priced.0.mc', closeTo(46000.0))
            ->where('report.defencesByImportance.priced.0.cbr', closeTo(11.5))
            ->where('report.defencesByImportance.priced.1.title', 'Cyber insurance')
            ->where('report.defencesByImportance.priced.1.mc', closeTo(29500.0))
        );
});

test('defences ranked by opportunity match the addition delta against current', function () {
    $assessment = buildWorkedExampleAssessment();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.defencesByOpportunity.priced', 1)
            ->where('report.defencesByOpportunity.priced.0.title', 'Web application firewall')
            ->where('report.defencesByOpportunity.priced.0.mcAdd', closeTo(28800.0))
        );
});

test('the shelved defence is only surfaced on demand, never inside a baseline total', function () {
    $assessment = buildWorkedExampleAssessment();

    // shelvedOpportunity carries D4's mcAdd (what unshelving would buy);
    // the exec-summary test above already proves the three baseline totals
    // reproduce the golden vector exactly with D4 shelved throughout, i.e.
    // its presence here never leaked into a total.
    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.shelvedOpportunity.priced', 1)
            ->where('report.shelvedOpportunity.priced.0.title', 'Legacy VPN hardening')
            ->where('report.shelvedOpportunity.priced.0.mcAdd', closeTo(4600.0))
        );
});

test('a partial assessment is never rendered as a bare number', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'impact_mode' => 'quantitative', 'impact_amount' => 100000]);
    $attackValued = Attack::factory()->create(['assessment_id' => $assessment->id, 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 1.0]);
    $attackMissing = Attack::factory()->withoutValue()->create(['assessment_id' => $assessment->id]);
    $risk->attacks()->attach($attackValued->id, ['position' => 0]);
    $risk->attacks()->attach($attackMissing->id, ['position' => 1]);

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.executiveSummary.current.isPartial', true)
            ->where('report.executiveSummary.current.pairsValued', 1)
            ->where('report.executiveSummary.current.pairsTotal', 2)
        );
});

test('the CSV export lists both importance and opportunity rows and never prints infinity', function () {
    $assessment = buildWorkedExampleAssessment();

    $response = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.defences-csv', $assessment));

    $response->assertOk();
    $csv = $response->getContent();

    expect($csv)->toContain('MFA on admin accounts');
    expect($csv)->toContain('Web application firewall');
    expect($csv)->not->toContain('INF');
    expect($csv)->not->toContain('Infinity');
});
