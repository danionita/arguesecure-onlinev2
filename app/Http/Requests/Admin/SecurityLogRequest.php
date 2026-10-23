<?php

namespace App\Http\Requests\Admin;

use App\Domain\Audit\SecurityEventExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters of the admin Security log page and its download (W128, D99).
 * The route is behind the `admin` middleware; `event` is checked against the
 * SecurityEvent enum so request input never reaches a query as anything but a
 * known name.
 */
class SecurityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'event' => ['nullable', 'string', Rule::in([...SecurityEventExport::eventNames(), ...SecurityEventExport::prefixes()])],
            'format' => [$this->routeIs('admin.security-log.download') ? 'required' : 'nullable', Rule::in(['csv', 'json'])],
        ];
    }
}
