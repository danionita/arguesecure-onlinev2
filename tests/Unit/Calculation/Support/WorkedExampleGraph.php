<?php

namespace Tests\Unit\Calculation\Support;

use App\Domain\Graph\AssessmentGraph;

/**
 * Builds the calculation-engine.md §14 worked example as an AssessmentGraph,
 * independent of any Eloquent factory (RiskCalculator never sees a model).
 *
 * Scale-level representatives are set to exactly the §14 "Resolves to"
 * column, since a representative is an admin-editable per-band value
 * (schema.md §3.2 invariant 5) — not required to equal the system-default
 * geometric-mean formula in §3.2.1, which is only a pre-fill suggestion.
 */
final class WorkedExampleGraph
{
    // Impact scale (kind=impact, unit=currency).
    public const IMPACT_MODERATE_LEVEL_ID = 3;

    // Likelihood scale (kind=likelihood, unit=rate_per_year).
    public const LIKELIHOOD_UNLIKELY_LEVEL_ID = 12;

    // Strength scale (kind=strength, unit=fraction).
    public const STRENGTH_STRONG_LEVEL_ID = 24;

    public static function build(): AssessmentGraph
    {
        return new AssessmentGraph(
            assessmentUuid: 'assessment-14',
            currency: 'EUR',
            amortizationYears: 3,
            rootTitle: 'Worked example',
            rootDescription: null,
            risks: [
                'r1' => ['uuid' => 'r1', 'title' => 'Customer data breach', 'impact_mode' => 'quantitative', 'impact_amount' => 200000.0, 'impact_level_id' => null],
                'r2' => ['uuid' => 'r2', 'title' => 'Service outage', 'impact_mode' => 'qualitative', 'impact_amount' => null, 'impact_level_id' => self::IMPACT_MODERATE_LEVEL_ID],
            ],
            attacks: [
                'a1' => ['uuid' => 'a1', 'title' => 'Phishing of admin credentials', 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 0.5, 'likelihood_level_id' => null],
                'a2' => ['uuid' => 'a2', 'title' => 'SQL injection on portal', 'likelihood_mode' => 'qualitative', 'likelihood_aro' => null, 'likelihood_level_id' => self::LIKELIHOOD_UNLIKELY_LEVEL_ID],
                'a3' => ['uuid' => 'a3', 'title' => 'Volumetric DDoS', 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 2.0, 'likelihood_level_id' => null],
            ],
            defences: [
                'd1' => ['uuid' => 'd1', 'title' => 'MFA on admin accounts', 'is_transfer' => false, 'status' => 'implemented', 'capex_amount' => null, 'opex_annual_amount' => 4000.0],
                'd2' => ['uuid' => 'd2', 'title' => 'Cyber insurance', 'is_transfer' => true, 'status' => 'implemented', 'capex_amount' => null, 'opex_annual_amount' => 12000.0],
                'd3' => ['uuid' => 'd3', 'title' => 'Web application firewall', 'is_transfer' => false, 'status' => 'todo', 'capex_amount' => null, 'opex_annual_amount' => 10000.0],
                'd4' => ['uuid' => 'd4', 'title' => 'Legacy VPN hardening', 'is_transfer' => false, 'status' => 'shelved', 'capex_amount' => null, 'opex_annual_amount' => 2000.0],
            ],
            riskAttackLinks: [
                ['risk_uuid' => 'r1', 'attack_uuid' => 'a1', 'position' => 0],
                ['risk_uuid' => 'r2', 'attack_uuid' => 'a1', 'position' => 1],
                ['risk_uuid' => 'r1', 'attack_uuid' => 'a2', 'position' => 2],
                ['risk_uuid' => 'r2', 'attack_uuid' => 'a3', 'position' => 3],
            ],
            attackDefenceLinks: [
                ['attack_uuid' => 'a1', 'defence_uuid' => 'd1', 'position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.80, 'strength_level_id' => null, 'reduction_target' => 'likelihood'],
                ['attack_uuid' => 'a1', 'defence_uuid' => 'd2', 'position' => 1, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.50, 'strength_level_id' => null, 'reduction_target' => 'impact'],
                ['attack_uuid' => 'a2', 'defence_uuid' => 'd3', 'position' => 2, 'strength_mode' => 'qualitative', 'strength_fraction' => null, 'strength_level_id' => self::STRENGTH_STRONG_LEVEL_ID, 'reduction_target' => 'likelihood'],
                ['attack_uuid' => 'a3', 'defence_uuid' => 'd2', 'position' => 3, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.30, 'strength_level_id' => null, 'reduction_target' => 'impact'],
                ['attack_uuid' => 'a3', 'defence_uuid' => 'd3', 'position' => 4, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.50, 'strength_level_id' => null, 'reduction_target' => 'likelihood'],
                ['attack_uuid' => 'a1', 'defence_uuid' => 'd4', 'position' => 5, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.40, 'strength_level_id' => null, 'reduction_target' => null],
            ],
            scaleLevels: self::scaleLevels(),
        );
    }

    /**
     * The §14 partial-data variant: A2's likelihood is blanked, so (R1,A2)
     * drops out of the total (§15 item 6, §14 "Partial-data variant").
     */
    public static function buildWithoutA2Likelihood(): AssessmentGraph
    {
        $g = self::build();

        $attacks = $g->attacks;
        $attacks['a2'] = ['uuid' => 'a2', 'title' => 'SQL injection on portal', 'likelihood_mode' => null, 'likelihood_aro' => null, 'likelihood_level_id' => null];

        return new AssessmentGraph(
            assessmentUuid: $g->assessmentUuid,
            currency: $g->currency,
            amortizationYears: $g->amortizationYears,
            rootTitle: $g->rootTitle,
            rootDescription: $g->rootDescription,
            risks: $g->risks,
            attacks: $attacks,
            defences: $g->defences,
            riskAttackLinks: $g->riskAttackLinks,
            attackDefenceLinks: $g->attackDefenceLinks,
            scaleLevels: $g->scaleLevels,
        );
    }

    /**
     * @return array<int, array{id: int, scale_id: int, kind: string, unit: string, code: string, label: string, ordinal: int, lower_bound: float, upper_bound: ?float, representative: float}>
     */
    public static function scaleLevels(): array
    {
        $impact = [
            [1, 'negligible', 0, 1000, 250],
            [2, 'minor', 1000, 10000, 3162],
            [3, 'moderate', 10000, 100000, 30000], // §14's "Resolves to" value
            [4, 'major', 100000, 1000000, 316228],
            [5, 'severe', 1000000, null, 3000000],
        ];

        $likelihood = [
            [11, 'rare', 0, 0.02, 0.005],
            [12, 'unlikely', 0.02, 0.2, 0.06],
            [13, 'possible', 0.2, 1, 0.45],
            [14, 'likely', 1, 10, 3.16],
            [15, 'frequent', 10, null, 30],
        ];

        // §14 labels the 0.5-0.8 band "strong" with representative 0.65
        // (rather than the system default's "moderate" for that band) —
        // both are valid per-band labels; representative just has to sit
        // within [lower, upper) (schema.md §3.2 invariant 5).
        $strength = [
            [21, 'weak', 0, 0.2, 0.1],
            [22, 'limited', 0.2, 0.5, 0.35],
            [24, 'strong', 0.5, 0.8, 0.65],
            [23, 'very_strong', 0.8, 0.95, 0.875],
            [25, 'near_certain', 0.95, 1, 0.975],
        ];

        $levels = [];

        foreach ([['impact', 'currency', 1, $impact], ['likelihood', 'rate_per_year', 2, $likelihood], ['strength', 'fraction', 3, $strength]] as [$kind, $unit, $scaleId, $bands]) {
            foreach ($bands as $ordinal => [$id, $code, $lower, $upper, $representative]) {
                $levels[$id] = [
                    'id' => $id,
                    'scale_id' => $scaleId,
                    'kind' => $kind,
                    'unit' => $unit,
                    'code' => $code,
                    'label' => ucfirst(str_replace('_', ' ', $code)),
                    'ordinal' => $ordinal + 1,
                    'lower_bound' => (float) $lower,
                    'upper_bound' => $upper === null ? null : (float) $upper,
                    'representative' => (float) $representative,
                ];
            }
        }

        return $levels;
    }
}
