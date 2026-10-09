import type { GraphResolver } from './GraphResolver';
import type {
    AttackFactorData,
    CostBucket,
    DefenceData,
    DefenceResult,
    ResolvedLink,
    RiskAttackLink,
} from './types';

interface ValuedPair {
    riskUuid: string;
    imp: number;
    aro: number;
}

/**
 * Ranks defences: marginal contribution (§9.1-9.2), the addition delta for
 * planning (§9.3), log-share attribution (§9.5), and cost-benefit (§10).
 * Mirrors App\Domain\Calculation\DefenceRanker. Pure: consumes only the
 * plain values RiskCalculator has already resolved via GraphResolver.
 */
export class DefenceRanker {
    constructor(private readonly resolver: GraphResolver) {}

    /**
     * One row per defence for one baseline. A defence active in `S`
     * (`activeDefenceUuids`) gets `mc` (§9.1) and `attribution` (§9.5); a
     * defence not in `S` gets `mcAdd` (§9.3) instead — never both, mirroring
     * the worked example's combined "MC / MC add" column (§14).
     */
    rank(
        defences: Record<string, DefenceData>,
        activeDefenceUuids: Record<string, true>,
        attackData: Record<string, AttackFactorData>,
        impacts: Record<string, number | null>,
        aros: Record<string, number | null>,
        linksByDefence: Record<string, ResolvedLink[]>,
        riskAttackLinks: RiskAttackLink[],
        amortizationYears: number,
    ): DefenceResult[] {
        const pairsByAttack = this.valuedPairsByAttack(
            riskAttackLinks,
            impacts,
            aros,
        );
        const results: DefenceResult[] = [];

        for (const uuid of Object.keys(defences)) {
            const defence = defences[uuid];
            const isActive = activeDefenceUuids[uuid] === true;
            const linksForDefence = linksByDefence[uuid] ?? [];

            let mc: number | null = null;
            let mcAdd: number | null = null;
            let attribution: number | null = null;

            if (isActive) {
                mc = this.marginalContribution(
                    uuid,
                    linksForDefence,
                    attackData,
                    pairsByAttack,
                );
                attribution = this.attribution(
                    linksForDefence,
                    attackData,
                    pairsByAttack,
                );
            } else {
                mcAdd = this.additionDelta(
                    linksForDefence,
                    attackData,
                    pairsByAttack,
                );
            }

            const annualCost = this.annualCost(defence, amortizationYears);
            const effectiveMc = mc ?? mcAdd;

            const [cbr, rosi, netBenefit, bucket] = this.costBenefit(
                effectiveMc,
                annualCost,
            );

            results.push({
                uuid,
                status: defence.status ?? 'shelved',
                mc,
                mcAdd,
                attribution,
                annualCost,
                cbr,
                rosi,
                netBenefit,
                costBucket: bucket,
            });
        }

        return results;
    }

    /**
     * §9.2 closed form, implemented by re-multiplying rather than dividing
     * by `(1 - s)` so it never blows up at `s = 1`.
     */
    private marginalContribution(
        defenceUuid: string,
        linksForDefence: ResolvedLink[],
        attackData: Record<string, AttackFactorData>,
        pairsByAttack: Record<string, ValuedPair[]>,
    ): number {
        let mc = 0.0;

        for (const link of linksForDefence) {
            const attackUuid = link.attackUuid;
            const data = attackData[attackUuid];

            if (data === undefined) {
                continue;
            }

            const factorWithout = this.resolver.factorExcluding(
                data.activeLinks,
                defenceUuid,
            );
            const delta = factorWithout - data.factor;

            for (const pair of pairsByAttack[attackUuid] ?? []) {
                mc += pair.aro * pair.imp * delta;
            }
        }

        return mc;
    }

