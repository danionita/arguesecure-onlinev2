<?php

namespace App\Http\Controllers;

use App\Domain\Tags\TagService;
use App\Http\Requests\Assessment\AttachTagRequest;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Labels on a node (W43, item 18).
 *
 * Two actions and no index: the assessment's whole tag vocabulary already
 * arrives with the graph (AssessmentController::show), so the autocomplete
 * has everything it needs on the client and a search endpoint would be a
 * round trip to re-send data the page is already holding.
 *
 * Both routes bind `{assessment:uuid}` and `{node:uuid}` and both check the
 * assessment through a FormRequest, per CLAUDE.md — no route touching an
 * assessment skips AssessmentPolicy.
 */
class TagController extends Controller
{
    public function store(AttachTagRequest $request, Assessment $assessment, Node $node, TagService $tags): JsonResponse
    {
        $this->assertBelongsTo($assessment, $node);

        $tag = $tags->attach($node, $request->validated()['name']);

        // The resolved tag, not the name that was sent: "pci dss" resolves to
        // an existing "PCI DSS" and the client has to render the label the
        // assessment actually carries, or two people would see the same tag
        // spelled two ways until the next reload.
        return response()->json([
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ],
        ], 201);
    }

    public function destroy(Request $request, Assessment $assessment, Node $node, Tag $tag, TagService $tags): JsonResponse
    {
        // No FormRequest here because there is no body to validate; the
        // authorization is the same `update` check and is made explicitly
        // rather than being skipped for the want of a request class.
        abort_unless((bool) $request->user()?->can('update', $assessment), 403);

        $this->assertBelongsTo($assessment, $node);

        // A tag id from another assessment must not be usable to reach into
        // this one, even though detaching a tag that is not attached is a
        // no-op: answering 404 for "not yours" and 200 for "yours but not
        // attached" is exactly the difference an enumeration attack reads
        // (security.md §3.3).
        abort_unless($tag->assessment_id === $assessment->id, 404);

        $tags->detach($node, $tag);

        return response()->json(['detached' => true]);
    }

    /**
     * Route-model binding resolves `{node:uuid}` globally — a uuid from
     * another assessment binds successfully and would then be labelled
     * through this assessment's policy check. Same guard the link routes
     * make, for the same reason.
     */
    private function assertBelongsTo(Assessment $assessment, Node $node): void
    {
        abort_unless($node->assessment_id === $assessment->id, 404);
    }
}
