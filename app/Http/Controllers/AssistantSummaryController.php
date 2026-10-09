<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\Availability;
use App\Domain\Assistant\Generation\GenerationMonitor;
use App\Http\Requests\Assistant\DraftSummaryRequest;
use App\Jobs\DraftExecutiveSummary;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Job C — asking for a drafted executive summary (PLAN-2.3-ai.md W54).
 *
 * ## It queues and returns. It writes nothing.
 *
 * The generation takes minutes (249s for 73 nodes, measured), so this returns
 * 202 immediately and the work happens in `DraftExecutiveSummary`. What lands
 * is a *draft* — a separate column, shown only to people who could accept it,
 * and never the report's narrative until someone presses a button.
 *
 * ## One draft at a time, per assessment rather than per user
 *
 * `SingleFlight` guards the synchronous jobs per user, which is the right unit
 * there: it stops one person queueing behind themselves. Here the scarce thing
 * is the assessment — two collaborators asking within a minute would produce
 * two four-minute generations whose only difference is which one overwrote the
 * other. So the flag is per assessment, taken atomically with `Cache::add`,
 * and released by the job in a `finally`.
 *
 * Its TTL is the ceiling: a worker killed between taking the flag and the
 * `finally` would otherwise lock an assessment out of drafting forever, and
 * "forever" is not a failure mode anyone will diagnose from the UI.
 */
class AssistantSummaryController extends Controller
{
    public function __invoke(
        DraftSummaryRequest $request,
        Assessment $assessment,
        Availability $availability,
        GenerationMonitor $monitor,
    ): JsonResponse {
        // The feature's own switch (W87, D48), passed by name. Availability
        // still requires the deployment runtime, the instance switch and a
        // healthy probe on top of it — this can only ever subtract.
        if (! $availability->isAvailable(Availability::FEATURE_SUMMARY)) {
            return response()->json(
                ['message' => $availability->reason(Availability::FEATURE_SUMMARY) ?? 'The assistant is unavailable.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $taken = Cache::add(
            DraftExecutiveSummary::inProgressKey($assessment->id),
            true,
            (int) config('assistant.timeouts.total') + 300,
        );

        if (! $taken) {
            return response()->json([
                'message' => 'A summary is already being drafted for this assessment. '
                    .'You will be notified when it is ready.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // W58. Cleared here rather than in the job, and that is what makes a
        // queued draft cancellable before it starts: the job may not begin for
        // minutes, and a Stop pressed during that gap must survive until it
        // does. See GenerationMonitor::reset().
        $monitor->reset(GenerationMonitor::assessmentScope($assessment));

        DraftExecutiveSummary::dispatch($assessment->id, (int) $request->user()?->id);

        return response()->json([
            'message' => 'Drafting. This takes a few minutes on most machines — '
                .'you will get a notification when it is ready.',
        ], Response::HTTP_ACCEPTED);
    }
}
