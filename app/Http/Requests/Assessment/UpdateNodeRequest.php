<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use App\Models\Node;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every PATCH carries `updated_at` (ui.md §3.5) — NodeController compares it
 * against the current row and returns 409 on mismatch rather than silently
 * clobbering a concurrent edit. Field rules are keyed off the *node's own*
 * type, not a client-supplied one, so a request can never smuggle e.g.
 * `impact_amount` onto a defence and trip the `chk_nodes_defence_cols`
 * CHECK constraint at the database layer instead of failing validation
 * cleanly (schema.md §3.4).
 */
class UpdateNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');
        /** @var Node $node */
        $node = $this->route('node');

        if (! $this->user()?->can('update', $node)) {
            return false;
        }

        // Belt-and-suspenders IDOR check, matching the pattern in
        // app/Http/Requests/Sharing/UpdateCollaboratorRequest.php
        // (security.md §3.3): a node uuid from a different assessment must
        // 403, not operate cross-assessment.
        return $node->assessment_id === $assessment->id;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var Node $node */
        $node = $this->route('node');

        $common = [
            'updated_at' => ['required', 'string'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];

        return match ($node->type) {
            'risk' => [
                ...$common,
                'impact_mode' => ['sometimes', 'nullable', Rule::in(['quantitative', 'qualitative'])],
                'impact_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'impact_level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('scale_levels', 'id')],
            ],
            'attack' => [
                ...$common,
                'likelihood_mode' => ['sometimes', 'nullable', Rule::in(['quantitative', 'qualitative'])],
                'likelihood_aro' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'likelihood_level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('scale_levels', 'id')],
            ],
            'defence' => [
                ...$common,
                'is_transfer' => ['sometimes', 'boolean'],
                'status' => ['sometimes', 'nullable', Rule::in(['implemented', 'todo', 'shelved'])],
                'capex_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'opex_annual_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            ],
            default => $common, // root: title/description only
        };
    }
}
