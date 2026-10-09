<?php

namespace Tests\Feature\Reporting;

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;

/**
 * calculation-engine.md §14's worked example — the first golden vector:
 * inherent €187,000, current €65,500, target €36,700 — built for the export
 * tests in this directory.
 *
 * A class rather than the global function `tests/Feature/Report/ReportContentTest.php`
 * uses, and deliberately: Pest exposes a function defined in a test file
 * globally once that file has been loaded, so calling that one from here
 * would make these tests pass or fail depending on which file the runner
 * reached first — the kind of order dependence that surfaces as a mystery
 * failure on someone else's machine six weeks later. A PSR-4 class is loaded
 * when it is named and never runs as a test.
 *
 * The figures are the point: an export that disagreed with them would be
 * disagreeing with a number derived by hand in the spec, not merely with
 * another part of this codebase.
 */
final class ExportFixture
{
    public static function assessment(): Assessment
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
     * Every export route, by name, in menu order.
     *
     * The list is what makes "the menu is one thing" testable: a sixth format
     * added without an authorization test would have to be added here to be
     * reachable, and every test below that iterates this list would then
     * cover it. A per-route test that someone forgets to copy is the failure
     * mode this avoids.
     *
     * @return list<string>
     */
    public static function routeNames(): array
    {
        return [
            'assessments.report.export-html',
            'assessments.report.defences-csv',
            'assessments.report.export-json',
            'assessments.report.export-excel',
            'assessments.report.export-word',
        ];
    }
}
