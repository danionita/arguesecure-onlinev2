<?php

namespace App\Domain\Reporting;

use App\Domain\Calculation\CalculationResults;
use App\Domain\Graph\AssessmentGraph;
use App\Models\Assessment;
use App\Models\Node;
use Illuminate\Support\Collection;

/**
 * JSON export of a whole assessment (PLAN.md Phase 7: "Also provide... JSON
 * export/import of a whole assessment — the latter doubles as backup and as
 * a way to hand an assessment to someone on another instance").
 *
 * **Export only.** JSON *import* is a separate, later package per PLAN.md's
 * task graph — nothing here parses or applies this shape back onto an
 * assessment. This is a plain data dump: the graph exactly as
 * AssessmentGraphLoader produced it (so a re-import, when built, has an
 * unambiguous source of truth), the descriptive columns the calculation DTO
 * intentionally excludes (schema.md §4), and the computed results for all
 * three baselines so the export is self-contained without recomputation.
 */
final class AssessmentJsonExporter
{
    private const SCHEMA_VERSION = 1;

    /**
     * @param  Collection<string, Node>  $nodesByUuid
     * @return array<string, mixed>
     */
    public function export(Assessment $assessment, AssessmentGraph $graph, CalculationResults $results, Collection $nodesByUuid): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'exportedAt' => now()->toIso8601String(),
            'assessment' => [
                'uuid' => $assessment->uuid,
                'currency' => $assessment->currency,
                'displayMode' => $assessment->display_mode,
                'amortizationYears' => $assessment->amortization_years,
                'calcVersion' => $assessment->calc_version,
            ],
            'nodes' => $nodesByUuid->map(fn (Node $node): array => [
                'uuid' => $node->uuid,
                'type' => $node->type,
                'title' => $node->title,
                'description' => $node->description,
                'position' => $node->position,
                'impactMode' => $node->impact_mode,
                'impactAmount' => $node->impact_amount,
                'impactLevelId' => $node->impact_level_id,
                'likelihoodMode' => $node->likelihood_mode,
                'likelihoodAro' => $node->likelihood_aro,
                'likelihoodLevelId' => $node->likelihood_level_id,
                'isTransfer' => (bool) $node->is_transfer,
                'status' => $node->status,
                'capexAmount' => $node->capex_amount,
                'opexAnnualAmount' => $node->opex_annual_amount,
            ])->values()->all(),
            'riskAttackLinks' => $graph->riskAttackLinks,
            'attackDefenceLinks' => $graph->attackDefenceLinks,
            'scales' => array_values($graph->scales),
            'scaleLevels' => array_values($graph->scaleLevels),
            'results' => [
                'inherent' => $this->toArray($results->inherent),
                'current' => $this->toArray($results->current),
                'target' => $this->toArray($results->target),
            ],
        ];
    }

    /**
     * Generic readonly-DTO -> array converter, matching the same pattern
     * AssessmentController already uses for the tree editor's read route —
     * kept as its own private copy here rather than a shared helper, since
     * this package owns no file either of those live in.
     */
    private function toArray(mixed $value): mixed
    {
        if (is_object($value)) {
            return array_map([$this, 'toArray'], get_object_vars($value));
        }

        if (is_array($value)) {
            return array_map([$this, 'toArray'], $value);
        }

        return $value;
    }
}
