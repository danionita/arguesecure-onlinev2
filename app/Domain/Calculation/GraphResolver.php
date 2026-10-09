<?php

namespace App\Domain\Calculation;

use App\Domain\Graph\AssessmentGraph;

/**
 * Turns the raw AssessmentGraph into the typed values RiskCalculator and
 * DefenceRanker both need — missing-value classification (§6.1), strength
 * coercion (§6.2.2), and the per-attack `(1-s)` factor products (§3, §5, §7).
 * Not one of the four documented services (§11.1); a private supporting
 * class shared so the two public services can never disagree on how a value
 * resolves. Pure: plain arrays and floats only, no Eloquent.
 *
 * @phpstan-import-type ScaleLevelData from AssessmentGraph
 * @phpstan-import-type RiskData from AssessmentGraph
 * @phpstan-import-type AttackData from AssessmentGraph
 * @phpstan-import-type AttackDefenceLink from AssessmentGraph
 *
 * @phpstan-type ResolvedLink array{attack_uuid: string, defence_uuid: string, reduction_target: ?string, s: float, missing: bool}
 */
final class GraphResolver
{
    public function __construct(
        private readonly ScaleMapper $scaleMapper,
    ) {}

    /**
     * @return array<string, ?float> risk uuid => Imp(r), or null (⊥, §6.1)
     */
    public function resolveImpacts(AssessmentGraph $g): array
    {
        $out = [];

        foreach ($g->risks as $uuid => $risk) {
            $out[$uuid] = $this->resolveQuantity(
                $risk['impact_mode'],
                $risk['impact_amount'],
                $risk['impact_level_id'],
                $g->scaleLevels,
            );
        }

        return $out;
    }

    /**
     * @return array<string, ?float> attack uuid => ARO(a), or null (⊥, §6.1)
     */
    public function resolveAros(AssessmentGraph $g): array
    {
        $out = [];

        foreach ($g->attacks as $uuid => $attack) {
            $out[$uuid] = $this->resolveQuantity(
                $attack['likelihood_mode'],
                $attack['likelihood_aro'],
                $attack['likelihood_level_id'],
                $g->scaleLevels,
            );
        }

        return $out;
    }

    /**
     * Every attack_defence link, resolved, in the loader's stable
     * (position-ordered) sequence. A missing strength is coerced to `s = 0`
     * here — the single point where §6.2.2's asymmetry is applied — and
     * flagged via `missing` rather than silently disappearing.
     *
     * @return list<ResolvedLink>
     */
    public function resolveLinks(AssessmentGraph $g): array
    {
        $out = [];

        foreach ($g->attackDefenceLinks as $link) {
            $value = $this->resolveQuantity(
                $link['strength_mode'],
                $link['strength_fraction'],
                $link['strength_level_id'],
                $g->scaleLevels,
            );

            $out[] = [
                'attack_uuid' => $link['attack_uuid'],
                'defence_uuid' => $link['defence_uuid'],
                'reduction_target' => $link['reduction_target'],
                's' => $value ?? 0.0,
                'missing' => $value === null,
            ];
        }

        return $out;
    }

    /**
     * §2 — the defence uuids that participate in one baseline's set S.
     *
     * @return array<string, true>
     */
    public function baselineSet(AssessmentGraph $g, string $baseline): array
    {
        $statuses = match ($baseline) {
            'inherent' => [],
            'current' => ['implemented'],
            'target' => ['implemented', 'todo'],
            default => throw new \InvalidArgumentException("Unknown baseline '{$baseline}'."),
        };

        $set = [];

        foreach ($g->defences as $uuid => $defence) {
            if (in_array($defence['status'], $statuses, true)) {
                $set[$uuid] = true;
            }
        }

        return $set;
    }

    /**
     * §3/§7 factor(a,S), plus the §5 L/I/C sub-products used only for
     * display (aro_residual, retained impact) — never for the total, so
     * changing reduction_target cannot perturb it (§5.1, §15 item 8).
     *
     * @param  list<ResolvedLink>  $activeLinks  already filtered to l.defence in S
     * @return array{factor: float, productL: float, productI: float, productC: float}
     */
    public function attackFactors(array $activeLinks): array
    {
        $factor = 1.0;
        $productL = 1.0;
        $productI = 1.0;
        $productC = 1.0;

        foreach ($activeLinks as $link) {
            $retain = 1.0 - $link['s'];
            $factor *= $retain;

            match ($link['reduction_target']) {
                'likelihood' => $productL *= $retain,
                'impact' => $productI *= $retain,
                default => $productC *= $retain, // null = ∅, the combined-loss default
            };
        }

        return ['factor' => $factor, 'productL' => $productL, 'productI' => $productI, 'productC' => $productC];
    }

    /**
     * §9.2 — factorWithout(a,S,d), computed by re-multiplying the remaining
     * factors rather than dividing by `(1 - s(d,a))`, so it never blows up
     * at `s = 1`.
     *
     * @param  list<ResolvedLink>  $activeLinks  already filtered to l.defence in S
     */
    public function factorExcluding(array $activeLinks, string $excludeDefenceUuid): float
    {
        $factor = 1.0;

        foreach ($activeLinks as $link) {
            if ($link['defence_uuid'] === $excludeDefenceUuid) {
                continue;
            }

            $factor *= 1.0 - $link['s'];
        }

        return $factor;
    }

    /**
     * @param  array<int, ScaleLevelData>  $scaleLevels
     */
    private function resolveQuantity(?string $mode, ?float $amount, ?int $levelId, array $scaleLevels): ?float
    {
        if ($mode === null) {
            return null;
        }

        if ($mode === 'quantitative') {
            return $amount;
        }

        // qualitative
        if ($levelId === null || ! isset($scaleLevels[$levelId])) {
            return null;
        }

        return $this->scaleMapper->toNumber($scaleLevels[$levelId]);
    }
}