    /**
     * §9.3 — Total(S) - Total(S ∪ {d}) for a defence not currently in S.
     * Adding `d` multiplies the attack's factor by `(1 - s(d,a))`; no
     * division needed here either since we start from `1` and multiply in.
     */
    private additionDelta(
        linksForDefence: ResolvedLink[],
        attackData: Record<string, AttackFactorData>,
        pairsByAttack: Record<string, ValuedPair[]>,
    ): number {
        let mcAdd = 0.0;

        for (const link of linksForDefence) {
            const attackUuid = link.attackUuid;
            const data = attackData[attackUuid];

            if (data === undefined) {
                continue;
            }

            const factorWith = data.factor * (1.0 - link.s);
            const delta = data.factor - factorWith;

            for (const pair of pairsByAttack[attackUuid] ?? []) {
                mcAdd += pair.aro * pair.imp * delta;
            }
        }

        return mcAdd;
    }

    /**
     * §9.5 log-share attribution. Only called for a defence active in S — a
     * defence's own link weight `w(d,a)` is only meaningful against the
     * other active weights on the same attack.
     */
    private attribution(
        linksForDefence: ResolvedLink[],
        attackData: Record<string, AttackFactorData>,
        pairsByAttack: Record<string, ValuedPair[]>,
    ): number {
        let attr = 0.0;

        for (const link of linksForDefence) {
            const attackUuid = link.attackUuid;
            const data = attackData[attackUuid];

            if (data === undefined) {
                continue;
            }

            let totalWeight = 0.0;
            for (const activeLink of data.activeLinks) {
                totalWeight += this.logWeight(activeLink.s);
            }

            // All active links on this attack have s = 0 (no measured
            // reduction) — factor is 1, reduction is 0, nothing to share.
            if (totalWeight <= 0.0) {
                continue;
            }

            const share = this.logWeight(link.s) / totalWeight;

            for (const pair of pairsByAttack[attackUuid] ?? []) {
                const aleInherent = pair.aro * pair.imp;
                const aleAtS = aleInherent * data.factor;
                attr += (aleInherent - aleAtS) * share;
            }
        }

        return attr;
    }

    private logWeight(s: number): number {
        // §9.5: clamp for weighting only, so a 100%-effective link doesn't
        // produce ln(1/0) = +Infinity and swallow the whole share.
        const clamped = Math.min(s, 1.0 - 1e-9);

        return Math.log(1.0 / (1.0 - clamped));
    }

    private valuedPairsByAttack(
        riskAttackLinks: RiskAttackLink[],
        impacts: Record<string, number | null>,
        aros: Record<string, number | null>,
    ): Record<string, ValuedPair[]> {
        const out: Record<string, ValuedPair[]> = {};

        for (const link of riskAttackLinks) {
            const imp = impacts[link.riskUuid] ?? null;
            const aro = aros[link.attackUuid] ?? null;

            if (imp === null || aro === null) {
                continue;
            }

            (out[link.attackUuid] ??= []).push({
                riskUuid: link.riskUuid,
                imp,
                aro,
            });
        }

        return out;
    }

    /**
     * §10 — AC(d). A NULL cost column is treated as 0 only when the other is
     * set; both NULL means the cost is genuinely unestimated (⊥), not free.
     */
    private annualCost(
        defence: DefenceData,
        amortizationYears: number,
    ): number | null {
        const opex = defence.opexAnnualAmount;
        const capex = defence.capexAmount;

        if (opex === null && capex === null) {
            return null;
        }

        return (opex ?? 0.0) + (capex ?? 0.0) / amortizationYears;
    }

    /**
     * §10 — three buckets, never one merged list. `cbr` is null for both
     * `free` (never print Infinity) and `unpriced` (cost not estimated); the
     * bucket tag is what a renderer uses to tell them apart.
     */
    private costBenefit(
        mc: number | null,
        annualCost: number | null,
    ): [number | null, number | null, number | null, CostBucket] {
        if (annualCost === null) {
            return [null, null, null, 'unpriced'];
        }

        if (annualCost === 0.0) {
            const netBenefit = mc !== null ? mc - annualCost : null;

            return [null, null, netBenefit, 'free'];
        }

        const cbr = mc !== null ? mc / annualCost : null;
        const rosi = cbr !== null ? cbr - 1.0 : null;
        const netBenefit = mc !== null ? mc - annualCost : null;

        return [cbr, rosi, netBenefit, 'priced'];
    }
}
