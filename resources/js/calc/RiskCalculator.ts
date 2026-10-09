import { DefenceRanker } from './DefenceRanker';
import { GraphResolver } from './GraphResolver';
import { ScaleMapper } from './ScaleMapper';
import type {
    AssessmentGraph,
    AttackFactorData,
    AttackResult,
    Baseline,
    CalculationResult,
    CalculationResults,
    Completeness,
    CompletenessLinkRef,
    PairResult,
    ResolvedLink,
    RiskResult,
    ScaleLevel,
    SharedAttack,
    Warning,
} from './types';

interface StructuralData {
    risksWithoutAttacks: string[];
    orphanAttacks: string[];
    orphanDefences: string[];
    sharedAttacks: SharedAttack[];
}

/**
 * The engine (calculation-engine.md §3, §11). Mirrors
 * App\Domain\Calculation\RiskCalculator field-for-field. Pure: no DOM, no
 * network, no clock, no randomness — accepts only an AssessmentGraph and
 * returns plain value objects.
 *
 * Single pass, per §11.2: resolve every value once (via GraphResolver), then
 * derive the three baselines by filtering the same per-attack link list by
 * defence status — they differ only in which links are "active".
 */
export class RiskCalculator {
    private readonly mapper: ScaleMapper;
    private readonly resolver: GraphResolver;
    private readonly ranker: DefenceRanker;

    constructor() {
        this.mapper = new ScaleMapper();
        this.resolver = new GraphResolver(this.mapper);
        this.ranker = new DefenceRanker(this.resolver);
    }

    compute(g: AssessmentGraph): CalculationResults {
        const impacts = this.resolver.resolveImpacts(g);
        const aros = this.resolver.resolveAros(g);
        const links = this.resolver.resolveLinks(g);

        const linksByAttack: Record<string, ResolvedLink[]> = {};
        const linksByDefence: Record<string, ResolvedLink[]> = {};
        for (const link of links) {
            (linksByAttack[link.attackUuid] ??= []).push(link);
            (linksByDefence[link.defenceUuid] ??= []).push(link);
        }

        const riskAttackByRisk: Record<string, string[]> = {};
        const riskAttackByAttack: Record<string, string[]> = {};
        for (const ra of g.riskAttackLinks) {
            (riskAttackByRisk[ra.riskUuid] ??= []).push(ra.attackUuid);
            (riskAttackByAttack[ra.attackUuid] ??= []).push(ra.riskUuid);
        }

        const impactLevels = this.levelsOfKind(g.scaleLevels, 'impact');
        const likelihoodLevels = this.levelsOfKind(g.scaleLevels, 'likelihood');

        const structural: StructuralData = {
            risksWithoutAttacks: Object.keys(g.risks).filter(
                (uuid) => (riskAttackByRisk[uuid] ?? []).length === 0,
            ),
            orphanAttacks: Object.keys(g.attacks).filter(
                (uuid) => (riskAttackByAttack[uuid] ?? []).length === 0,
            ),
            orphanDefences: Object.keys(g.defences).filter(
                (uuid) => (linksByDefence[uuid] ?? []).length === 0,
            ),
            sharedAttacks: Object.keys(g.attacks)
                .map((uuid) => ({
                    uuid,
                    riskCount: (riskAttackByAttack[uuid] ?? []).length,
                }))
                .filter((row) => row.riskCount >= 2),
        };

        return {
            inherent: this.computeBaseline(
                'inherent',
                g,
                impacts,
                aros,
                linksByAttack,
                linksByDefence,
                riskAttackByAttack,
                impactLevels,
                likelihoodLevels,
                structural,
            ),
            current: this.computeBaseline(
                'current',
                g,
                impacts,
                aros,
                linksByAttack,
                linksByDefence,
                riskAttackByAttack,
                impactLevels,
                likelihoodLevels,
                structural,
            ),
            target: this.computeBaseline(
                'target',
                g,
                impacts,
                aros,
                linksByAttack,
                linksByDefence,
                riskAttackByAttack,
                impactLevels,
                likelihoodLevels,
                structural,
            ),
        };
    }

