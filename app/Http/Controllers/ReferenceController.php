<?php

namespace App\Http\Controllers;

use App\Domain\References\ReferenceValidator;
use App\Http\Requests\Assessment\DestroyNodeReferenceRequest;
use App\Http\Requests\Assessment\ReorderNodeReferencesRequest;
use App\Http\Requests\Assessment\StoreNodeReferenceRequest;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\NodeReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * CVE and friends (schema.md §3.12, ui.md §7 "References" tab, PLAN.md
 * Phase 8 item 5). **No outbound API calls, ever** — nothing here fetches
 * or enriches anything; App\Domain\References\ReferenceValidator derives
 * every non-`url`/`other` link as a pure function of the stored
 * identifier.
 *
 * `index` needs only `view` (read-only share links and public assessments
 * may read references, matching CommentController::index's own
 * `view`-for-read / `update`-for-write split); every mutation needs
 * `update` on the owning assessment — a reference is structural content of
 * a node, not a discussion, so `comment` is not enough.
 */
class ReferenceController extends Controller
{
    public function index(Request $request, Assessment $assessment, Node $node): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('view', $assessment)) {
            abort(403);
        }

        return response()->json([
            'references' => $node->references->map(fn (NodeReference $r) => $this->present($r))->values(),
        ]);
    }

    public function store(StoreNodeReferenceRequest $request, Assessment $assessment, Node $node): JsonResponse
    {
        $validated = $request->validated();
        $kind = $validated['kind'];

        // The URL for a derived kind is never taken from input: deriveUrl()
        // has no $url parameter to smuggle one through (security.md §7).
        // `url`/`other` use the validated `url` field directly instead.
        $url = in_array($kind, ReferenceValidator::URL_KINDS, true)
            ? $validated['url']
            : ReferenceValidator::deriveUrl($kind, $validated['identifier']);

        $nextPosition = (int) ($node->references()->max('position') ?? -1) + 1;

        $reference = $node->references()->create([
            'kind' => $kind,
            'identifier' => $kind === 'url' ? null : $validated['identifier'],
            'url' => $url,
            'title' => $validated['title'] ?? null,
            'position' => $nextPosition,
        ]);

        return response()->json(['reference' => $this->present($reference)], 201);
    }

    public function reorder(ReorderNodeReferencesRequest $request, Assessment $assessment, Node $node): JsonResponse
    {
        $order = $request->validated()['order'];

        DB::transaction(function () use ($order): void {
            foreach (array_values($order) as $position => $id) {
                NodeReference::query()->whereKey($id)->update(['position' => $position]);
            }
        });

        return response()->json([
            'references' => $node->references()->get()->map(fn (NodeReference $r) => $this->present($r))->values(),
        ]);
    }

    public function destroy(DestroyNodeReferenceRequest $request, Assessment $assessment, Node $node, NodeReference $reference): JsonResponse
    {
        // {reference} binds by plain id and is not scoped by route-model
        // binding (see the FormRequest's own docblock) — re-assert the
        // membership explicitly, the same IDOR pattern
        // NodeRevisionController::revert() uses for its own plain-id-bound
        // {revision}.
        abort_unless($reference->node_id === $node->id, 404);

        $reference->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function present(NodeReference $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'identifier' => $r->identifier,
            'url' => $r->url,
            'title' => $r->title,
            'position' => $r->position,
        ];
    }
}
