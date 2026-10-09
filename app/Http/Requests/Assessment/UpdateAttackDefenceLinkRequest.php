<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits an existing attack_defence edge's strength and reduction target
 * (ui.md §7: "Strength belongs to the attack-defence edge, not the defence
 * node"). Cross-field rules mirror schema.md §3.6's `chk_ad_strength_mode`
 * CHECK exactly — `strength_fraction`/`strength_level_id` must each be
 * blank when the other mode is active — so a request that would trip that
 * constraint fails validation with a clean 422 instead of a raw database
 * error. UpdateNodeRequest (W12, not owned by this package) does not do the
 * equivalent for impact/likelihood mode; flagged in the W13 report rather
 * than edited, per this package's file ownership.
 */
class UpdateAttackDefenceLinkRequest extends FormRequest
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
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return [
            'attack_uuid' => [
                'required',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query->where('assessment_id', $assessment->id)
                        ->where('type', 'attack')
                ),
            ],
            'defence_uuid' => [
                'required',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query->where('assessment_id', $assessment->id)
                        ->where('type', 'defence')
                ),
            ],
            'strength_mode' => ['sometimes', 'nullable', Rule::in(['quantitative', 'qualitative'])],
            'strength_fraction' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:1',
                Rule::prohibitedIf(fn () => $this->input('strength_mode') === 'qualitative'),
            ],
            'strength_level_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('scale_levels', 'id'),
                Rule::prohibitedIf(fn () => $this->input('strength_mode') === 'quantitative'),
            ],
            'reduction_target' => ['sometimes', 'nullable', Rule::in(['likelihood', 'impact'])],
            'rationale' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
