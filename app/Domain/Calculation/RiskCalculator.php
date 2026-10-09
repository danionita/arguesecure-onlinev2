<?php

namespace App\Domain\Calculation;

use App\Domain\Graph\AssessmentGraph;

/**
 * The engine (calculation-engine.md §3, §11). **Pure**: no Eloquent import,
 * no I/O, no clock, no randomness — asserted by an architecture test
 * (tests/Unit/Calculation/RiskCalculatorPurityTest.php, §15 item 14).
 * Accepts only an AssessmentGraph DTO and returns plain value objects.
 *
 * Single pass, per §11.2: resolve every value once (via GraphResolver),
 * then derive the three baselines by filtering the same per-attack link
 * list by defence status — they differ only in which links are "active".
 *
 * @phpstan-import-type ScaleLevelData from AssessmentGraph
 * @phpstan-import-type ResolvedLink from GraphResolver
 *
 * @phpstan-type AttackFactorData array{factor: float, productL: float, productI: float, productC: float, activeLinks: list<ResolvedLink>}
 */
final class RiskCalculator
{
    private readonly ScaleMapper $mapper;

    private readonly GraphResolver $resolver;

    private readonly DefenceRanker $ranker;

    public function __construct()
    {
        $this->mapper = new ScaleMapper;
        $this->resolver = new GraphResolver($this->mapper);
        $this->ranker = new DefenceRanker($this->resolver);
    }

    public function compute(AssessmentGraph $g): CalculationResults
    {
        $impacts = $this->resolver->resolveImpacts($g);
        $aros = $this->resolver->resolveAros($g);
        $links = $this->resolver->resolveLinks($g);

        $linksByAttack = [];
        $linksByDefence = [];
        foreach ($links as $link) {
            $linksByAttack[$link['attack_uuid']][] = $link;
            $linksByDefence[$link['defence_uuid']][] = $link;
        }

        $riskAttackByRisk = [];
        $riskAttackByAttack = [];
        foreach ($g->riskAttackLinks as $ra) {
            $riskAttackByRisk[$ra['risk_uuid']][] = $ra['attack_uuid'];
            $riskAttackByAttack[$ra['attack_uuid']][] = $ra['risk_uuid'];
        }

        $impactLevels = $this->levelsOfKind($g->scaleLevels, 'impact');
        $likelihoodLevels = $this->levelsOfKind($g->scaleLevels, 'likelihood');

        $structural = [
            'risksWithoutAttacks' => array_values(array_filter(
                array_keys($g->risks),
                fn (string $uuid): bool => ($riskAttackByRisk[$uuid] ?? []) === [],
            )),
            'orphanAttacks' => array_values(array_filter(
                array_keys($g->attacks),
                fn (string $uuid): bool => ($riskAttackByAttack[$uuid] ?? []) === [],
            )),
            'orphanDefences' => array_values(array_filter(
                array_keys($g->defences),
                fn (string $uuid): bool => ($linksByDefence[$uuid] ?? []) === [],
            )),
            'sharedAttacks' => array_values(array_filter(array_map(
                fn (string $uuid): array => ['uuid' => $uuid, 'risk_count' => count($riskAttackByAttack[$uuid] ?? [])],
                array_keys($g->attacks),
            ), fn (array $row): bool => $row['risk_count'] >= 2)),
        ];

        return new CalculationResults(
            inherent: $this->computeBaseline('inherent', $g, $impacts, $aros, $linksByAttack, $linksByDefence, $riskAttackByAttack, $impactLevels, $likelihoodLevels, $structural),
            current: $this->computeBaseline('current', $g, $impacts, $aros, $linksByAttack, $linksByDefence, $riskAttackByAttack, $impactLevels, $likelihoodLevels, $structural),
            target: $this->computeBaseline('target', $g, $impacts, $aros, $linksByAttack, $linksByDefence, $riskAttackByAttack, $impactLevels, $likelihoodLevels, $structural),
        );
    }

