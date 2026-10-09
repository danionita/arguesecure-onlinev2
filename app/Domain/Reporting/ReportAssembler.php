<?php

namespace App\Domain\Reporting;

use App\Domain\Calculation\AttackResult;
use App\Domain\Calculation\CalculationResult;
use App\Domain\Calculation\CalculationResults;
use App\Domain\Calculation\DefenceResult;
use App\Domain\Calculation\PairResult;
use App\Domain\Calculation\RiskResult;
use App\Domain\Calculation\ScaleMapper;
use App\Domain\Graph\AssessmentGraph;
use App\Domain\References\ControlId;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Node;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Assembles the report payload from an already-computed CalculationResults
 * (PLAN.md Phase 7, ui.md §9). This class never touches the engine's math —
 * it only selects, joins and formats what App\Domain\Calculation already
 * produced (CLAUDE.md: "Consume the existing RiskCalculator... Do not
 * reimplement or duplicate any part of the engine"). The one exception is
 * ScaleMapper::toNumber(), which is not a calculation but a pure lookup
 * (`representative` off a level row) already exposed as a reusable public
 * method on the engine's own mapper — reusing it here is the "do not
 * reimplement" rule working as intended, not a violation of it.
 *
 * All three baselines are always assembled, regardless of the
 * `ReportOptions::$baseline` choice: the underlying CalculationResults
 * already computed all three in one pass (calculation-engine.md §11.2), so
 * withholding one here would only add branching for no savings. The Vue
 * report page shows or hides the current/target columns per the option;
 * `shelvedOpportunity` is likewise always computed and gated by
 * `includeShelved` only in the frontend's rendering, for the same reason.
 * `comments` is the one section that changes what is *fetched*, since
 * skipping the query when unwanted is a real saving.
 */
final class ReportAssembler
{
    private readonly ScaleMapper $scaleMapper;

    public function __construct()
    {
        $this->scaleMapper = new ScaleMapper;
    }

    /**
     * @param  Collection<string, Node>  $nodesByUuid  every node of the assessment, keyed by uuid, with `references` (and `comments.user`, `comments.replies.user` when `$options->includeComments`) eager-loaded by the caller
     * @return array<string, mixed>
     */
    public function assemble(
        Assessment $assessment,
        AssessmentGraph $graph,
        CalculationResults $results,
        Collection $nodesByUuid,
        ReportOptions $options,
    ): array {
        $titles = $this->titleMap($graph);

        return [
            'assessment' => [
                'uuid' => $assessment->uuid,
                'currency' => $assessment->currency,
                'amortizationYears' => $assessment->amortization_years,
                // W44. Display only: the baseline chart draws a threshold
                // line against it. It is not in the AssessmentGraph and no
                // figure below is computed from it.
                'riskTolerance' => $assessment->risk_tolerance,
                'isPublic' => (bool) $assessment->is_public,
            ],
            'options' => $options->toArray(),
            // W74. The author's own words, and the disclosure that goes with
            // them, on the assembled report rather than only on the Inertia
            // page prop — the HTML and Word exports carry the prose sections
            // in order, and reading the model a second time to get them would
            // make the file and the page two sources for one document.
            //
            // The *draft* is deliberately not here and must not be added.
            // `summary_narrative` is text a person wrote or accepted and is
            // already sent to every reader; `summary_draft` is un-reviewed
            // model output that the server withholds from anyone who could
            // not accept it (D24), and an export is exactly the path by which
            // it would reach them.
            'writtenSummary' => [
                'text' => $assessment->summary_narrative,
                'generated' => (bool) $assessment->summary_narrative_generated,
            ],
            'cover' => $this->cover($assessment, $graph, $results, $nodesByUuid),
            'executiveSummary' => $this->executiveSummary($results),
            'programme' => $this->programme($graph, $results),
            'riskRegister' => $this->riskRegister($graph, $results, $titles),
            'defencesByImportance' => $this->bucketAndSort(
                array_values(array_filter($results->current->defences, fn (DefenceResult $d): bool => $d->status === 'implemented')),
                $graph,
                $titles,
            ),
            'defencesByOpportunity' => $this->bucketAndSort(
                array_values(array_filter($results->current->defences, fn (DefenceResult $d): bool => $d->status === 'todo')),
                $graph,
                $titles,
            ),
            'shelvedOpportunity' => $this->bucketAndSort(
                array_values(array_filter($results->current->defences, fn (DefenceResult $d): bool => $d->status === 'shelved')),
                $graph,
                $titles,
            ),
            'transfers' => $this->transfers($graph, $results, $nodesByUuid),
            'controlCoverage' => $this->controlCoverage($graph, $nodesByUuid, $options),
            'tree' => $this->tree($graph, $results, $nodesByUuid, $options),
            'appendices' => $this->appendices($graph, $results, $nodesByUuid, $titles, $options),
        ];
    }

    /** @return array<string, string> node uuid => title, root included */
    private function titleMap(AssessmentGraph $graph): array
    {
        $titles = [];

        if ($graph->rootTitle !== null) {
            $titles[$graph->assessmentUuid] = $graph->rootTitle;
        }

        foreach ($graph->risks as $uuid => $risk) {
            $titles[$uuid] = $risk['title'];
        }

        foreach ($graph->attacks as $uuid => $attack) {
            $titles[$uuid] = $attack['title'];
        }

        foreach ($graph->defences as $uuid => $defence) {
            $titles[$uuid] = $defence['title'];
        }

        return $titles;
    }

    /** @return array{baseline: string, total: float, isPartial: bool, pairsValued: int, pairsTotal: int, coverage: float} */
    private function totalBlock(CalculationResult $result): array
    {
        return [
            'baseline' => $result->baseline,
            'total' => $result->total,
            'isPartial' => $result->completeness->isPartial,
            'pairsValued' => $result->completeness->pairsValued,
            'pairsTotal' => $result->completeness->pairsTotal,
            'coverage' => $result->completeness->coverage,
        ];
    }

    private function safeRatio(float $numerator, float $denominator): ?float
    {
        if ($denominator == 0.0) {
            return null;
        }

        return $numerator / $denominator;
    }

    /**
     * @param  Collection<string, Node>  $nodesByUuid
     * @return array<string, mixed>
     */
    private function cover(Assessment $assessment, AssessmentGraph $graph, CalculationResults $results, Collection $nodesByUuid): array
    {
        $owner = $assessment->owner;

        return [
            'title' => $graph->rootTitle,
            'description' => $graph->rootDescription,
            // Name only, deliberately. `view` is the broadest ability there
            // is — an is_public assessment grants it to the whole internet
            // and a share link grants it to anyone holding the URL
            // (security.md §3.1) — so anything placed here is published to
            // exactly that audience. The cover calls for the owner
            // (PLAN.md Phase 7 §1), and the page only ever renders the name;
            // shipping the address as well would have turned every public
            // report into a harvestable email address for no rendered
            // benefit. Collaborator emails stay where they belong, behind
            // `manage` in the share dialog.
            'owner' => $owner === null ? null : ['name' => $owner->name],
            'generatedAt' => now()->toIso8601String(),
            'scaleCalibration' => $this->scaleCalibration($graph),
            'completeness' => $this->totalBlock($results->current),
        ];
    }

    /** @return list<array{kind: string, unit: string, isSystem: bool, levels: list<array{code: string, label: string, lowerBound: float, upperBound: ?float, representative: float}>}> */
    private function scaleCalibration(AssessmentGraph $graph): array
    {
        $levelsByScale = [];
        foreach ($graph->scaleLevels as $level) {
            $levelsByScale[$level['scale_id']][] = $level;
        }

        $out = [];
        foreach ($graph->scales as $scaleId => $scale) {
            $levels = $levelsByScale[$scaleId] ?? [];
            usort($levels, fn (array $a, array $b): int => $a['ordinal'] <=> $b['ordinal']);

            $out[] = [
                'kind' => $scale['kind'],
                'unit' => $scale['unit'],
                'isSystem' => $scale['is_system'],
                'levels' => array_map(fn (array $l): array => [
                    'code' => $l['code'],
                    'label' => $l['label'],
                    'lowerBound' => $l['lower_bound'],
                    'upperBound' => $l['upper_bound'],
                    'representative' => $l['representative'],
                ], $levels),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function executiveSummary(CalculationResults $results): array
    {
        $inherentTotal = $results->inherent->total;

        $priced = 0.0;
        $free = 0.0;
        $unpricedCount = 0;
        $implementedCount = 0;

        foreach ($results->current->defences as $defence) {
            if ($defence->status !== 'implemented') {
                continue;
            }
            $implementedCount++;

            match ($defence->costBucket) {
                'priced' => $priced += $defence->annualCost ?? 0.0,
                'free' => $free += 0.0,
                'unpriced' => $unpricedCount++,
                default => null,
            };
        }

        return [
            'inherent' => $this->totalBlock($results->inherent),
            'current' => $this->totalBlock($results->current),
            'target' => $this->totalBlock($results->target),
            'reductionCurrent' => $this->safeRatio($inherentTotal - $results->current->total, $inherentTotal),
            'reductionTarget' => $this->safeRatio($inherentTotal - $results->target->total, $inherentTotal),
            'annualCostOfControls' => [
                'priced' => $priced,
                'free' => $free,
                'unpricedCount' => $unpricedCount,
                'implementedCount' => $implementedCount,
            ],
        ];
    }

    /**
     * What the plan costs and what it buys (W33).
     *
     * `executiveSummary()` counts implemented controls only, which answers
     * "what are we spending" and not the question a reader actually arrives
     * with: *is the todo list worth doing?* Every figure here is a sum or a
     * subtraction over what the engine already produced — no new arithmetic
     * about risk, and `RiskCalculator` is untouched.
     *
     * Costs come from the target baseline because that is the one in which a
     * planned control exists at all. Unpriced controls are **counted, never
     * folded into a total**: a defence with no cost entered is not a free
     * defence (ui.md §9, "never print ∞ for a zero-cost control" is the same
     * principle from the other end).
     *
     * @return array<string, mixed>
     */
    private function programme(AssessmentGraph $graph, CalculationResults $results): array
    {
        $plannedAnnualised = 0.0;
        $plannedOneOff = 0.0;
        $plannedRecurring = 0.0;
        $plannedCount = 0;
        $plannedUnpricedCount = 0;
        $plannedFreeCount = 0;

        foreach ($results->target->defences as $defence) {
            if ($defence->status !== 'todo') {
                continue;
            }

            $plannedCount++;

            match ($defence->costBucket) {
                'priced' => $plannedAnnualised += $defence->annualCost ?? 0.0,
                'free' => $plannedFreeCount++,
                'unpriced' => $plannedUnpricedCount++,
                default => null,
            };

            $node = $graph->defences[$defence->uuid] ?? null;
            $plannedOneOff += $node['capex_amount'] ?? 0.0;
            $plannedRecurring += $node['opex_annual_amount'] ?? 0.0;
        }

        $reducedToCurrent = $results->inherent->total - $results->current->total;
        $reducedToTarget = $results->current->total - $results->target->total;

        return [
            'planned' => [
                // Already amortised: DefenceRanker::annualCost() is
                // opex + capex / amortization_years, so the one-off and
                // recurring figures beside it are the breakdown, not
                // additional cost.
                'annualisedCost' => $plannedAnnualised,
                'oneOffCost' => $plannedOneOff,
                'recurringCost' => $plannedRecurring,
                'count' => $plannedCount,
                'freeCount' => $plannedFreeCount,
                'unpricedCount' => $plannedUnpricedCount,
            ],
            'totals' => [
                'inherent' => $results->inherent->total,
                'current' => $results->current->total,
                'target' => $results->target->total,
            ],
            'riskReduced' => [
                'inherentToCurrent' => $reducedToCurrent,
                'currentToTarget' => $reducedToTarget,
                'inherentToTarget' => $results->inherent->total - $results->target->total,
            ],
            // The programme-level counterpart of a defence's own ROSI
            // (calculation-engine.md §10): what a year of the plan returns
            // per unit spent. Null rather than infinite when the plan costs
            // nothing recorded — an unknown denominator is not a windfall.
            'returnOnPlanned' => $plannedAnnualised > 0.0
                ? $reducedToTarget / $plannedAnnualised
                : null,
        ];
    }

    /**
     * @param  array<string, string>  $titles
     * @return list<array<string, mixed>>
     */
    private function riskRegister(AssessmentGraph $graph, CalculationResults $results, array $titles): array
    {
        $inherentByUuid = $this->indexRisks($results->inherent);
        $currentByUuid = $this->indexRisks($results->current);
        $targetByUuid = $this->indexRisks($results->target);

        $currentPairs = $this->indexPairs($results->current);
        $targetPairs = $this->indexPairs($results->target);

        $attacksByRisk = [];
        foreach ($graph->riskAttackLinks as $link) {
            $attacksByRisk[$link['risk_uuid']][] = $link;
        }

        $rows = [];

        foreach (array_keys($graph->risks) as $riskUuid) {
            $risk = $graph->risks[$riskUuid];
            $inherentRow = $inherentByUuid[$riskUuid] ?? null;
            $currentRow = $currentByUuid[$riskUuid] ?? null;
            $targetRow = $targetByUuid[$riskUuid] ?? null;

            $attacks = [];
            foreach ($attacksByRisk[$riskUuid] ?? [] as $link) {
                $attackUuid = $link['attack_uuid'];
                $key = $riskUuid.'|'.$attackUuid;
                $currentPair = $currentPairs[$key] ?? null;
                $targetPair = $targetPairs[$key] ?? null;

                $attacks[] = [
                    'uuid' => $attackUuid,
                    'title' => $titles[$attackUuid] ?? '',
                    'currentAle' => $currentPair === null ? null : $currentPair->ale,
                    'currentValued' => $currentPair !== null && $currentPair->valued,
                    'targetAle' => $targetPair === null ? null : $targetPair->ale,
                    'targetValued' => $targetPair !== null && $targetPair->valued,
                ];
            }

            $inherentAle = $inherentRow === null ? 0.0 : $inherentRow->ale;
            $currentAle = $currentRow === null ? 0.0 : $currentRow->ale;
            $targetAle = $targetRow === null ? 0.0 : $targetRow->ale;

            $inherentCounts = $inherentRow === null ? ['pairsValued' => 0, 'pairsTotal' => 0] : ['pairsValued' => $inherentRow->pairsValued, 'pairsTotal' => $inherentRow->pairsTotal];
            $currentCounts = $currentRow === null ? ['pairsValued' => 0, 'pairsTotal' => 0] : ['pairsValued' => $currentRow->pairsValued, 'pairsTotal' => $currentRow->pairsTotal];
            $targetCounts = $targetRow === null ? ['pairsValued' => 0, 'pairsTotal' => 0] : ['pairsValued' => $targetRow->pairsValued, 'pairsTotal' => $targetRow->pairsTotal];

            $rows[] = [
                'uuid' => $riskUuid,
                'title' => $risk['title'],
                'impactMode' => $risk['impact_mode'],
                'impactAmount' => $risk['impact_amount'],
                'impactValue' => $currentRow?->impactValue,
                'impactLevelCode' => $currentRow?->impactLevelCode,
                'attacks' => $attacks,
                'inherent' => ['ale' => $inherentAle, ...$inherentCounts],
                'current' => ['ale' => $currentAle, ...$currentCounts],
                'target' => ['ale' => $targetAle, ...$targetCounts],
                'reductionCurrent' => $this->safeRatio($inherentAle - $currentAle, $inherentAle),
                'reductionTarget' => $this->safeRatio($inherentAle - $targetAle, $inherentAle),
            ];
        }

        return $rows;
    }

    /** @return array<string, RiskResult> */
    private function indexRisks(CalculationResult $result): array
    {
        $out = [];
        foreach ($result->risks as $risk) {
            $out[$risk->uuid] = $risk;
        }

        return $out;
    }

    /** @return array<string, PairResult> keyed "riskUuid|attackUuid" */
    private function indexPairs(CalculationResult $result): array
    {
        $out = [];
        foreach ($result->pairs as $pair) {
            $out[$pair->riskUuid.'|'.$pair->attackUuid] = $pair;
        }

        return $out;
    }

    /**
     * §10 — three buckets, never one merged list; sort per bucket per the
     * spec table (priced: CBR desc tiebreak NB desc; free/unpriced: the
     * populated MC/MCadd desc — DefenceRanker already computed cbr/rosi/
     * netBenefit off whichever of mc/mcAdd applies, so "benefit" below reads
     * correctly for both the importance and the opportunity list without
     * this class needing to know which one it is looking at).
     *
     * @param  list<DefenceResult>  $defenceResults
     * @param  array<string, string>  $titles
     * @return array{priced: list<array<string, mixed>>, free: list<array<string, mixed>>, unpriced: list<array<string, mixed>>}
     */
    private function bucketAndSort(array $defenceResults, AssessmentGraph $graph, array $titles): array
    {
        $rows = array_map(function (DefenceResult $d) use ($graph, $titles): array {
            $meta = $graph->defences[$d->uuid] ?? null;

            return [
                'uuid' => $d->uuid,
                'title' => $titles[$d->uuid] ?? '',
                'isTransfer' => (bool) ($meta['is_transfer'] ?? false),
                'status' => $d->status,
                'benefit' => $d->mc ?? $d->mcAdd,
                'mc' => $d->mc,
                'mcAdd' => $d->mcAdd,
                'attribution' => $d->attribution,
                'annualCost' => $d->annualCost,
                'cbr' => $d->cbr,
                'rosi' => $d->rosi,
                'netBenefit' => $d->netBenefit,
                'costBucket' => $d->costBucket,
            ];
        }, $defenceResults);

        $priced = array_values(array_filter($rows, fn (array $r): bool => $r['costBucket'] === 'priced'));
        $free = array_values(array_filter($rows, fn (array $r): bool => $r['costBucket'] === 'free'));
        $unpriced = array_values(array_filter($rows, fn (array $r): bool => $r['costBucket'] === 'unpriced'));

        usort($priced, fn (array $a, array $b): int => ($b['cbr'] ?? -INF) <=> ($a['cbr'] ?? -INF) ?: ($b['netBenefit'] ?? -INF) <=> ($a['netBenefit'] ?? -INF));
        usort($free, fn (array $a, array $b): int => ($b['benefit'] ?? -INF) <=> ($a['benefit'] ?? -INF));
        usort($unpriced, fn (array $a, array $b): int => ($b['benefit'] ?? -INF) <=> ($a['benefit'] ?? -INF));

        return ['priced' => $priced, 'free' => $free, 'unpriced' => $unpriced];
    }

    /**
     * @param  Collection<string, Node>  $nodesByUuid
     * @return list<array<string, mixed>>
     */
    private function transfers(AssessmentGraph $graph, CalculationResults $results, Collection $nodesByUuid): array
    {
        $linksByDefence = [];
        foreach ($graph->attackDefenceLinks as $link) {
            $linksByDefence[$link['defence_uuid']][] = $link;
        }

        $currentPairs = $this->indexPairs($results->current);
        $targetPairs = $this->indexPairs($results->target);

        $riskAttackByAttack = [];
        foreach ($graph->riskAttackLinks as $link) {
            $riskAttackByAttack[$link['attack_uuid']][] = $link['risk_uuid'];
        }

        $rows = [];

        foreach ($graph->defences as $uuid => $defence) {
            if (! $defence['is_transfer']) {
                continue;
            }

            $node = $nodesByUuid->get($uuid);

            $links = [];
            foreach ($linksByDefence[$uuid] ?? [] as $link) {
                $attackUuid = $link['attack_uuid'];
                $attackTitle = $graph->attacks[$attackUuid]['title'] ?? '';
                $strength = $this->resolveLinkStrength($link, $graph);

                $retained = [];
                foreach ($riskAttackByAttack[$attackUuid] ?? [] as $riskUuid) {
                    $key = $riskUuid.'|'.$attackUuid;
                    $retained[] = [
                        'riskUuid' => $riskUuid,
                        'riskTitle' => $graph->risks[$riskUuid]['title'] ?? '',
                        'currentRetainedImpact' => $currentPairs[$key]->impactResidual ?? null,
                        'targetRetainedImpact' => $targetPairs[$key]->impactResidual ?? null,
                    ];
                }

                $links[] = [
                    'attackUuid' => $attackUuid,
                    'attackTitle' => $attackTitle,
                    'reductionTarget' => $link['reduction_target'],
                    'strength' => $strength,
                    'retained' => $retained,
                ];
            }

            $rows[] = [
                'uuid' => $uuid,
                'title' => $defence['title'],
                'description' => $node?->description,
                'status' => $defence['status'],
                'links' => $links,
            ];
        }

        return $rows;
    }

    /**
     * Pure display transform, not a calculation: resolves a link's
     * quantitative-or-qualitative strength to a single fraction for
     * rendering, exactly the way ScaleMapper::toNumber() already does for
     * the engine (calculation-engine.md §8.1) — reused, not reimplemented.
     *
     * @param  array{attack_uuid: string, defence_uuid: string, position: int, strength_mode: ?string, strength_fraction: ?float, strength_level_id: ?int, reduction_target: ?string, rationale?: ?string}  $link
     */
    private function resolveLinkStrength(array $link, AssessmentGraph $graph): ?float
    {
        if ($link['strength_mode'] === 'quantitative') {
            return $link['strength_fraction'];
        }

        if ($link['strength_mode'] === 'qualitative' && $link['strength_level_id'] !== null) {
            $level = $graph->scaleLevels[$link['strength_level_id']] ?? null;

            return $level === null ? null : $this->scaleMapper->toNumber($level);
        }

        return null;
    }

    /**
     * @param  Collection<string, Node>  $nodesByUuid
     * @return array<string, mixed>
     */
    private function tree(AssessmentGraph $graph, CalculationResults $results, Collection $nodesByUuid, ReportOptions $options): array
    {
        // One query for the whole tree, and only when the option is on.
        // `loadMissing` batches across the collection; reading
        // `$node->assignees` inside the loops below would be one query per
        // node, which is the N+1 CLAUDE.md names as the legacy tree render's
        // defining defect. Doing it here rather than in the controller keeps
        // the cost inside the option that asks for it — a report with
        // assignees off issues exactly the queries it did before W46, which
        // is what the report's query-budget test measures.
        if ($options->showAssignees) {
            // Wrapped in an Eloquent collection because the caller hands us a
            // Support one, which has no `load()`. The Node objects are the
            // same instances either way, so the relation lands on the models
            // the loops below already hold — and it goes through the relation
            // rather than a hand-rolled join, so the name ordering is defined
            // in exactly one place.
            EloquentCollection::make($nodesByUuid->values()->all())->load('assignees');
        }

        $attacksByRisk = [];
        foreach ($graph->riskAttackLinks as $link) {
            $attacksByRisk[$link['risk_uuid']][] = $link;
        }
        foreach ($attacksByRisk as &$links) {
            usort($links, fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        }
        unset($links);

        $defencesByAttack = [];
        foreach ($graph->attackDefenceLinks as $link) {
            $defencesByAttack[$link['attack_uuid']][] = $link;
        }
        foreach ($defencesByAttack as &$links) {
            usort($links, fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        }
        unset($links);

        // W33: the inherent baseline joins the other two here. The tree
        // section is the one place in the report that shows every node with
        // every figure, and "before any defence" is the reference the other
        // two are read against — without it a reader cannot see what the
        // existing controls already bought.
        $inherentAttacks = $this->indexAttacks($results->inherent);
        $currentAttacks = $this->indexAttacks($results->current);
        $targetAttacks = $this->indexAttacks($results->target);
        $inherentRisks = $this->indexRisks($results->inherent);
        $currentRisks = $this->indexRisks($results->current);
        $targetRisks = $this->indexRisks($results->target);
        $currentDefences = $this->indexDefences($results->current);
        $targetDefences = $this->indexDefences($results->target);

        $riskRows = [];

        foreach (array_keys($graph->risks) as $riskUuid) {
            $risk = $graph->risks[$riskUuid];
            $node = $nodesByUuid->get($riskUuid);

            $attackRows = [];
            foreach ($attacksByRisk[$riskUuid] ?? [] as $link) {
                $attackUuid = $link['attack_uuid'];
                $attack = $graph->attacks[$attackUuid];
                $attackNode = $nodesByUuid->get($attackUuid);

                $defenceRows = [];
                foreach ($defencesByAttack[$attackUuid] ?? [] as $dLink) {
                    $defenceUuid = $dLink['defence_uuid'];
                    $defence = $graph->defences[$defenceUuid] ?? null;
                    if ($defence === null) {
                        continue;
                    }

                    $defenceRows[] = [
                        'uuid' => $defenceUuid,
                        'title' => $defence['title'],
                        'description' => $nodesByUuid->get($defenceUuid)?->description,
                        'status' => $defence['status'],
                        'isTransfer' => $defence['is_transfer'],
                        'reductionTarget' => $dLink['reduction_target'],
                        'strength' => $this->resolveLinkStrength($dLink, $graph),
                        // Why that strength — the half of a defensible report
                        // that a number cannot carry (ui.md §7).
                        'rationale' => $dLink['rationale'] ?? null,
                        'capexAmount' => $defence['capex_amount'],
                        'opexAnnualAmount' => $defence['opex_annual_amount'],
                        'assignees' => $this->assignees($nodesByUuid->get($defenceUuid), $options),
                        'current' => $this->defenceSummary($currentDefences[$defenceUuid] ?? null),
                        'target' => $this->defenceSummary($targetDefences[$defenceUuid] ?? null),
                    ];
                }

                $attackRows[] = [
                    'uuid' => $attackUuid,
                    'title' => $attack['title'],
                    'description' => $attackNode?->description,
                    'likelihoodMode' => $attack['likelihood_mode'],
                    'likelihoodAro' => $attack['likelihood_aro'],
                    'assignees' => $this->assignees($attackNode, $options),
                    'inherent' => $this->attackSummary($inherentAttacks[$attackUuid] ?? null),
                    'current' => $this->attackSummary($currentAttacks[$attackUuid] ?? null),
                    'target' => $this->attackSummary($targetAttacks[$attackUuid] ?? null),
                    'defences' => $defenceRows,
                ];
            }

            $riskRows[] = [
                'uuid' => $riskUuid,
                'title' => $risk['title'],
                'description' => $node?->description,
                'impactMode' => $risk['impact_mode'],
                'impactAmount' => $risk['impact_amount'],
                'assignees' => $this->assignees($node, $options),
                'inherent' => $this->riskSummary($inherentRisks[$riskUuid] ?? null),
                'current' => $this->riskSummary($currentRisks[$riskUuid] ?? null),
                'target' => $this->riskSummary($targetRisks[$riskUuid] ?? null),
                'attacks' => $attackRows,
            ];
        }

        return [
            'risks' => $riskRows,
            'orphanAttacks' => array_map(fn (string $u): array => ['uuid' => $u, 'title' => $graph->attacks[$u]['title'] ?? ''], $results->current->completeness->orphanAttacks),
            'orphanDefences' => array_map(fn (string $u): array => ['uuid' => $u, 'title' => $graph->defences[$u]['title'] ?? ''], $results->current->completeness->orphanDefences),
        ];
    }

    /**
     * The names on a node, or an empty list when the option is off (W46).
     *
     * An empty list rather than a null or an absent key: the report payload's
     * shape must not depend on an option, or every consumer of it — the Vue
     * section, the JSON export, a future exporter — grows a branch for the
     * same question. The section decides whether to render a column; this
     * decides what is in it.
     *
     * Display names only, never addresses. A report is a document that leaves
     * the room, and an email address in a printed appendix is the kind of
     * disclosure nobody notices until it is already filed somewhere.
     *
     * @return list<string>
     */
    private function assignees(?Node $node, ReportOptions $options): array
    {
        if (! $options->showAssignees || $node === null) {
            return [];
        }

        return $node->assignees->map(fn (User $user): string => $user->name)->values()->all();
    }

    /** @return array<string, AttackResult> */
    private function indexAttacks(CalculationResult $result): array
    {
        $out = [];
        foreach ($result->attacks as $attack) {
            $out[$attack->uuid] = $attack;
        }

        return $out;
    }

    /** @return array<string, DefenceResult> */
    private function indexDefences(CalculationResult $result): array
    {
        $out = [];
        foreach ($result->defences as $defence) {
            $out[$defence->uuid] = $defence;
        }

        return $out;
    }

    /** @return ?array{ale: float, pairsValued: int, pairsTotal: int} */
    private function riskSummary(?RiskResult $risk): ?array
    {
        return $risk === null ? null : [
            'ale' => $risk->ale,
            'pairsValued' => $risk->pairsValued,
            'pairsTotal' => $risk->pairsTotal,
        ];
    }

    /** @return ?array{ale: float, aroResidual: ?float, factor: float} */
    private function attackSummary(?AttackResult $attack): ?array
    {
        return $attack === null ? null : [
            'ale' => $attack->ale,
            'aroResidual' => $attack->aroResidual,
            'factor' => $attack->factor,
        ];
    }

    /**
     * What the defences cite, per framework (PLAN-2.7 D64–D66). Status, not
     * figures: whether a control is in place is what a framework asks, and
     * how much each defence reduces is already the rankings'. A control is
     * `implemented` when any citing defence is, `planned` when none is but
     * one is todo, `shelved` otherwise. Nothing here says a framework is
     * met, and no uncited control is listed: this reads the assessment, not
     * a catalogue of the framework.
     *
     * Shelved defences follow `includeShelved`, as the shelved ranking does;
     * a control left out for that reason is counted, not dropped silently,
     * and a framework left with no control to list is not listed either.
     *
     * @param  Collection<string, Node>  $nodesByUuid
     * @return array{frameworks: list<array{framework: string, label: string, url: string, controls: list<array<string, mixed>>}>, hiddenShelved: int}
     */
    private function controlCoverage(AssessmentGraph $graph, Collection $nodesByUuid, ReportOptions $options): array
    {
        $attackCount = [];
        foreach ($graph->attackDefenceLinks as $link) {
            $attackCount[$link['defence_uuid']] = ($attackCount[$link['defence_uuid']] ?? 0) + 1;
        }

        /** @var array<string, array<string, list<array{uuid: string, title: string, status: string, isTransfer: bool, attackCount: int}>>> $citing */
        $citing = [];
        foreach ($graph->defences as $uuid => $defence) {
            foreach ($nodesByUuid->get($uuid)->references ?? [] as $reference) {
                $control = $reference->kind === 'control' ? ControlId::parse((string) $reference->identifier) : null;
                if ($control === null) {
                    continue;
                }
                $citing[$control->framework][$control->control][] = [
                    'uuid' => $uuid,
                    'title' => $defence['title'],
                    // Nullable in the schema. An unset status is in no
                    // baseline, and DefenceRanker reads it as shelved too.
                    'status' => $defence['status'] ?? 'shelved',
                    'isTransfer' => $defence['is_transfer'],
                    'attackCount' => $attackCount[$uuid] ?? 0,
                ];
            }
        }

        $frameworks = [];
        $hiddenShelved = 0;
        foreach (ControlId::frameworks() as $code) {
            if (! isset($citing[$code])) {
                continue;
            }

            $controls = [];
            $parts = array_keys($citing[$code]);
            usort($parts, fn (string $a, string $b): int => strnatcmp($a, $b));
            foreach ($parts as $part) {
                $defences = $citing[$code][$part];
                if (! $options->includeShelved) {
                    $defences = array_values(array_filter($defences, fn (array $d): bool => $d['status'] !== 'shelved'));
                    if ($defences === []) {
                        $hiddenShelved++;

                        continue;
                    }
                }
                usort($defences, fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
                $statuses = array_column($defences, 'status');
                $controls[] = [
                    'identifier' => $code.':'.$part,
                    'label' => ControlId::parse($code.':'.$part)?->label() ?? $part,
                    'status' => match (true) {
                        in_array('implemented', $statuses, true) => 'implemented',
                        in_array('todo', $statuses, true) => 'planned',
                        default => 'shelved',
                    },
                    'defences' => $defences,
                ];
            }

            if ($controls === []) {
                continue;
            }

            $frameworks[] = [
                'framework' => $code,
                'label' => ControlId::frameworkLabel($code),
                'url' => ControlId::frameworkUrl($code),
                'controls' => $controls,
            ];
        }

        return ['frameworks' => $frameworks, 'hiddenShelved' => $hiddenShelved];
    }

    /** @return ?array{mc: ?float, mcAdd: ?float, attribution: ?float, annualCost: ?float, costBucket: string} */
    private function defenceSummary(?DefenceResult $defence): ?array
    {
        return $defence === null ? null : [
            'mc' => $defence->mc,
            'mcAdd' => $defence->mcAdd,
            'attribution' => $defence->attribution,
            // Amortised (opex + capex / amortization_years), so the tree row
            // can print what a control actually costs per year beside the
            // one-off and recurring figures it is made of.
            'annualCost' => $defence->annualCost,
            'costBucket' => $defence->costBucket,
        ];
    }

    /**
     * @param  Collection<string, Node>  $nodesByUuid
     * @param  array<string, string>  $titles
     * @return array<string, mixed>
     */
    private function appendices(AssessmentGraph $graph, CalculationResults $results, Collection $nodesByUuid, array $titles, ReportOptions $options): array
    {
        $references = [];
        foreach ($nodesByUuid as $uuid => $node) {
            foreach ($node->references ?? [] as $reference) {
                $references[] = [
                    'nodeUuid' => $uuid,
                    'nodeTitle' => $titles[$uuid] ?? '',
                    'kind' => $reference->kind,
                    'identifier' => $reference->identifier,
                    'url' => $reference->url,
                    'title' => $reference->title,
                ];
            }
        }

        $comments = [];
        if ($options->includeComments) {
            foreach ($nodesByUuid as $uuid => $node) {
                foreach ($node->comments ?? [] as $comment) {
                    $comments[] = $this->commentRow($comment, $uuid, $titles);
                }
            }
        }

        // Warnings from all three baselines, deduped by (code, nodeUuid,
        // message) — `implausible_strength`/`independence_assumed`/etc. are
        // baseline-scoped (a link only active under some baselines), so the
        // union across all three is what the appendix needs to be complete
        // without repeating an assessment-level warning three times.
        $warnings = [];
        $seen = [];
        foreach ([$results->inherent, $results->current, $results->target] as $result) {
            foreach ($result->warnings as $warning) {
                $key = $warning->code.'|'.$warning->nodeUuid.'|'.$warning->message;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $warnings[] = [
                    'code' => $warning->code,
                    'nodeUuid' => $warning->nodeUuid,
                    'nodeTitle' => $warning->nodeUuid !== null ? ($titles[$warning->nodeUuid] ?? null) : null,
                    'message' => $warning->message,
                ];
            }
        }

        return [
            'references' => $references,
            'comments' => $comments,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, string>  $titles
     * @return array<string, mixed>
     */
    private function commentRow(Comment $comment, string $nodeUuid, array $titles): array
    {
        return [
            'nodeUuid' => $nodeUuid,
            'nodeTitle' => $titles[$nodeUuid] ?? '',
            'author' => $comment->user?->name,
            'body' => $comment->body,
            'createdAt' => $comment->created_at?->toIso8601String(),
            'resolved' => $comment->resolved_at !== null,
            'replies' => ($comment->replies ?? collect())->map(fn (Comment $reply): array => [
                'author' => $reply->user?->name,
                'body' => $reply->body,
                'createdAt' => $reply->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
