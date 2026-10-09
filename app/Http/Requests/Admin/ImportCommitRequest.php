<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The commit step re-submits the exact CSV content the preview was built
 * from and re-parses/re-validates it server-side — the client's "this row
 * is valid" flags from the preview response are never trusted as input,
 * only used for display. `excluded_lines` lets the admin deselect specific
 * rows (e.g. a duplicate they'd rather skip) without hand-editing the CSV.
 */
class ImportCommitRequest extends FormRequest
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
            'csv_text' => ['required', 'string', 'max:1048576'],
            'excluded_lines' => ['sometimes', 'array'],
            'excluded_lines.*' => ['integer'],
        ];
    }
}
