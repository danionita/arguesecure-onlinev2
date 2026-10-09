<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Unlinking an attack from a risk in the node modal's Links tab (ui.md §7). */
class DestroyRiskAttackLinkRequest extends FormRequest
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
                ),
            ],
            'attack_uuid' => [
                'required',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query->where('assessment_id', $assessment->id)
                        ->where('type', 'attack')
                ),
            ],
        ];
    }
}
