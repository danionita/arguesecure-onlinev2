<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The node modal's Links tab (ui.md §7) linking an existing attack under an
 * existing risk. Mirrors StoreNodeRequest's pattern for validating
 * endpoint-type and same-assessment membership as clean 422s, rather than
 * letting LinkService::assertSameAssessment's RuntimeException surface as a
 * 500 — that exception remains defense in depth, not the primary check.
 */
class StoreRiskAttackLinkRequest extends FormRequest
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
            'risk_uuid' => [
                'required',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query->where('assessment_id', $assessment->id)
                        ->where('type', 'risk')
                        ->whereNull('deleted_at')
                ),
            ],
            'attack_uuid' => [
                'required',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query->where('assessment_id', $assessment->id)
                        ->where('type', 'attack')
                        ->whereNull('deleted_at')
                ),
            ],
        ];
    }
}
