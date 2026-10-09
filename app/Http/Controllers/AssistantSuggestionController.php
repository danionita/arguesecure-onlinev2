<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\Availability;
use App\Domain\Assistant\Generation\GenerationCancelled;
use App\Domain\Assistant\Generation\GenerationMonitor;
use App\Domain\Assistant\Generation\SingleFlight;
use App\Domain\Assistant\Suggestions\Suggester;
use App\Http\Requests\Assistant\SuggestChildrenRequest;
use App\Models\Assessment;
use App\Models\Node;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Job B — suggesting children for a node (PLAN-2.3-ai.md W52).
 *
 * ## It returns text. It does not touch the graph.
 *
 * There is no write here and there must never be one. A suggestion becomes a
 * node only when a person clicks accept, and that click goes through
 * `NodeController::store` — the same validated endpoint a manually created
 * node goes through, with the same policy check and the same undo entry.
 *
 * That is not caution about model quality. The spike measured that text
 * injected into a node description reaches the model's output on **3 of 3**
 * usable models, and that no prompt wording prevents it, because an injection
 * that merely adds to the output costs the model nothing to obey. `title` is a
 * field the accept path legitimately reads, so no parser can tell an injected
 * title from a genuine one. **The person reading the suggestion is the
 * guardrail** (D24) — everything else is defence in depth behind them.
 */
class AssistantSuggestionController extends Controller
{
    public function __invoke(
        SuggestChildrenRequest $request,
        Assessment $assessment,
        Availability $availability,
        SingleFlight $singleFlight,
        GenerationMonitor $monitor,
        Suggester $suggester,
    ): JsonResponse {
        // The feature's own switch (W87, D48), passed by name. Availability
        // still requires the deployment runtime, the instance switch and a
        // healthy probe on top of it — this can only ever subtract.
        if (! $availability->isAvailable(Availability::FEATURE_SUGGESTIONS)) {
            return response()->json(
                ['message' => $availability->reason(Availability::FEATURE_SUGGESTIONS) ?? 'The assistant is unavailable.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $validated = $request->validated();

        /** @var Node $parent */
        $parent = Node::query()
            ->where('assessment_id', $assessment->id)
            ->where('uuid', $validated['parent_uuid'])
            ->firstOrFail();

        $childType = Suggester::childTypeFor($parent->type);

        if ($childType === null) {
            return response()->json(
                ['message' => 'That node cannot have children.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $count = (int) ($validated['count'] ?? 5);

        /** @var Authenticatable $user */
        $user = $request->user();
        $scope = GenerationMonitor::userScope($user);

        try {
            $suggestions = $singleFlight->run(
                $user,
                function () use ($monitor, $scope, $suggester, $assessment, $parent, $count): array {
                    // Opened inside the lock, not before it: a caller who is
                    // refused for already having a generation running must not
                    // reset the progress of the one they are queueing behind.
                    $monitor->reset($scope);
                    $monitor->start($scope, Suggester::budgetFor($count));

                    try {
                        return $suggester->suggest($assessment, $parent, $count, $monitor->observer($scope));
                    } finally {
                        $monitor->finish($scope);
                    }
                },
            );
        } catch (GenerationCancelled) {
            // Not a failure and not reported. The person pressed Stop; the
            // browser has usually abandoned this response already, and the
            // only thing that matters is that the lock and the progress row
            // were released on the way out — which the `finally` above did.
            return response()->json(['cancelled' => true], Response::HTTP_OK);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'The assistant could not finish. Try again.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($suggestions === null) {
            return response()->json([
                'message' => 'You already have a suggestion running. Wait for it to finish.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return response()->json([
            'child_type' => $childType,
            'suggestions' => $suggestions,
            // Marked here, and marked again at the point of use. The reader
            // must never have to remember which of the things on their screen
            // a model wrote.
            'generated' => true,
        ]);
    }
}