    /**
     * @param  array<string, ?float>  $impacts
     * @param  array<string, ?float>  $aros
     * @param  array<string, list<ResolvedLink>>  $linksByAttack
     * @param  array<string, list<ResolvedLink>>  $linksByDefence
     * @param  array<string, list<string>>  $riskAttackByAttack
     * @param  list<ScaleLevelData>  $impactLevels
     * @param  list<ScaleLevelData>  $likelihoodLevels
     * @param  array{risksWithoutAttacks: list<string>, orphanAttacks: list<string>, orphanDefences: list<string>, sharedAttacks: list<array{uuid: string, risk_count: int}>}  $structural
     */
    private function computeBaseline(
        string $baseline,
        AssessmentGraph $g,
        array $impacts,
        array $aros,
        array $linksByAttack,
        array $linksByDefence,
        array $riskAttackByAttack,
        array $impactLevels,
        array $likelihoodLevels,
        array $structural,
    ): CalculationResult {
        $activeDefenceUuids = $this->resolver->baselineSet($g, $baseline);

        // Step 3 (§11.2): per attack, the active link list and factor(a,S).
        /** @var array<string, AttackFactorData> $attackData */
        $attackData = [];
        foreach (array_keys($g->attacks) as $attackUuid) {
            $activeLinks = array_values(array_filter(
                $linksByAttack[$attackUuid] ?? [],
                fn (array $l): bool => isset($activeDefenceUuids[$l['defence_uuid']]),
            ));

            $factors = $this->resolver->attackFactors($activeLinks);

            $attackData[$attackUuid] = [
                'factor' => $factors['factor'],
                'productL' => $factors['productL'],
                'productI' => $factors['productI'],
                'productC' => $factors['productC'],
                'activeLinks' => $activeLinks,
            ];
        }

        // Step 4: per pair, ARO', Imp', ALE.
        $pairs = [];
        $riskAleSum = [];
        $riskPairsValued = [];
        $riskPairsTotal = [];
        $riskAroSumForProbability = [];
        $attackAleSum = [];

        foreach ($g->riskAttackLinks as $ra) {
            $riskUuid = $ra['risk_uuid'];
            $attackUuid = $ra['attack_uuid'];
            $data = $attackData[$attackUuid];

            $impact0 = $impacts[$riskUuid] ?? null;
            $aro0 = $aros[$attackUuid] ?? null;
            $valued = $impact0 !== null && $aro0 !== null;

            $aroResidual = $aro0 !== null ? $aro0 * $data['productL'] : null;
            $impactResidual = $impact0 !== null ? $impact0 * $data['productI'] : null;
            $ale = $valued ? $aro0 * $impact0 * $data['factor'] : null;

            $pairs[] = new PairResult(
                riskUuid: $riskUuid,
                attackUuid: $attackUuid,
                aroResidual: $aroResidual,
                impactResidual: $impactResidual,
                ale: $ale,
                valued: $valued,
            );

            $riskPairsTotal[$riskUuid] = ($riskPairsTotal[$riskUuid] ?? 0) + 1;

            if ($valued) {
                $riskAleSum[$riskUuid] = ($riskAleSum[$riskUuid] ?? 0.0) + $ale;
                $riskPairsValued[$riskUuid] = ($riskPairsValued[$riskUuid] ?? 0) + 1;
                $attackAleSum[$attackUuid] = ($attackAleSum[$attackUuid] ?? 0.0) + $ale;
            }

            // §4 display-only P(>=1 occurrence): summed whenever ARO is
            // known, regardless of whether the paired impact also is —
            // it never enters a total, so it is not gated by full pair
            // valuation the way ale is.
            if ($aroResidual !== null) {
                $riskAroSumForProbability[$riskUuid] = ($riskAroSumForProbability[$riskUuid] ?? 0.0) + $aroResidual;
            }
        }

        $total = array_sum($riskAleSum);

        // Step 5: roll up to risks and attacks.
        $risks = [];
        foreach (array_keys($g->risks) as $riskUuid) {
            $impactValue = $impacts[$riskUuid] ?? null;

            $risks[] = new RiskResult(
                uuid: $riskUuid,
                ale: $riskAleSum[$riskUuid] ?? 0.0,
                impactValue: $impactValue,
                impactLevelCode: $impactValue !== null ? $this->mapper->toLevel($impactValue, $impactLevels, 'currency')['code'] ?? null : null,
                annualProbability: 1.0 - exp(-($riskAroSumForProbability[$riskUuid] ?? 0.0)),
                pairsValued: $riskPairsValued[$riskUuid] ?? 0,
                pairsTotal: $riskPairsTotal[$riskUuid] ?? 0,
            );
        }

        $attacks = [];
        foreach (array_keys($g->attacks) as $attackUuid) {
            $data = $attackData[$attackUuid];
            $aroBase = $aros[$attackUuid] ?? null;
            $aroResidual = $aroBase !== null ? $aroBase * $data['productL'] : null;

            $attacks[] = new AttackResult(
                uuid: $attackUuid,
                ale: $attackAleSum[$attackUuid] ?? 0.0,
                aroBase: $aroBase,
                aroResidual: $aroResidual,
                aroLevelCode: $aroResidual !== null ? $this->mapper->toLevel($aroResidual, $likelihoodLevels, 'rate_per_year')['code'] ?? null : null,
                factor: $data['factor'],
                riskCount: count($riskAttackByAttack[$attackUuid] ?? []),
            );
        }

        // Step 6-7: per-defence MC / MCadd / attribution / cost-benefit.
        $defenceResults = $this->ranker->rank(
            defences: $g->defences,
            activeDefenceUuids: $activeDefenceUuids,
            attackData: array_map(
                fn (array $d): array => ['factor' => $d['factor'], 'activeLinks' => $d['activeLinks']],
                $attackData,
            ),
            impacts: $impacts,
            aros: $aros,
            linksByDefence: $linksByDefence,
            riskAttackLinks: $g->riskAttackLinks,
            amortizationYears: $g->amortizationYears,
        );

        // Step 9: completeness block (§6.3).
        $pairsTotal = count($g->riskAttackLinks);
        $pairsValued = array_sum($riskPairsValued);
        $coverage = $pairsTotal > 0 ? $pairsValued / $pairsTotal : 1.0;

        $linksMissingStrength = [];
        $implausibleStrength = [];
        foreach ($linksByAttack as $attackLinks) {
            foreach ($attackLinks as $link) {
                if (! isset($activeDefenceUuids[$link['defence_uuid']])) {
                    continue;
                }

                if ($link['missing']) {
                    $linksMissingStrength[] = ['attack_uuid' => $link['attack_uuid'], 'defence_uuid' => $link['defence_uuid']];
                } elseif ($link['s'] === 1.0) {
                    $implausibleStrength[] = ['attack_uuid' => $link['attack_uuid'], 'defence_uuid' => $link['defence_uuid']];
                }
            }
        }

        $completeness = new Completeness(
            isPartial: $pairsValued < $pairsTotal,
            pairsTotal: $pairsTotal,
            pairsValued: $pairsValued,
            coverage: $coverage,
            risksMissingImpact: array_values(array_filter(array_keys($g->risks), fn (string $u): bool => ($impacts[$u] ?? null) === null)),
            attacksMissingLikelihood: array_values(array_filter(array_keys($g->attacks), fn (string $u): bool => ($aros[$u] ?? null) === null)),
            linksMissingStrength: $linksMissingStrength,
            risksWithoutAttacks: $structural['risksWithoutAttacks'],
            orphanAttacks: $structural['orphanAttacks'],
            orphanDefences: $structural['orphanDefences'],
            sharedAttacks: $structural['sharedAttacks'],
            implausibleStrength: $implausibleStrength,
        );

        $warnings = $this->warnings($structural, $completeness, $attackData, $g->scales);

        return new CalculationResult(
            baseline: $baseline,
            total: $total,
            risks: $risks,
            attacks: $attacks,
            pairs: $pairs,
            defences: $defenceResults,
            completeness: $completeness,
            warnings: $warnings,
        );
    }

