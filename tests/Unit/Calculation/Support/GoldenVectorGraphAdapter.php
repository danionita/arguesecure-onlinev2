<?php

namespace Tests\Unit\Calculation\Support;

use App\Domain\Graph\AssessmentGraph;

/**
 * Translates one golden-vector `input` block (tests/fixtures/calculation-vectors.json,
 * see tests/fixtures/README.md for the format) into an AssessmentGraph — the
 * only shape RiskCalculator accepts (calculation-engine.md §11.1).
 *
 * This is the fixture-to-graph boundary the W7 reconciliation brief asks to
 * keep "clearly separated and readable", since W11's TypeScript mirror will
 * need an equivalent translation of the same fixture.
 *
 * Two deliberate adapter-only decisions, neither of which touches the
 * fixture or the engine:
 *
 * 1. Scale levels are addressed by `code` in the fixture (readable ids like
 *    "moderate") but by integer `*_level_id` in AssessmentGraph (schema.md
 *    §4). This class assigns synthetic sequential ids per vector and keeps
 *    the code=>id map only long enough to resolve the risks/attacks/links
 *    that reference a level by code.
 * 2. Several vectors declare a scale with `"levels": []` because they only
 *    exercise quantitative values and don't care about qualitative mapping
 *    (see tests/fixtures/README.md). schema.md §3.2 invariant 3 guarantees
 *    a real scale always has `level[1].lower_bound == 0` and at least one
 *    band — an empty scale never reaches the engine in production. But
 *    RiskCalculator unconditionally band-maps every valued quantitative
 *    risk/attack to compute `impact_level_code`/`aro_level_code` for
 *    display (§11.2 step 8), so an empty scale makes ScaleMapper::toLevel()
 *    throw. Rather than mutate the fixture's intentionally-minimal scale
 *    declarations or special-case RiskCalculator, this adapter fills in a
 *    single schema-valid catch-all band `[0, ∞)` with representative 0 for
 *    any scale the vector declares empty — mirroring the existing
 *    `minimalScaleLevels()` convention in CalculationRulesTest.php. No
 *    vector asserts `impact_level_code`/`aro_level_code` against one of
 *    these synthesized bands, so this never masks a real assertion.
 */
final class GoldenVectorGraphAdapter
{
    /**
     * @param  array<string, mixed>  $input  the vector's "input" block (already `$ref_note`-resolved by the caller)
     */
    public static function build(array $input): AssessmentGraph
    {
        [$scaleLevels, $codeMaps] = self::buildScaleLevels($input['scales'] ?? []);

        $risks = [];
        foreach ($input['risks'] ?? [] as $r) {
            $risks[$r['uuid']] = [
                'uuid' => $r['uuid'],
                'title' => $r['name'] ?? $r['uuid'],
                'impact_mode' => $r['impact_mode'] ?? null,
                'impact_amount' => self::floatOrNull($r['impact_amount'] ?? null),
                'impact_level_id' => self::levelId($codeMaps['impact'], $r['impact_level_code'] ?? null),
            ];
        }

        $attacks = [];
        foreach ($input['attacks'] ?? [] as $a) {
            $attacks[$a['uuid']] = [
                'uuid' => $a['uuid'],
                'title' => $a['name'] ?? $a['uuid'],
                'likelihood_mode' => $a['likelihood_mode'] ?? null,
                'likelihood_aro' => self::floatOrNull($a['likelihood_amount'] ?? null),
                'likelihood_level_id' => self::levelId($codeMaps['likelihood'], $a['likelihood_level_code'] ?? null),
            ];
        }

        $defences = [];
        foreach ($input['defences'] ?? [] as $d) {
            $defences[$d['uuid']] = [
                'uuid' => $d['uuid'],
                'title' => $d['name'] ?? $d['uuid'],
                'is_transfer' => (bool) ($d['is_transfer'] ?? false),
                'status' => $d['status'] ?? null,
                'capex_amount' => self::floatOrNull($d['capex_amount'] ?? null),
                'opex_annual_amount' => self::floatOrNull($d['opex_annual_amount'] ?? null),
            ];
        }

        $riskAttackLinks = [];
        foreach (array_values($input['risk_attack'] ?? []) as $i => $ra) {
            $riskAttackLinks[] = [
                'risk_uuid' => $ra['risk_uuid'],
                'attack_uuid' => $ra['attack_uuid'],
                'position' => $ra['position'] ?? $i,
            ];
        }

        $attackDefenceLinks = [];
        foreach (array_values($input['attack_defence'] ?? []) as $i => $l) {
            $attackDefenceLinks[] = [
                'attack_uuid' => $l['attack_uuid'],
                'defence_uuid' => $l['defence_uuid'],
                'position' => $l['position'] ?? $i,
                'strength_mode' => $l['strength_mode'] ?? null,
                'strength_fraction' => self::floatOrNull($l['strength_fraction'] ?? null),
                'strength_level_id' => self::levelId($codeMaps['strength'], $l['strength_level_code'] ?? null),
                'reduction_target' => $l['reduction_target'] ?? null,
            ];
        }

        $assessment = $input['assessment'] ?? [];

        return new AssessmentGraph(
            assessmentUuid: 'golden-vector',
            currency: $assessment['currency'] ?? 'EUR',
            amortizationYears: (int) ($assessment['amortization_years'] ?? 1),
            rootTitle: null,
            rootDescription: null,
            risks: $risks,
            attacks: $attacks,
            defences: $defences,
            riskAttackLinks: $riskAttackLinks,
            attackDefenceLinks: $attackDefenceLinks,
            scaleLevels: $scaleLevels,
            scales: self::buildScales($input['scales'] ?? []),
        );
    }

