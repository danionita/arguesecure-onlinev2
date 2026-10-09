<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assessment settings (W44, item 12; ui.md §8).
 *
 * Gated on `manage`, not `update`. Everything here changes what every figure
 * in the assessment *means* — the currency they are denominated in, the
 * period a capital cost is spread over, the threshold the report draws a line
 * at — so it is an ownership-level act rather than an edit, the same
 * distinction `templates.update` already makes.
 *
 * ## `title` and `description` are `prohibited` (W86, item 9)
 *
 * They live on the root node and are edited there — two editors for one
 * field is how they diverge, which is exactly what this screen and the tree
 * were doing until now. Removing the Vue inputs is not enough on its own: a
 * removed input is not a removed endpoint, and this is the guarantee that a
 * scripted request cannot resurrect the second write path by posting `title`
 * or `description` directly. `prohibited` rather than just dropping the
 * rules entirely, so the field is *rejected* (422) rather than silently
 * ignored — the same "assert the specified value" standard CLAUDE.md holds
 * every guard to.
 *
 * Before this, both were written to the root node inside
 * `AssessmentSettingsController::update()`, and that write went through the
 * ordinary `NodeObserver`/`RevisionRecorder` path — so the history page was
 * recording a title/description edit from a second place, on top of the
 * tree's own. See `RevisionRecorder::SETTING_FIELDS`'s docblock, which
 * already documented the double-write risk this closes.
 */
class UpdateAssessmentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return (bool) $this->user()?->can('manage', $assessment);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['prohibited'],
            'description' => ['prohibited'],

            // A fixed list from config, never a free string: the value
            // reaches `Intl.NumberFormat`, which throws a RangeError on an
            // unknown code and would blank every figure on the page. It is
            // also never used to build a class name or a column name
            // (security.md defect #7).
            'currency' => ['required', 'string', Rule::in(array_keys(self::currencies()))],

            'amortization_years' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('arguesecure.max_amortization_years', 10),
            ],

            // Nullable and clearable: "I have not set a tolerance" is a state
            // someone must be able to return to, and it is not the same
            // statement as a tolerance of zero.
            //
            // `min:0` rather than `gt:0` because zero is meaningful — it says
            // every euro of residual loss is worth arguing about — and a
            // negative threshold is not a statement at all.
            'risk_tolerance' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'currency.in' => 'That currency is not one this instance offers.',
            'risk_tolerance.min' => 'A risk tolerance cannot be negative.',
            'amortization_years.max' => 'Amortisation is capped at '
                .(int) config('arguesecure.max_amortization_years', 10)
                .' years — beyond that the annual figure stops being checkable.',
        ];
    }

    /** @return array<string, string> */
    public static function currencies(): array
    {
        /** @var array<string, string> $currencies */
        $currencies = config('arguesecure.currencies', ['EUR' => 'Euro']);

        return $currencies;
    }
}