    /**
     * §12. Every message here is normative and asserted byte-for-byte by the
     * golden vectors, which the TypeScript mirror is validated against too —
     * changing one is a breaking change across both implementations.
     *
     * @param  array{risksWithoutAttacks: list<string>, orphanAttacks: list<string>, orphanDefences: list<string>, sharedAttacks: list<array{uuid: string, risk_count: int}>}  $structural
     * @param  array<string, AttackFactorData>  $attackData
     * @param  array<int, array{id: int, kind: string, unit: string, is_system: bool}>  $scales
     * @return list<Warning>
     */
    private function warnings(array $structural, Completeness $completeness, array $attackData, array $scales): array
    {
        $warnings = [];

        foreach ($structural['sharedAttacks'] as $shared) {
            $warnings[] = new Warning(
                code: 'shared_attack',
                nodeUuid: $shared['uuid'],
                message: "Contributes to {$shared['risk_count']} risks. Totals assume all those losses occur when this attack succeeds. If these risks describe the same loss, merge them.",
            );
        }

        foreach ($structural['risksWithoutAttacks'] as $riskUuid) {
            $warnings[] = new Warning(
                code: 'risk_without_attacks',
                nodeUuid: $riskUuid,
                message: 'No attack paths identified — this risk contributes nothing to the total.',
            );
        }

        if ($completeness->coverage < 1.0) {
            $missing = $completeness->pairsTotal - $completeness->pairsValued;
            $warnings[] = new Warning(
                code: 'missing_values',
                nodeUuid: null,
                message: "{$missing} of {$completeness->pairsTotal} paths are missing values and are excluded from the total.",
            );
        }

        foreach ($completeness->implausibleStrength as $flagged) {
            $warnings[] = new Warning(
                code: 'implausible_strength',
                nodeUuid: $flagged['defence_uuid'],
                message: 'A 100% effective control eliminates this path entirely. Confirm this is intended.',
            );
        }

        foreach ($attackData as $attackUuid => $data) {
            if (count($data['activeLinks']) >= 2) {
                $warnings[] = new Warning(
                    code: 'independence_assumed',
                    nodeUuid: $attackUuid,
                    message: 'Stacked defences on this attack are combined assuming independence.',
                );
            }
        }

        // Assessment-wide, so nodeUuid is null (§12). Reporting currency off an
        // uncalibrated default scale is the failure mode §8.4.4 calls "the tool
        // lying with a straight face", so one untouched scale is enough to warn.
        foreach ($scales as $scale) {
            if ($scale['is_system']) {
                $warnings[] = new Warning(
                    code: 'uncalibrated_scales',
                    nodeUuid: null,
                    message: 'Scales have not been calibrated for this assessment.',
                );

                break;
            }
        }

        return $warnings;
    }

    /**
     * @param  array<int, ScaleLevelData>  $scaleLevels
     * @return list<ScaleLevelData>
     */
    private function levelsOfKind(array $scaleLevels, string $kind): array
    {
        return array_values(array_filter($scaleLevels, fn (array $l): bool => $l['kind'] === $kind));
    }
}