    private computeBaseline(
        baseline: Baseline,
        g: AssessmentGraph,
        impacts: Record<string, number | null>,
        aros: Record<string, number | null>,
        linksByAttack: Record<string, ResolvedLink[]>,
        linksByDefence: Record<string, ResolvedLink[]>,
        riskAttackByAttack: Record<string, string[]>,
        impactLevels: ScaleLevel[],
        likelihoodLevels: ScaleLevel[],
        structural: StructuralData,
    ): CalculationResult {
        const activeDefenceUuids = this.resolver.baselineSet(g, baseline);

        // Step 3 (§11.2): per attack, the active link list and factor(a,S).
        const attackData: Record<string, AttackFactorData> = {};
        for (const attackUuid of Object.keys(g.attacks)) {
            const activeLinks = (linksByAttack[attackUuid] ?? []).filter(
                (l) => activeDefenceUuids[l.defenceUuid] === true,
            );
            const factors = this.resolver.attackFactors(activeLinks);

            attackData[attackUuid] = {
                factor: factors.factor,
                productL: factors.productL,
                productI: factors.productI,
                productC: factors.productC,
                activeLinks,
            };
        }

        // Step 4: per pair, ARO', Imp', ALE.
        const pairs: PairResult[] = [];
        const riskAleSum: Record<string, number> = {};
        const riskPairsValued: Record<string, number> = {};
        const riskPairsTotal: Record<string, number> = {};
        const riskAroSumForProbability: Record<string, number> = {};
        const attackAleSum: Record<string, number> = {};

        for (const ra of g.riskAttackLinks) {
            const riskUuid = ra.riskUuid;
            const attackUuid = ra.attackUuid;
            const data = attackData[attackUuid];

            const impact0 = impacts[riskUuid] ?? null;
            const aro0 = aros[attackUuid] ?? null;
            const valued = impact0 !== null && aro0 !== null;

            const aroResidual = aro0 !== null ? aro0 * data.productL : null;
            const impactResidual =
                impact0 !== null ? impact0 * data.productI : null;
            const ale = valued
                ? (aro0 as number) * (impact0 as number) * data.factor
                : null;

            pairs.push({
                riskUuid,
                attackUuid,
                aroResidual,
                impactResidual,
                ale,
                valued,
            });

            riskPairsTotal[riskUuid] = (riskPairsTotal[riskUuid] ?? 0) + 1;

            if (valued) {
                riskAleSum[riskUuid] =
                    (riskAleSum[riskUuid] ?? 0.0) + (ale as number);
                riskPairsValued[riskUuid] =
                    (riskPairsValued[riskUuid] ?? 0) + 1;
                attackAleSum[attackUuid] =
                    (attackAleSum[attackUuid] ?? 0.0) + (ale as number);
            }

            // §4 display-only P(>=1 occurrence): summed whenever ARO is
            // known, regardless of whether the paired impact also is — it
            // never enters a total, so it is not gated by full pair
            // valuation the way ale is.
            if (aroResidual !== null) {
                riskAroSumForProbability[riskUuid] =
                    (riskAroSumForProbability[riskUuid] ?? 0.0) + aroResidual;
            }
        }

        const total = Object.values(riskAleSum).reduce(
            (sum, v) => sum + v,
            0.0,
        );

        // Step 5: roll up to risks and attacks.
        const risks: RiskResult[] = [];
        for (const riskUuid of Object.keys(g.risks)) {
            const impactValue = impacts[riskUuid] ?? null;
            const impactLevel =
                impactValue !== null
                    ? this.mapper.toLevel(impactValue, impactLevels, 'currency')
                    : null;

            risks.push({
                uuid: riskUuid,
                ale: riskAleSum[riskUuid] ?? 0.0,
                impactValue,
                impactLevelCode: impactLevel?.code ?? null,
                annualProbability:
                    1.0 -
                    Math.exp(-(riskAroSumForProbability[riskUuid] ?? 0.0)),
                pairsValued: riskPairsValued[riskUuid] ?? 0,
                pairsTotal: riskPairsTotal[riskUuid] ?? 0,
            });
        }

        const attacks: AttackResult[] = [];
        for (const attackUuid of Object.keys(g.attacks)) {
            const data = attackData[attackUuid];
            const aroBase = aros[attackUuid] ?? null;
            const aroResidual =
                aroBase !== null ? aroBase * data.productL : null;
            const aroLevel =
                aroResidual !== null
                    ? this.mapper.toLevel(
                          aroResidual,
                          likelihoodLevels,
                          'rate_per_year',
                      )
                    : null;

            attacks.push({
                uuid: attackUuid,
                ale: attackAleSum[attackUuid] ?? 0.0,
                aroBase,
                aroResidual,
                aroLevelCode: aroLevel?.code ?? null,
                factor: data.factor,
                riskCount: (riskAttackByAttack[attackUuid] ?? []).length,
            });
        }

        // Step 6-7: per-defence MC / MCadd / attribution / cost-benefit.
        const defenceResults = this.ranker.rank(
            g.defences,
            activeDefenceUuids,
            attackData,
            impacts,
            aros,
            linksByDefence,
            g.riskAttackLinks,
            g.amortizationYears,
        );

        // Step 9: completeness block (§6.3).
        const pairsTotal = g.riskAttackLinks.length;
        const pairsValued = Object.values(riskPairsValued).reduce(
            (sum, v) => sum + v,
            0,
        );
        const coverage = pairsTotal > 0 ? pairsValued / pairsTotal : 1.0;

        const linksMissingStrength: CompletenessLinkRef[] = [];
        const implausibleStrength: CompletenessLinkRef[] = [];
        for (const attackLinks of Object.values(linksByAttack)) {
            for (const link of attackLinks) {
                if (activeDefenceUuids[link.defenceUuid] !== true) {
                    continue;
                }

                if (link.missing) {
                    linksMissingStrength.push({
                        attackUuid: link.attackUuid,
                        defenceUuid: link.defenceUuid,
                    });
                } else if (link.s === 1.0) {
                    implausibleStrength.push({
                        attackUuid: link.attackUuid,
                        defenceUuid: link.defenceUuid,
                    });
                }
            }
        }

        const completeness: Completeness = {
            isPartial: pairsValued < pairsTotal,
            pairsTotal,
            pairsValued,
            coverage,
            risksMissingImpact: Object.keys(g.risks).filter(
                (u) => (impacts[u] ?? null) === null,
            ),
            attacksMissingLikelihood: Object.keys(g.attacks).filter(
                (u) => (aros[u] ?? null) === null,
            ),
            linksMissingStrength,
            risksWithoutAttacks: structural.risksWithoutAttacks,
            orphanAttacks: structural.orphanAttacks,
            orphanDefences: structural.orphanDefences,
            sharedAttacks: structural.sharedAttacks,
            implausibleStrength,
        };

        const warnings = this.warnings(
            structural,
            completeness,
            attackData,
            g.scales,
        );

        return {
            baseline,
            total,
            risks,
            attacks,
            pairs,
            defences: defenceResults,
            completeness,
            warnings,
        };
    }