    private static function floatOrNull(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    /**
     * @param  array<string, int>  $codeMap
     */
    private static function levelId(array $codeMap, ?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        return $codeMap[$code] ?? null;
    }

    /**
     * Builds the flat `scaleLevels` array AssessmentGraph expects, plus a
     * per-kind `code => id` map used only by this class to resolve the
     * fixture's `*_level_code` fields.
     *
     * @param  array<string, mixed>  $scalesInput  the vector's "input.scales" block
     * @return array{0: array<int, array{id: int, scale_id: int, kind: string, unit: string, code: string, label: string, ordinal: int, lower_bound: float, upper_bound: ?float, representative: float}>, 1: array{impact: array<string, int>, likelihood: array<string, int>, strength: array<string, int>}}
     */
    public static function buildScaleLevels(array $scalesInput): array
    {
        $levels = [];
        $codeMaps = ['impact' => [], 'likelihood' => [], 'strength' => []];
        $nextId = 1;
        $scaleIds = ['impact' => 1, 'likelihood' => 2, 'strength' => 3];
        $defaultUnits = ['impact' => 'currency', 'likelihood' => 'rate_per_year', 'strength' => 'fraction'];

        foreach (['impact', 'likelihood', 'strength'] as $kind) {
            $scale = $scalesInput[$kind] ?? null;
            $unit = $scale['unit'] ?? $defaultUnits[$kind];
            $rawLevels = $scale['levels'] ?? [];

            if ($rawLevels === []) {
                // See class docblock point 2: a schema-valid catch-all band
                // standing in for a vector that declared an empty scale.
                $id = $nextId++;
                $levels[$id] = [
                    'id' => $id,
                    'scale_id' => $scaleIds[$kind],
                    'kind' => $kind,
                    'unit' => $unit,
                    'code' => '_adapter_fallback',
                    'label' => 'Fallback (unused by this vector)',
                    'ordinal' => 1,
                    'lower_bound' => 0.0,
                    'upper_bound' => null,
                    'representative' => 0.0,
                ];

                continue;
            }

            foreach ($rawLevels as $ord => $lvl) {
                $id = $nextId++;
                $codeMaps[$kind][$lvl['code']] = $id;
                $levels[$id] = [
                    'id' => $id,
                    'scale_id' => $scaleIds[$kind],
                    'kind' => $kind,
                    'unit' => $unit,
                    'code' => $lvl['code'],
                    'label' => $lvl['label'] ?? $lvl['code'],
                    'ordinal' => $lvl['ordinal'] ?? ($ord + 1),
                    'lower_bound' => (float) $lvl['lower_bound'],
                    'upper_bound' => $lvl['upper_bound'] === null ? null : (float) $lvl['upper_bound'],
                    'representative' => (float) $lvl['representative'],
                ];
            }
        }

        return [$levels, $codeMaps];
    }

    /**
     * The parent scale rows behind `scaleLevels`, carrying the `is_system` flag
     * `uncalibrated_scales` is decided from (calculation-engine.md §12).
     *
     * A kind the vector omits entirely gets no entry: the band synthesized for
     * it in buildScaleLevels() stands in for a scale the vector never exercises,
     * and claiming it is an unmodified system default would fire the warning on
     * ten vectors that say nothing about calibration. Absent `is_system` on a
     * scale the vector *does* declare means false — the vector defined its own
     * bands, which is what calibration is.
     *
     * @param  array<string, mixed>  $scalesInput  the vector's "input.scales" block
     * @return array<int, array{id: int, kind: string, unit: string, is_system: bool}>
     */
    public static function buildScales(array $scalesInput): array
    {
        $scaleIds = ['impact' => 1, 'likelihood' => 2, 'strength' => 3];
        $defaultUnits = ['impact' => 'currency', 'likelihood' => 'rate_per_year', 'strength' => 'fraction'];
        $scales = [];

        foreach (['impact', 'likelihood', 'strength'] as $kind) {
            $scale = $scalesInput[$kind] ?? null;

            if (! is_array($scale)) {
                continue;
            }

            $id = $scaleIds[$kind];
            $scales[$id] = [
                'id' => $id,
                'kind' => $kind,
                'unit' => $scale['unit'] ?? $defaultUnits[$kind],
                'is_system' => (bool) ($scale['is_system'] ?? false),
            ];
        }

        return $scales;
    }
}
