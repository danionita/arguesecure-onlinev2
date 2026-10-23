<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
        return [
            // No `default_currency` (it moved to the Defaults tab, W87 D47),
            // no `default_scale_calibration` (removed in 2.5.1 — there is one
            // default set, edited on the Defaults tab) and no assistant
            // switches (their own tab and request since 2.5.1,
            // UpdateAssistantSettingsRequest). Absent rather than
            // `prohibited`: `InstanceSettings::update()` writes only the keys
            // this hands it, so an unruled key is simply never written.
            'public_assessments_allowed' => ['required', 'boolean'],
            'session_lifetime_minutes' => ['required', 'integer', 'min:5', 'max:43200'],
            // `require_two_factor` and `sso_only` moved to the Authentication
            // tab (W133, D105), which has its own request.
        ];
    }
}
