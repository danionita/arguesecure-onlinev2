<?php

namespace App\Http\Requests\Admin;

use App\Domain\Accounts\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
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
            // Blank means "generate one" — the admin either sets a known
            // password or gets a generated one back to hand over once.
            'password' => ['nullable', 'confirmed', PasswordPolicy::rule()],
        ];
    }
}
