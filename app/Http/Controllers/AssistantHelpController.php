<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\Availability;
use App\Domain\Assistant\Generation\GenerationCancelled;
use App\Domain\Assistant\Generation\GenerationMonitor;
use App\Domain\Assistant\Generation\SingleFlight;
use App\Domain\Assistant\Help\HelpAnswerer;
use App\Http\Requests\Assistant\AskHelpRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Job A — answering a question about ArgueSecure from its help pages
 * (PLAN-2.3-ai.md W51).
 *
 * ## This route requires a login, and the help pages it reads do not
 *
 * `/help` is deliberately outside `auth`: a facilitator running a workshop or
 * a guest reading a share link should be able to read the documentation
 * without an account. This endpoint is not the documentation. It spends
 * seconds of CPU per call on a machine that is also serving everybody else,
 * which makes an anonymous version a free denial-of-service primitive against
 * the whole instance — the identical text is already readable at `/help/*`
 * without one, so requiring a login costs a guest nothing they could not
 * otherwise get.
 *
 * Three guards, and they cover different things:
 *
 * - **`assistant` middleware** — the deployment has no runtime, so the route
 *   does not exist.
 * - **`throttle:assistant`** — how many questions per minute.
 * - **{@see SingleFlight}** — how many *at once*, which a per-minute limit
 *   cannot see and which is what actually exhausts a CPU.
 */
class AssistantHelpController extends Controller
{
    public function __invoke(
        AskHelpRequest $request,
        Availability $availability,
        SingleFlight $singleFlight,
        GenerationMonitor $monitor,
        HelpAnswerer $answerer,
    ): JsonResponse {
        // The feature's own switch (W87, D48), passed by name. Availability
        // still requires the deployment runtime, the instance switch and a
        // healthy probe on top of it — this can only ever subtract.
        if (! $availability->isAvailable(Availability::FEATURE_HELP)) {
            // 503, not 500: nothing is broken about the request, the feature
            // is off or its runtime is not answering. The reason is the one
            // Availability composes, so the user is told which.
            return response()->json(
                ['message' => $availability->reason(Availability::FEATURE_HELP) ?? 'The assistant is unavailable.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $question = $request->validated()['question'];

        /** @var Authenticatable $user */
        $user = $request->user();
        $scope = GenerationMonitor::userScope($user);

        try {
            $answer = $singleFlight->run(
                $user,
                function () use ($monitor, $scope, $answerer, $question): string {
                    $monitor->reset($scope);
                    $monitor->start($scope, (int) config('assistant.max_tokens.help'));

                    try {
                        return $answerer->answer($question, $monitor->observer($scope));
                    } finally {
                        $monitor->finish($scope);
                    }
                },
            );
        } catch (GenerationCancelled) {
            // A cancelled question is not a failed one. Nothing is reported,
            // nothing is logged, and the browser that asked has usually gone.
            return response()->json(['cancelled' => true], Response::HTTP_OK);
        } catch (RuntimeException $e) {
            // "The model is wedged" and "the application is broken" have
            // completely different remedies, and a generic 500 tells the user
            // neither. Reported at 503 with a next step; the exception itself
            // still reaches the log for whoever has to fix it.
            report($e);

            return response()->json([
                'message' => 'The assistant could not finish answering. Try again.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($answer === null) {
            return response()->json([
                'message' => 'You already have a question being answered. Wait for it to finish.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return response()->json([
            // Plain text, and it stays plain text all the way to the screen.
            // W30 deleted this codebase's only user-authored-HTML renderer
            // along with `notesPreview.ts`; model output is the last thing
            // that should reintroduce one, and the prompt asks for prose
            // precisely so there is no markup to render.
            'answer' => $answer,
            // Marked at the point of use, every time. The reader must never
            // have to remember which sentences on their screen came from a
            // model.
            'generated' => true,
        ]);
    }
}
