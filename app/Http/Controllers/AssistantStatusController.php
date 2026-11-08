<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\Availability;
use Illuminate\Http\JsonResponse;

/**
 * What the assistant can currently do, for a client deciding whether to offer
 * an action (docs/ASSISTANT.md §3).
 *
 * This is the whole of the status surface: no generation happens here and no
 * prompt is built. The generation jobs are elsewhere; this tells them whether
 * to appear.
 *
 * **The route is not registered at all when the deployment has no runtime**
 * (see the assistant block in routes/web.php), so this controller is only ever
 * reached on an installation that has one. It therefore never returns
 * `NOT_CONFIGURED` — that state is expressed by the endpoint being absent,
 * which is the honest encoding: a feature this installation does not have
 * should 404, not answer questions about itself.
 */
class AssistantStatusController extends Controller
{
    public function __invoke(Availability $availability): JsonResponse
    {
        $state = $availability->state();

        // No `model` any more (2.6). It was `config('assistant.model')`,
        // a label an operator typed that could name one model while the
        // container ran another. Which model is running is now asked of the
        // runtime itself, on the admin tab only — not here, where every
        // client that decides whether to show a button would pay for the
        // probe.
        return response()->json([
            'state' => $state,
            'available' => $state === Availability::AVAILABLE,
            'reason' => $availability->reason(),
        ]);
    }
}
