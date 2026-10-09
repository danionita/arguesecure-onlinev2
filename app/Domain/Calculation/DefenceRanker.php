<?php

namespace App\Domain\Calculation;

/**
 * Ranks defences: marginal contribution (§9.1-9.2), the addition delta for
 * planning (§9.3), log-share attribution (§9.5), and cost-benefit (§10).
 * Pure: consumes only the plain arrays RiskCalculator has already resolved
 * via GraphResolver — no Eloquent, no I/O.
 *
 * @phpstan-import-type ResolvedLink from GraphResolver
 *
 * @phpstan-type DefenceData array{uuid: string, title: string, is_transfer: bool, status: ?string, capex_amount: ?float, opex_annual_amount: ?float}
 * @phpstan-type AttackFactorData array{factor: float, activeLinks: list<ResolvedLink>}
 * @phpstan-type ValuedPair array{risk_uuid: string, imp: float, aro: float}
 */
final class DefenceRanker
{
    public function __construct(
        private readonly GraphResolver $resolver,
    ) {}

    /**
     * One row per defence for one baseline. A defence active in `S`
     * (`$activeDefenceUuids`) gets `mc` (§9.1) and `attribution` (§9.5); a
     * defence not in `S` gets `mcAdd` (§9.3) instead — never both, mirroring
     * the worked example's combined "MC / MC add" column (§14).
     *
     * @param  array<string, DefenceData>  $defences
     * @param  array<string, true>  $activeDefenceUuids  S for this baseline
     * @param  array<string, AttackFactorData>  $attackData  attack uuid => factor(a,S) plus its active links
     * @param  array<string, ?float>  $impacts  risk uuid => Imp(r)
     * @param  array<string, ?float>  $aros  attack uuid => ARO(a)
     * @param  array<string, list<ResolvedLink>>  $linksByDefence  every link for that defence, regardless of S — needed to evaluate a not-yet-active (todo/shelved) defence "on demand" (§9.3)
     * @param  list<array{risk_uuid: string, attack_uuid: string}>  $riskAttackLinks
     * @return list<DefenceResult>
     */
    public function rank(
        array $defences,
        array $activeDefenceUuids,
        array $attackData,
        array $impacts,
        array $aros,
        array $linksByDefence,
        array $riskAttackLinks,
        int $amortizationYears,
    ): array {
        $pairsByAttack = $this->valuedPairsByAttack($riskAttackLinks, $impacts, $aros);

        $results = [];

        foreach ($defences as $uuid => $defence) {
            $isActive = isset($activeDefenceUuids[$uuid]);
            $linksForDefence = $linksByDefence[$uuid] ?? [];

            $mc = null;
            $mcAdd = null;
            $attribution = null;

            if ($isActive) {
                $mc = $this->marginalContribution($uuid, $linksForDefence, $attackData, $pairsByAttack);
                $attribution = $this->attribution($linksForDefence, $attackData, $pairsByAttack);
            } else {
                $mcAdd = $this->additionDelta($linksForDefence, $attackData, $pairsByAttack);
            }

            $annualCost = $this->annualCost($defence, $amortizationYears);
            $effectiveMc = $mc ?? $mcAdd;

            [$cbr, $rosi, $netBenefit, $bucket] = $this->costBenefit($effectiveMc, $annualCost);

            $results[] = new DefenceResult(
                uuid: $uuid,
                status: (string) ($defence['status'] ?? 'shelved'),
                mc: $mc,
                mcAdd: $mcAdd,
                attribution: $attribution,
                annualCost: $annualCost,
                cbr: $cbr,
                rosi: $rosi,
                netBenefit: $netBenefit,
                costBucket: $bucket,
            );
        }

        return $results;
    }

    /**
     * §9.2 closed form, implemented by re-multiplying rather than dividing
     * by `(1 - s)` so it never blows up at `s = 1`.
     *
     * @param  list<ResolvedLink>  $linksForDefence
     * @param  array<string, AttackFactorData>  $attackData
     * @param  array<string, list<ValuedPair>>  $pairsByAttack
     */
    private function marginalContribution(string $defenceUuid, array $linksForDefence, array $attackData, array $pairsByAttack): float
    {
        $mc = 0.0;

        foreach ($linksForDefence as $link) {
            $attackUuid = $link['attack_uuid'];
            $data = $attackData[$attackUuid] ?? null;

            if ($data === null) {
                continue;
            }

            $factorWithout = $this->resolver->factorExcluding($data['activeLinks'], $defenceUuid);
            $delta = $factorWithout - $data['factor'];

            foreach ($pairsByAttack[$attackUuid] ?? [] as $pair) {
                $mc += $pair['aro'] * $pair['imp'] * $delta;
            }
        }

        return $mc;
    }

