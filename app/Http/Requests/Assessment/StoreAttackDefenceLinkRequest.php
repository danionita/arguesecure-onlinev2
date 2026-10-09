<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Links an existing defence under an existing attack from the node modal's
 * Links tab (ui.md §7). Strength is deliberately not accepted here — it
 * starts unset (schema.md §3.6's own `NULL` = "strength not yet stated")
 * and is set afterwards via UpdateAttackDefenceLinkRequest, matching the
 * two-step "create the edge, then rate it" flow the Links tab presents.
 * `reduction_target` may be supplied to override LinkService's default
 * (impact for transfers, null otherwise) at creation time.
 */
class StoreAttackDefenceLinkRequest extends FormRequest
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
                        ->whereNull('deleted_at')
                ),
            ],
            'defence_uuid' => [
                'required',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query->where('assessment_id', $assessment->id)
                        ->where('type', 'defence')
                        ->whereNull('deleted_at')
                ),
            ],
            'reduction_target' => ['sometimes', 'nullable', Rule::in(['likelihood', 'impact'])],
        ];
    }
}
