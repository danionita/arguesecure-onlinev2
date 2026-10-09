<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Suggestions for the add-a-child field (ui.md §3.3, W40).
 *
 * Gated on `update`, not `view`. The endpoint exists to feed an edit, and a
 * read-only viewer — including anyone holding a share link or reading a
 * public assessment — has nothing to do with the answer. `is_public` grants
 * read only (CLAUDE.md), and "read" here would be an inventory of every node
 * title in the assessment handed out one keystroke at a time.
 *
 * `type` is a fixed whitelist and is never used to build a class name
 * (security.md defect #7). NodeController::search() maps it through an
 * explicit match(), the same way store() does.
 *
 * Only `attack` and `defence` are offered, unlike StoreNodeRequest's three.
 * A risk belongs to exactly one assessment root and cannot be shared between
 * parents, so there is no such thing as linking an existing one — the DAG
 * only branches below the risk layer (schema.md §3.3).
 */
class SearchNodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return (bool) $this->user()?->can('update', $assessment);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['attack', 'defence'])],
            // Blank is legitimate: an empty box offers the most recently
            // touched candidates, which is what makes the field useful
            // before the user knows what they are looking for.
            'q' => ['nullable', 'string', 'max:255'],
            // The parent whose existing children are excluded from the
            // results. Optional so the endpoint is still meaningful without
            // one, and existence-checked against *this* assessment so a
            // uuid from someone else's graph cannot be probed for validity
            // (security.md §3.3).
            'parent_uuid' => [
                'nullable',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query
                        ->where('assessment_id', $this->assessmentId())
                        ->whereNull('deleted_at')
                ),
            ],
        ];
    }

    private function assessmentId(): int
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $assessment->id;
    }
}
