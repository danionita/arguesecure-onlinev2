<?php

namespace App\Http\Requests\Admin;

use App\Domain\Scales\ScaleSets;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new custom scale (W126): a name, and which scale to copy. */
class StoreScaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64', Rule::unique('scale_sets', 'name')],
            'from' => ['required', 'string', Rule::in(ScaleSets::selectableKeys())],
        ];
    }
}
