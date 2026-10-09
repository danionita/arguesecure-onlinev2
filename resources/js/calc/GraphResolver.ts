import type { ScaleMapper } from './ScaleMapper';
import type {
    AssessmentGraph,
    ReductionTarget,
    ResolvedLink,
    ValueMode,
} from './types';

export interface AttackFactors {
    factor: number;
    productL: number;
    productI: number;
    productC: number;
}

/**
 * Turns the raw AssessmentGraph into the typed values RiskCalculator and
 * DefenceRanker both need — missing-value classification (§6.1), strength
 * coercion (§6.2.2), and the per-attack `(1-s)` factor products (§3, §5, §7).
 * Mirrors App\Domain\Calculation\GraphResolver. Pure: plain values only, no
 * framework dependency.
 */
export class GraphResolver {
    constructor(private readonly scaleMapper: ScaleMapper) {}

    /** risk uuid => Imp(r), or null (⊥, §6.1) */
    resolveImpacts(g: AssessmentGraph): Record<string, number | null> {
        const out: Record<string, number | null> = {};

        for (const uuid of Object.keys(g.risks)) {
            const risk = g.risks[uuid];
            out[uuid] = this.resolveQuantity(
                risk.impactMode,
                risk.impactAmount,
                risk.impactLevelId,
                g.scaleLevels,
            );
        }

        return out;
    }

    /** attack uuid => ARO(a), or null (⊥, §6.1) */
    resolveAros(g: AssessmentGraph): Record<string, number | null> {
        const out: Record<string, number | null> = {};

        for (const uuid of Object.keys(g.attacks)) {
            const attack = g.attacks[uuid];
            out[uuid] = this.resolveQuantity(
                attack.likelihoodMode,
                attack.likelihoodAro,
                attack.likelihoodLevelId,
                g.scaleLevels,
            );
        }

        return out;
    }

    /**
     * Every attack_defence link, resolved, in the loader's stable
     * (position-ordered) sequence. A missing strength is coerced to `s = 0`
     * here — the single point where §6.2.2's asymmetry is applied — and
     * flagged via `missing` rather than silently disappearing.
     */
    resolveLinks(g: AssessmentGraph): ResolvedLink[] {
        return g.attackDefenceLinks.map((link) => {
            const value = this.resolveQuantity(
                link.strengthMode,
                link.strengthFraction,
                link.strengthLevelId,
                g.scaleLevels,
            );

            return {
                attackUuid: link.attackUuid,
                defenceUuid: link.defenceUuid,
                reductionTarget: link.reductionTarget,
                s: value ?? 0.0,
                missing: value === null,
            };
        });
    }

    /** §2 — the defence uuids that participate in one baseline's set S. */
    baselineSet(g: AssessmentGraph, baseline: string): Record<string, true> {
        let statuses: string[];

        switch (baseline) {
            case 'inherent':
                statuses = [];
                break;
            case 'current':
                statuses = ['implemented'];
                break;
            case 'target':
                statuses = ['implemented', 'todo'];
                break;
            default:
                throw new Error(`Unknown baseline '${baseline}'.`);
        }

        const set: Record<string, true> = {};

        for (const uuid of Object.keys(g.defences)) {
            const defence = g.defences[uuid];
            if (defence.status !== null && statuses.includes(defence.status)) {
                set[uuid] = true;
            }
        }

        return set;
    }

    /**
     * §3/§7 factor(a,S), plus the §5 L/I/C sub-products used only for
     * display (aro_residual, retained impact) — never for the total, so
     * changing reduction_target cannot perturb it (§5.1, §15 item 8).
     *
     * @param activeLinks already filtered to l.defence in S
     */
    attackFactors(activeLinks: ResolvedLink[]): AttackFactors {
        let factor = 1.0;
        let productL = 1.0;
        let productI = 1.0;
        let productC = 1.0;

        for (const link of activeLinks) {
            const retain = 1.0 - link.s;
            factor *= retain;

            switch (link.reductionTarget as ReductionTarget | null) {
                case 'likelihood':
                    productL *= retain;
                    break;
                case 'impact':
                    productI *= retain;
                    break;
                default:
                    // null = ∅, the combined-loss default
                    productC *= retain;
                    break;
            }
        }

        return { factor, productL, productI, productC };
    }

    /**
     * §9.2 — factorWithout(a,S,d), computed by re-multiplying the remaining
     * factors rather than dividing by `(1 - s(d,a))`, so it never blows up
     * at `s = 1`.
     *
     * @param activeLinks already filtered to l.defence in S
     */
    factorExcluding(
        activeLinks: ResolvedLink[],
        excludeDefenceUuid: string,
    ): number {
        let factor = 1.0;

        for (const link of activeLinks) {
            if (link.defenceUuid === excludeDefenceUuid) {
                continue;
            }

            factor *= 1.0 - link.s;
        }

        return factor;
    }

    private resolveQuantity(
        mode: ValueMode | null,
        amount: number | null,
        levelId: number | null,
        scaleLevels: AssessmentGraph['scaleLevels'],
    ): number | null {
        if (mode === null) {
            return null;
        }

        if (mode === 'quantitative') {
            return amount;
        }

        // qualitative
        if (levelId === null || scaleLevels[levelId] === undefined) {
            return null;
        }

        return this.scaleMapper.toNumber(scaleLevels[levelId]);
    }
}
