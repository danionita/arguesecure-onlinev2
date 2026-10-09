<?php

namespace App\Http\Controllers;

use App\Http\Requests\Assessment\UpdateSummaryNarrativeRequest;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The report's written executive summary, and the draft waiting to become one
 * (W54).
 *
 * ## Not an assistant route
 *
 * Nothing here is behind the `assistant` middleware and nothing here needs a
 * runtime. The narrative is an ordinary field of the assessment: a person
 * types it, edits it, clears it. A deployment that has never had an inference
 * container — which is nearly all of them (D25) — gets the whole of this
 * screen and simply never sees a Draft button.
 *
 * That also settles what happens when an assistant is removed after use: the
 * text stays, the generated mark stays, and the report keeps disclosing what
 * it always disclosed. Nothing stores a reference to a model, so there is
 * nothing to dangle.
 *
 * ## The generated mark is sticky on purpose
 *
 * Accepting a draft sets it. Editing the accepted text does **not** clear it —
 * a reworded model draft is still a model draft, and the disclaimer in the
 * methodology appendix exists for the reader, not for the author's sense of
 * authorship. Clearing the narrative clears the mark, because there is then
 * nothing left to disclose. Someone who wants an undeclared summary can clear
 * it and write their own from nothing, which is exactly the act the mark
 * describes.
 *
 * Responses are JSON rather than an Inertia redirect: the report page is one
 * of the heaviest renders in the application, and a full round trip to save a
 * paragraph would re-assemble every table on the page to change one field.
 */
class SummaryNarrativeController extends Controller
{
    public function update(UpdateSummaryNarrativeRequest $request, Assessment $assessment): JsonResponse
    {
        $validated = $request->validated();

        $narrative = is_string($validated['narrative'] ?? null)
            ? trim($validated['narrative'])
            : null;

        if ($narrative === '') {
            $narrative = null;
        }

        $fromDraft = (bool) ($validated['from_draft'] ?? false);

        // Assigned as properties, never mass-assigned: these three columns are
        // deliberately absent from Assessment::$fillable, so `update([...])`
        // here would drop all of them silently and report success.
        $assessment->summary_narrative = $narrative;
        $assessment->summary_narrative_generated = match (true) {
            $narrative === null => false,
            $fromDraft => true,
            default => $assessment->summary_narrative_generated,
        };

        if ($fromDraft) {
            // Accepted. The draft has become the narrative and keeping a copy
            // would leave un-reviewed text on the record with nothing pointing
            // at it.
            $assessment->summary_draft = null;
        }

        $assessment->save();

        return response()->json([
            'narrative' => $assessment->summary_narrative,
            'generated' => $assessment->summary_narrative_generated,
            'draft' => $assessment->summary_draft,
        ]);
    }

    /**
     * Throws the pending draft away.
     *
     * Separate from `update` so discarding costs one click and no typing.
     * A draft nobody wants should be as cheap to reject as to accept —
     * otherwise the easy path is to accept it and tidy it later, which is the
     * accept step decaying into a formality.
     */
    public function destroyDraft(Request $request, Assessment $assessment): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('update', $assessment)) {
            abort(403);
        }

        $assessment->summary_draft = null;
        $assessment->save();

        return response()->json(['draft' => null]);
    }
}
