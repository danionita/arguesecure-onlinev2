<?php

namespace App\Http\Requests\Auth;

use App\Domain\Accounts\SelfRegistration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The self-registration form: an address and nothing else. There is deliberately no `unique` rule on the
 * email: a validation error for an existing address would tell anyone which
 * addresses have accounts.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 404, not 403: while it is off the route should not exist.
        abort_unless(SelfRegistration::enabled(), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
