<?php

namespace App\Jobs;

use App\Domain\Assistant\Generation\GenerationCancelled;
use App\Domain\Assistant\Generation\GenerationMonitor;
use App\Domain\Assistant\Summary\Summarizer;
use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Domain\Notifications\SummaryDrafted;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job C, on the queue where it belongs (PLAN-2.3-ai.md W54).
 *
 * 249 seconds for 73 nodes on the recommended model, 499 on the slowest
 * candidate, growing with the assessment — measured on a workstation faster
 * than a typical Unraid host. This was never going to be a request.
 *
 * ## One try, and a timeout longer than the client's
 *
 * `$tries = 1`. A failed generation on a CPU costs minutes of every core the
 * host has; retrying it three times turns one bad request into a quarter hour
 * of contention with the application it shares a machine with. If it failed,
 * the person is told and can ask again with a keystroke.
 *
 * `$timeout` sits above `assistant.timeouts.total` deliberately. The client
 * enforces its own deadline and throws something this class can explain; a
 * worker timeout firing first would kill the process mid-generation, leave the
 * in-progress flag set until its TTL expires, and send no notification at all.
 * The outer limit exists only to catch a wedge the client's own timeouts
 * cannot see.
 */
class DraftExecutiveSummary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public int $assessmentId,
        public int $requestedByUserId,
    ) {}

    /** The flag that stops a second draft being queued while this one runs. */
    public static function inProgressKey(int $assessmentId): string
    {
        return "assistant:summary:in-progress:{$assessmentId}";
    }

    public function handle(
        AssessmentGraphLoader $loader,
        RiskCalculator $calculator,
        CalculationCache $cache,
        Summarizer $summarizer,
        GenerationMonitor $monitor,
    ): void {
        $assessment = Assessment::find($this->assessmentId);
        $user = User::find($this->requestedByUserId);

        // Either deleted between the request and the run. Nothing to write and
        // nobody to tell — clearing the flag is all that is left to do.
        if ($assessment === null || $user === null) {
            Cache::forget(self::inProgressKey($this->assessmentId));

            return;
        }

        $scope = GenerationMonitor::assessmentScope($assessment);

        try {
            // Cancelled while it sat in the queue. Checked before anything
            // expensive: on a busy worker the gap between queueing a draft and
            // starting it is exactly when somebody realises they did not mean
            // to ask, and starting a four-minute generation they have already
            // stopped would be the worst possible reading of the request.
            if ($monitor->isCancelled($scope)) {
                throw new GenerationCancelled;
            }

            $graph = $loader->load($assessment);
            $results = $cache->remember($assessment, fn () => $calculator->compute($graph));

            // W58. Opened after the graph and the calculation, not before: a
            // progress row that starts ticking while the queue is still
            // loading would report a generation that has not begun, and on a
            // large assessment that is the difference between "working" and
            // "stuck at zero tokens for thirty seconds".
            $monitor->start($scope, (int) config('assistant.max_tokens.summary'));

            $draft = $summarizer->draft(
                $graph,
                $results,
                $this->descriptions($assessment),
                $monitor->observer($scope),
            );

            if ($draft === '') {
                // A model that spent its whole budget and produced nothing is
                // a real outcome — the spike watched one candidate do exactly
                // that on this job — and it must not be stored as an empty
                // draft that looks like a finished one.
                throw new \RuntimeException('The model returned an empty draft.');
            }

            // Never `summary_narrative`. A draft that overwrote the narrative
            // would arrive minutes later on top of whatever the author has
            // typed since, and would put un-reviewed model text into a report.
            $assessment->summary_draft = $draft;
            $assessment->save();

            $user->notify(new SummaryDrafted($assessment, succeeded: true));
        } catch (GenerationCancelled) {
            // Somebody pressed Stop. Not logged and not reported: it is a
            // decision, not a fault. The notification still goes out because
            // the person who *asked* for the draft may not be the person who
            // stopped it — a collaborator can cancel a draft on a shared
            // assessment — and silence would leave them waiting for something
            // that is not coming.
            $user->notify(new SummaryDrafted(
                $assessment,
                succeeded: false,
                reason: 'Drafting was stopped before it finished.',
            ));
        } catch (Throwable $e) {
            Log::warning('DraftExecutiveSummary failed.', [
                'assessment_id' => $this->assessmentId,
                'exception' => $e->getMessage(),
            ]);

            // The message comes from this application's own exceptions —
            // Client and Summarizer throw sentences written for a person. A
            // driver-level failure would not, so anything unrecognised is
            // reported as what the user can act on rather than verbatim.
            $user->notify(new SummaryDrafted(
                $assessment,
                succeeded: false,
                reason: $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'The assistant could not be reached. Try again, or ask an administrator to check it.',
            ));
        } finally {
            Cache::forget(self::inProgressKey($this->assessmentId));
            $monitor->finish($scope);
        }
    }

    /**
     * Node descriptions, which the graph DTO deliberately does not carry.
     *
     * One flat query for the whole assessment, keyed by uuid — the same shape
     * AssessmentController::show uses and for the same reason: the graph is
     * calculation input (schema.md §4) and a description is not one.
     *
     * @return array<string, string|null>
     */
    private function descriptions(Assessment $assessment): array
    {
        return Node::query()
            ->where('assessment_id', $assessment->id)
            ->pluck('description', 'uuid')
            ->all();
    }
}
