<?php

namespace App\Domain\Reporting;

use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Models\Assessment;
use App\Models\Node;
use Illuminate\Support\Collection;

/**
 * Load the graph, compute the baselines, assemble the report — once, in one
 * place, for the page and for all five exports (W74 item 25).
 *
 * Every export route used to repeat this sequence in the controller, and the
 * repetition was the risk: the CSV route loaded nodes without comments, the
 * JSON route built its own `ReportOptions`, and adding three more formats
 * would have meant three more copies of an eight-line ritual that has to be
 * identical or the figures diverge. **The exporters read this output, never
 * the database.** An exporter that re-queried would be a second source of
 * truth for the same numbers, and a second source of truth eventually
 * disagrees.
 *
 * The four-query budget (CLAUDE.md: "a whole assessment must load in four
 * queries") is `AssessmentGraphLoader`'s, and the one flat node query below
 * is the same one `AssessmentController::show` issues. Nothing here adds a
 * query per node, per risk or per format.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly AssessmentGraphLoader $loader,
        private readonly RiskCalculator $calculator,
        private readonly CalculationCache $cache,
        private readonly ReportAssembler $assembler,
    ) {}

    /**
     * @return array<string, mixed> the whole of ReportAssembler::assemble()
     */
    public function build(Assessment $assessment, ReportOptions $options): array
    {
        $graph = $this->loader->load($assessment);
        $results = $this->cache->remember($assessment, fn () => $this->calculator->compute($graph));
        $nodesByUuid = $this->loadNodes($assessment, includeComments: $options->includeComments);

        $assessment->loadMissing('owner');

        return $this->assembler->assemble($assessment, $graph, $results, $nodesByUuid, $options);
    }

    /**
     * One flat query hydrating every node type in one pass via
     * `Node::$typeMap` (same pattern as `AssessmentController::show`), plus
     * `references` always (appendix §8) and `comments`/`replies`/`user` only
     * when the report options ask for them — skipping that join entirely is
     * the one real saving `ReportOptions::$includeComments` buys, since every
     * other option only changes how already-computed data is rendered.
     *
     * @return Collection<string, Node>
     */
    private function loadNodes(Assessment $assessment, bool $includeComments): Collection
    {
        $query = Node::query()
            ->where('assessment_id', $assessment->id)
            ->orderBy('position')
            ->with('references');

        if ($includeComments) {
            $query->with(['comments' => function ($q) {
                $q->whereNull('parent_comment_id')->with(['user', 'replies.user']);
            }]);
        }

        return $query->get()->keyBy('uuid');
    }
}
