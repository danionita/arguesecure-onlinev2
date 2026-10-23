<?php

namespace App\Http\Requests\Admin;

use App\Domain\Scales\ScaleCalibrator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Bands for one to three kinds of a scale, from the admin Scales screen (W87,
 * D47; W126) and, through `UpdateAssessmentScalesRequest`, from Assessment
 * settings. The two post the same payload from the same component, so a
 * difference between their rules would be a difference in what a band may be
 * depending on who is editing it.
 *
 * Payload: `scales.{impact|likelihood|strength}.bands[]`. A kind that is
 * absent is left alone; a key that is not a kind is not validated into
 * `validated()` and so is never read, and a payload with no kind at all is
 * refused.
 */
class UpdateScaleBandsRequest extends FormRequest
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
        $rules = ['scales' => ['required', 'array', 'min:1']];

        foreach (ScaleCalibrator::kinds() as $kind) {
            $p = "scales.{$kind}";
            $rules[$p] = ['sometimes', 'array', 'required_array_keys:bands'];
            $rules["{$p}.bands"] = ['sometimes', 'required', 'array', 'min:1', 'max:12'];
            /*
             * Nullable and never rendered (D44). An existing band carries back
             * the code it was sent, which freezes a code across a rename; a
             * band the editor created sends null, for `LevelCodeDeriver` to
             * name from the label. The regex still constrains a carried code.
             */
            $rules["{$p}.bands.*.code"] = ['nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'];
            $rules["{$p}.bands.*.label"] = ['required', 'string', 'max:64'];
            // No `color`: there is no colour input (D45).
            $rules["{$p}.bands.*.lower_bound"] = ['required', 'numeric', 'min:0'];
            $rules["{$p}.bands.*.upper_bound"] = ['nullable', 'numeric', 'min:0'];
            $rules["{$p}.bands.*.representative"] = ['required', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sent = array_intersect_key((array) ($validator->getData()['scales'] ?? []), array_flip(ScaleCalibrator::kinds()));

                if ($validator->errors()->isEmpty() && $sent === []) {
                    $validator->errors()->add('scales', 'Send bands for at least one of impact, likelihood and strength.');
                }
            },
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>> keyed by kind
     */
    public function bands(): array
    {
        $out = [];

        foreach (ScaleCalibrator::kinds() as $kind) {
            /** @var list<array<string, mixed>>|null $bands */
            $bands = $this->validated()['scales'][$kind]['bands'] ?? null;

            if ($bands !== null) {
                $out[$kind] = $bands;
            }
        }

        return $out;
    }
}