    /**
     * §9.3 — Total(S) - Total(S ∪ {d}) for a defence not currently in S.
     * Adding `d` multiplies the attack's factor by `(1 - s(d,a))`; no
     * division needed here either since we start from `1` and multiply in.
     *
     * @param  list<ResolvedLink>  $linksForDefence
     * @param  array<string, AttackFactorData>  $attackData
     * @param  array<string, list<ValuedPair>>  $pairsByAttack
     */
    private function additionDelta(array $linksForDefence, array $attackData, array $pairsByAttack): float
    {
        $mcAdd = 0.0;

        foreach ($linksForDefence as $link) {
            $attackUuid = $link['attack_uuid'];
            $data = $attackData[$attackUuid] ?? null;

            if ($data === null) {
                continue;
            }

            $factorWith = $data['factor'] * (1.0 - $link['s']);
            $delta = $data['factor'] - $factorWith;

            foreach ($pairsByAttack[$attackUuid] ?? [] as $pair) {
                $mcAdd += $pair['aro'] * $pair['imp'] * $delta;
            }
        }

        return $mcAdd;
    }

    /**
     * §9.5 log-share attribution. Only called for a defence active in S — a
     * defence's own link weight `w(d,a)` is only meaningful against the
     * other active weights on the same attack.
     *
     * @param  list<ResolvedLink>  $linksForDefence
     * @param  array<string, AttackFactorData>  $attackData
     * @param  array<string, list<ValuedPair>>  $pairsByAttack
     */
    private function attribution(array $linksForDefence, array $attackData, array $pairsByAttack): float
    {
        $attr = 0.0;

        foreach ($linksForDefence as $link) {
            $attackUuid = $link['attack_uuid'];
            $data = $attackData[$attackUuid] ?? null;

            if ($data === null) {
                continue;
            }

            $totalWeight = 0.0;
            foreach ($data['activeLinks'] as $activeLink) {
                $totalWeight += $this->logWeight($activeLink['s']);
            }

            // All active links on this attack have s = 0 (no measured
            // reduction) — factor is 1, reduction is 0, nothing to share.
            if ($totalWeight <= 0.0) {
                continue;
            }

            $share = $this->logWeight($link['s']) / $totalWeight;

            foreach ($pairsByAttack[$attackUuid] ?? [] as $pair) {
                $aleInherent = $pair['aro'] * $pair['imp'];
                $aleAtS = $aleInherent * $data['factor'];
                $attr += ($aleInherent - $aleAtS) * $share;
            }
        }

        return $attr;
    }

    private function logWeight(float $s): float
    {
        // §9.5: clamp for weighting only, so a 100%-effective link doesn't
        // produce ln(1/0) = +INF and swallow the whole share.
        $clamped = min($s, 1.0 - 1e-9);

        return log(1.0 / (1.0 - $clamped));
    }

    /**
     * @param  list<array{risk_uuid: string, attack_uuid: string}>  $riskAttackLinks
     * @param  array<string, ?float>  $impacts
     * @param  array<string, ?float>  $aros
     * @return array<string, list<ValuedPair>> attack uuid => valued pairs on that attack
     */
    private function valuedPairsByAttack(array $riskAttackLinks, array $impacts, array $aros): array
    {
        $out = [];

        foreach ($riskAttackLinks as $link) {
            $imp = $impacts[$link['risk_uuid']] ?? null;
            $aro = $aros[$link['attack_uuid']] ?? null;

            if ($imp === null || $aro === null) {
                continue;
            }

            $out[$link['attack_uuid']][] = ['risk_uuid' => $link['risk_uuid'], 'imp' => $imp, 'aro' => $aro];
        }

        return $out;
    }

    /**
     * §10 — AC(d). A NULL cost column is treated as 0 only when the other is
     * set; both NULL means the cost is genuinely unestimated (⊥), not free.
     *
     * @param  DefenceData  $defence
     */
    private function annualCost(array $defence, int $amortizationYears): ?float
    {
        $opex = $defence['opex_annual_amount'];
        $capex = $defence['capex_amount'];

        if ($opex === null && $capex === null) {
            return null;
        }

        return ($opex ?? 0.0) + ($capex ?? 0.0) / $amortizationYears;
    }

    /**
     * §10 — three buckets, never one merged list. `cbr` is null for both
     * `free` (never print Infinity) and `unpriced` (cost not estimated); the
     * bucket tag is what a renderer uses to tell them apart.
     *
     * @return array{0: ?float, 1: ?float, 2: ?float, 3: string}
     */
    private function costBenefit(?float $mc, ?float $annualCost): array
    {
        if ($annualCost === null) {
            return [null, null, null, 'unpriced'];
        }

        if ($annualCost === 0.0) {
            $netBenefit = $mc !== null ? $mc - $annualCost : null;

            return [null, null, $netBenefit, 'free'];
        }

        $cbr = $mc !== null ? $mc / $annualCost : null;
        $rosi = $cbr !== null ? $cbr - 1.0 : null;
        $netBenefit = $mc !== null ? $mc - $annualCost : null;

        return [$cbr, $rosi, $netBenefit, 'priced'];
    }
}
