<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\Availability;
use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Http\Requests\Assessment\ShowAssessmentRequest;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\Tag;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tree editor's read route (ui.md Â§3, PLAN.md W12). Deliberately the
 * one place in this package that queries `nodes` beyond what
 * AssessmentGraphLoader already loads: the loader's DTO is calculation
 * input only (schema.md Â§4) and carries no `description`,
 * `position` or `updated_at` â€” all needed here for editing and for the
 * autosave conflict check (ui.md Â§3.5) â€” so a second, single, flat query
 * against `nodes` supplies them. The loader's own four queries are
 * untouched and still asserted by its own test
 * (tests/Feature/Domain/AssessmentGraphLoaderTest.php); this controller's
 * total is asserted flat (independent of graph size) by
 * tests/Feature/Assessment/AssessmentShowTest.php.
 */
class AssessmentController extends Controller
{
    public function show(
        ShowAssessmentRequest $request,
        Assessment $assessment,
        AssessmentGraphLoader $loader,
        RiskCalculator $calculator,
        CalculationCache $cache,
    ): Response {
        $graph = $loader->load($assessment);
        // Read-through: the key carries calc_version, which every mutation
        // bumps, so a stale entry is never looked up rather than served.
        $results = $cache->remember($assessment, fn () => $calculator->compute($graph));

        // Single flat query hydrating every node type in one pass via
        // Node::$typeMap. No withoutGlobalScopes() and no explicit
        // whereNull('deleted_at'): the per-subclass `type` scope is declared on
        // Risk/Attack/Defence/RootNode and never on Node, so a base-class query
        // has none to strip, and SoftDeletingScope â€” the only global scope Node
        // does carry â€” is exactly the one that must stay on.
        // `tags` eager-loaded (W43): two more queries for the whole graph,
        // constant in the number of nodes, which is the property the budget
        // test asserts. A per-node tag lookup would be the N+1 CLAUDE.md
        // names as the legacy tree render's defining defect.
        $nodes = Node::query()
            ->with('tags:id,name,slug,color')
            ->where('assessment_id', $assessment->id)
            ->orderBy('position')
            ->get();

        $user = $request->user();

        return Inertia::render('Assessment/Show', [
            'assessment' => [
                'uuid' => $assessment->uuid,
                'currency' => $assessment->currency,
                'display_mode' => $assessment->display_mode,
                'amortization_years' => $assessment->amortization_years,
                // W44. Display only: the card renders a badge against it and
                // nothing else reads it. It is deliberately absent from the
                // AssessmentGraph the calculator receives.
                'risk_tolerance' => $assessment->risk_tolerance,
                'is_public' => (bool) $assessment->is_public,
                // W141. A template opens in this same editor; the page only
                // hides what has no meaning without an owner (sharing).
                'is_template' => (bool) $assessment->is_template,
            ],
            'nodes' => $nodes->map(fn (Node $node): array => $this->nodeToArray($node))->values(),
            'riskAttackLinks' => $graph->riskAttackLinks,
            'attackDefenceLinks' => $graph->attackDefenceLinks,
            // The assessment's whole label vocabulary, which is what the
            // autocomplete offers and the filter bar lists. Sent even when
            // no node carries a tag: a label someone defined and then
            // removed from its last node is still part of the vocabulary,
            // and offering it again is how it gets reused rather than
            // retyped into a near-duplicate.
            'tags' => $assessment->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'color' => $tag->color,
                ])->values(),
            'scaleLevels' => array_values($graph->scaleLevels),
            'scales' => array_values($graph->scales),
            'results' => $this->toArray($results),
            'abilities' => [
                'comment' => (bool) $user?->can('comment', $assessment),
                'update' => (bool) $user?->can('update', $assessment),
                'manage' => (bool) $user?->can('manage', $assessment),
                'transfer' => (bool) $user?->can('transferOwnership', $assessment),
                // W141. Whether the Share dialog may offer "Publish as template".
                'publishTemplate' => (bool) $user?->can('saveAsTemplate', $assessment) && ! $assessment->is_template,
            ],
            // W52. Sent with the page rather than fetched from
            // /assistant/status on mount: the editor already makes one request
            // for the whole graph, and a second round trip on every load â€” on
            // the overwhelming majority of deployments that run no runtime at
            // all â€” buys nothing. A guest gets false without a probe.
            // The suggestions switch by name (W87, D48): the editor's only
            // assistant control suggests children, so this prop must answer
            // for that feature rather than for the assistant in general.
            'assistantAvailable' => $user !== null && app(Availability::class)->isAvailable(Availability::FEATURE_SUGGESTIONS),
        ]);
    }

    /** @return array<string, mixed> */
    private function nodeToArray(Node $node): array
    {
        return [
            'uuid' => $node->uuid,
            'type' => $node->type,
            'title' => (string) $node->title,
            'description' => $node->description,
            'position' => $node->position,
            'impact_mode' => $node->impact_mode,
            'impact_amount' => $node->impact_amount,
            'impact_level_id' => $node->impact_level_id,
            'likelihood_mode' => $node->likelihood_mode,
            'likelihood_aro' => $node->likelihood_aro,
            'likelihood_level_id' => $node->likelihood_level_id,
            // W43. Sent per node rather than as a separate `node_tag` map:
            // the store keys nodes by uuid and every consumer â€” the chip row,
            // the filter's `tagsForNode` â€” asks "what does this node carry?",
            // never the reverse.
            'tags' => $node->tags->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ])->values()->all(),
            'is_transfer' => (bool) $node->is_transfer,
            'status' => $node->status,
            'capex_amount' => $node->capex_amount,
            'opex_annual_amount' => $node->opex_annual_amount,
            'updated_at' => $node->updated_at?->toJSON(),
        ];
    }

    /**
     * Generic readonly-DTO -> array converter for App\Domain\Calculation's
     * result objects (outside this package's ownership). get_object_vars(),
     * called here from outside the DTO's own class, only ever sees public
     * properties â€” which is everything those classes declare (constructor
     * promoted, readonly) â€” so this stays correct without this controller
     * hardcoding each DTO's shape and drifting from it over time.
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