    /**
     * §12. Every message here is normative and asserted byte-for-byte by the
     * golden vectors, which the PHP engine is validated against too —
     * changing one is a breaking change across both implementations.
     */
    private warnings(
        structural: StructuralData,
        completeness: Completeness,
        attackData: Record<string, AttackFactorData>,
        scales: AssessmentGraph['scales'],
    ): Warning[] {
        const warnings: Warning[] = [];

        for (const shared of structural.sharedAttacks) {
            warnings.push({
                code: 'shared_attack',
                nodeUuid: shared.uuid,
                message: `Contributes to ${shared.riskCount} risks. Totals assume all those losses occur when this attack succeeds. If these risks describe the same loss, merge them.`,
            });
        }

        for (const riskUuid of structural.risksWithoutAttacks) {
            warnings.push({
                code: 'risk_without_attacks',
                nodeUuid: riskUuid,
                message:
                    'No attack paths identified — this risk contributes nothing to the total.',
            });
        }

        if (completeness.coverage < 1.0) {
            const missing = completeness.pairsTotal - completeness.pairsValued;
            warnings.push({
                code: 'missing_values',
                nodeUuid: null,
                message: `${missing} of ${completeness.pairsTotal} paths are missing values and are excluded from the total.`,
            });
        }

        for (const flagged of completeness.implausibleStrength) {
            warnings.push({
                code: 'implausible_strength',
                nodeUuid: flagged.defenceUuid,
                message:
                    'A 100% effective control eliminates this path entirely. Confirm this is intended.',
            });
        }

        for (const attackUuid of Object.keys(attackData)) {
            const data = attackData[attackUuid];
            if (data.activeLinks.length >= 2) {
                warnings.push({
                    code: 'independence_assumed',
                    nodeUuid: attackUuid,
                    message:
                        'Stacked defences on this attack are combined assuming independence.',
                });
            }
        }

        // Assessment-wide, so nodeUuid is null (§12). Reporting currency off
        // an uncalibrated default scale is the failure mode §8.4.4 calls
        // "the tool lying with a straight face", so one untouched scale is
        // enough to warn.
        for (const scale of Object.values(scales)) {
            if (scale.isSystem) {
                warnings.push({
                    code: 'uncalibrated_scales',
                    nodeUuid: null,
                    message:
                        'Scales have not been calibrated for this assessment.',
                });

                break;
            }
        }

        return warnings;
    }

    private levelsOfKind(
        scaleLevels: AssessmentGraph['scaleLevels'],
        kind: string,
    ): ScaleLevel[] {
        return Object.values(scaleLevels).filter((l) => l.kind === kind);
    }
}
