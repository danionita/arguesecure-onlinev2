<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Assessment\UpdateAssessmentSettingsRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The default currency a new assessment starts with (W87, D47).
 *
 * Validated against the fixed list in `config/arguesecure.php` — the same list
 * the assessment's own currency is validated against, read through the same
 * method rather than copied. The value reaches `Intl.NumberFormat`, which
 * throws on an unknown code and would blank every figure on the page, so a
 * free string here would be a way to break every report on the instance.
 *
 * Instance settings no longer validates `default_currency` at all (W87, D47
 * — see `UpdateSettingsRequest`, which carries no rule for the key any
 * more). The free-text input that used to live on that form was removed
 * along with it; the control lives only on the Defaults tab now, validated
 * here, and this request is the sole writer of the key.
 */
class UpdateDefaultCurrencyRequest extends FormRequest
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
            'default_currency' => ['required', 'string', Rule::in(array_keys(UpdateAssessmentSettingsRequest::currencies()))],
        ];
    }

    public function currency(): string
    {
        /** @var string $currency */
        $currency = $this->validated()['default_currency'];

        return $currency;
    }
}
