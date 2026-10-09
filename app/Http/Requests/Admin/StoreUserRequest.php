<?php

namespace App\Http\Requests\Admin;

use App\Domain\Accounts\PasswordPolicy;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Belt-and-suspenders alongside the 'admin' route middleware —
        // security.md §7 wants authorize() calling real authorization,
        // not a stand-in that always returns true.
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            // Admin-generated password is optional: if omitted, the
            // controller generates one and returns it once (ui.md §10).
            'password' => ['nullable', 'confirmed', PasswordPolicy::rule()],
            'is_admin' => ['required', 'boolean'],
        ];
    }
}
