<?php

namespace App\Http\Requests\Sharing;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Backs the "search existing users" step of the share dialog. Gated on
 * `manage`, same as the rest of the sharing surface — security.md §7
 * requires this not become a user-enumeration oracle for people who have
 * no business listing accounts, so only someone who can already manage
 * *this* assessment may search at all, and CollaboratorService::searchAddable()
 * returns only id/name/email.
 */
class SearchUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return (bool) $this->user()?->can('manage', $assessment);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A short minimum length keeps a single search from walking
            // the whole users table two characters at a time.
            'query' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }
}
