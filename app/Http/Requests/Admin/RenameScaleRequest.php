<?php

namespace App\Http\Requests\Admin;

use App\Models\ScaleSet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new name for a custom scale (W126). */
class RenameScaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $set = $this->route('scaleSet');

        return [
            'name' => ['required', 'string', 'max:64', Rule::unique('scale_sets', 'name')->ignore($set instanceof ScaleSet ? $set->id : null)],
        ];
    }
}
