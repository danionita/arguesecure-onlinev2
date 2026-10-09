<?php

namespace App\Http\Requests\Auth;

use App\Domain\Accounts\PasswordPolicy;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * First-run setup form. `authorize()` re-checks that no user exists yet —
 * the same guard the controller re-checks inside its transaction — so a
 * request that arrives after another browser tab already completed setup
 * is rejected here too, not just silently overwritten (security.md §4:
 * "Guard it with a check that the table is still empty at the moment of
 * submission, inside a transaction.").
 */
class SetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return User::query()->count() === 0;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ];
    }
}
