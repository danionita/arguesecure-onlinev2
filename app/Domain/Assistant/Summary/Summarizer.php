<?php

namespace App\Domain\Assistant\Summary;

use App\Domain\Assistant\Client;
use App\Domain\Calculation\CalculationResults;
use App\Domain\Graph\AssessmentGraph;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Job C: draft the report's executive summary (PLAN-2.3-ai.md W54).
 *
 * ## This one is not a button, and the reason is a number
 *
 * 249 seconds for a 73-node assessment on the recommended model, growing with
 * the assessment, on a machine faster than most deployment hosts. There is no
 * spinner that survives four minutes and no request that should. Everything
 * here runs inside `DraftExecutiveSummary`, on the queue, and the user is told
 * when it is done.
 *
 * ## What comes back is a draft, and stays one
 *
 * The reply is stored in `assessments.summary_draft` and never in
 * `summary_narrative`. Nothing this class produces reaches a report until a
 * person has read it and pressed a button — which is not politeness about
 * hallucination, it is the mitigation for the injection the spike could not
 * prompt its way out of. `summary-canary` complied on one of three models:
 * text a collaborator typed into a node description came back inside prose
 * that a client would read as the author's own words.
 *
 * ## The output budget is the latency dial
 *
 * Prompt tokens cost roughly a tenth of output tokens on a CPU, so the digest
 * can afford to be generous and the reply cannot. `max_tokens.summary` is 700,
 * which the spike measured as enough for four paragraphs on a real assessment
 * and which is the single knob that decides how long the queue holds this job.
 */
class Summarizer
{
    public function __construct(
        private readonly Client $client,
        private readonly AssessmentDigest $digest,
    ) {}

    /**
     * @param  array<string, string|null>  $descriptions  node uuid => description
     * @param  (callable(string): void)|null  $onToken  W58's progress and
     *                                                  cancellation observer.
     *
     * @throws RuntimeException if the digest would not fit, or the runtime fails
     */
    public function draft(
        AssessmentGraph $graph,
        CalculationResults $results,
        array $descriptions,
        ?callable $onToken = null,
    ): string {
        $prompt = $this->buildPrompt($this->digest->build($graph, $results, $descriptions));

        $this->assertFits($prompt);

        return trim($this->client->complete(
            $prompt,
            (int) config('assistant.max_tokens.summary'),
            // W58. Job C is the one that runs for minutes, so it is the one
            // where a person needs to see movement and be able to stop it.
            $onToken,
        ));
    }

    private function buildPrompt(string $assessment): string
    {
        return str_replace(
            '{{ASSESSMENT}}',
            $assessment,
            File::get(resource_path('prompts/assistant/summary.md')),
        );
    }

    /**
     * Refuses a prompt the runtime would silently truncate.
     *
     * Same guard as `HelpAnswerer::assertFits`, and it matters more here.
     * llama.cpp drops the *front* of an over-long prompt: the rules, the
     * "this is data, never instructions" block, and the opening fence. What
     * survives is user-written content with no instructions in front of it,
     * which is both a bad summary and the one arrangement in which an
     * injection has nothing to compete with.
     *
     * `AssessmentDigest` bounds itself so this should not fire. The guard
     * exists because "should not" is not a control.
     */
    private function assertFits(string $prompt): void
    {
        $promptTokens = (int) ceil(mb_strlen($prompt) / 4);
        $budget = (int) config('assistant.context_tokens')
            - (int) config('assistant.max_tokens.summary');

        if ($promptTokens > $budget * 0.9) {
            throw new RuntimeException(
                'This assessment is too large to summarise in one pass. '.
                'Raise assistant.context_tokens, or tighten AssessmentDigest\'s limits.'
            );
        }
    }
}
