<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\Generation\GenerationMonitor;
use App\Http\Requests\Assistant\DraftSummaryRequest;
use App\Jobs\DraftExecutiveSummary;
use App\Models\Assessment;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a generation is doing, and how to stop it (W58).
 *
 * ## Two scopes, and they are not the same shape
 *
 * The **user** routes cover job A and job B — synchronous work running inside
 * somebody's own request. The person who can see it and stop it is the person
 * who started it, and nobody else has any business knowing they asked.
 *
 * The **assessment** routes cover job C, which runs on a queue, outlives the
 * request that queued it, and belongs to the assessment rather than to the
 * person. Anyone who could have started it can see it and stop it: the flag
 * that prevents a second draft is per assessment for exactly the same reason,
 * and a draft nobody but its originator could stop would leave a four-minute
 * generation running when that person closes their laptop.
 *
 * Authorization for those reuses `DraftSummaryRequest` — the same class, not
 * merely the same ability — so "who may stop a draft" cannot drift from "who
 * may start one" through somebody editing one of them.
 *
 * ## Why polling, and why these routes are not under `throttle:assistant`
 *
 * A browser asks for progress every few seconds while a generation runs. The
 * 6/min assistant limiter exists to bound CPU spent on *inference*; a cache
 * read spends none, and putting the poll in that bucket would make asking how
 * a four-minute job is going impossible after the first thirty seconds. They
 * sit in the ordinary authenticated bucket instead.
 *
 * Polling rather than a websocket, though Reverb is right there: progress is a
 * private number about one person's request, it is worthless the moment the
 * generation ends, and a presence channel per generation would be more moving
 * parts than a cache read behind an ordinary GET.
 */
class AssistantGenerationController extends Controller
{
    /** Progress on the caller's own synchronous generation (jobs A and B). */
    public function show(Request $request, GenerationMonitor $monitor): JsonResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();

        return $this->progressResponse($monitor->progress(GenerationMonitor::userScope($user)));
    }

    /** Stops the caller's own generation. */
    public function destroy(Request $request, GenerationMonitor $monitor): JsonResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();

        $monitor->cancel(GenerationMonitor::userScope($user));

        // 202, not 200: the flag is set, and the generation stops within about
        // a second when its next token arrives. Claiming it has already
        // stopped would be a lie the client could catch by polling.
        return response()->json(['cancelling' => true], Response::HTTP_ACCEPTED);
    }

    /** Progress on this assessment's queued summary draft (job C). */
    public function summary(DraftSummaryRequest $request, Assessment $assessment, GenerationMonitor $monitor): JsonResponse
    {
        return $this->progressResponse(
            $monitor->progress(GenerationMonitor::assessmentScope($assessment)),
            // A draft can be queued and not yet started — the worker may be
            // busy, or absent. "Queued but not generating" and "nothing
            // happening" look identical from the progress row alone, and the
            // difference is the whole question when somebody is watching a
            // page and wondering whether to press the button again.
            queued: Cache::has(DraftExecutiveSummary::inProgressKey($assessment->id)),
        );
    }

    /** Stops this assessment's queued summary draft. */
    public function cancelSummary(DraftSummaryRequest $request, Assessment $assessment, GenerationMonitor $monitor): JsonResponse
    {
        $monitor->cancel(GenerationMonitor::assessmentScope($assessment));

        return response()->json(['cancelling' => true], Response::HTTP_ACCEPTED);
    }

    /**
     * @param  array<string, int>|null  $progress
     */
    private function progressResponse(?array $progress, bool $queued = false): JsonResponse
    {
        if ($progress === null) {
            return response()->json(['running' => false, 'queued' => $queued]);
        }

        return response()->json([
            'running' => true,
            'queued' => $queued,
            'tokens' => $progress['tokens'],
            // The budget from config/assistant.php, so the bar has a
            // denominator. A generation that stops early — and most do, since
            // the model usually finishes before its budget — simply never
            // fills it, which is honest: the bar is an upper bound, not a
            // prediction, and it is labelled as one in the interface.
            'expected_tokens' => $progress['expected_tokens'],
            'elapsed_seconds' => $progress['elapsed_seconds'],
        ]);
    }
}
