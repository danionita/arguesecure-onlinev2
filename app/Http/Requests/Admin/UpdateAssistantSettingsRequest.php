<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The admin **AI assistant** tab (2.5.1; the switches are W50's and W87's,
 * moved off Instance settings unchanged).
 *
 * `declined` — not `prohibited` — where the deployment has no inference
 * runtime (PLAN-2.3-ai.md D25). Sending `false` on such an instance is
 * harmless; what must be refused is switching a feature *on* without a
 * runtime to serve it, which would store a `true` every read path then has to
 * second-guess. `sometimes`, because the tab renders no toggles on such a
 * deployment and so submits no values, and `InstanceSettings::update()`
 * writes only the keys it is given.
 *
 * Each feature switch is subordinate to `Availability` and can only ever
 * subtract (D48).
 */
class UpdateAssistantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = config('assistant.enabled')
            ? ['sometimes', 'boolean']
            : ['sometimes', 'boolean', 'declined'];

        return [
            'assistant_enabled' => $rules,
            'assistant_help_enabled' => $rules,
            'assistant_suggestions_enabled' => $rules,
            'assistant_summary_enabled' => $rules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assistant_enabled.declined' => 'This deployment has no inference runtime, so the assistant cannot be enabled.',
            'assistant_help_enabled.declined' => 'This deployment has no inference runtime, so assistant help cannot be enabled.',
            'assistant_suggestions_enabled.declined' => 'This deployment has no inference runtime, so assistant suggestions cannot be enabled.',
            'assistant_summary_enabled.declined' => 'This deployment has no inference runtime, so assistant summaries cannot be enabled.',
        ];
    }
}
