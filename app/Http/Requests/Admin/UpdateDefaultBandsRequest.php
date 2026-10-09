<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One kind's bands, from the admin Defaults tab (W87, D47).
 *
 * The rules mirror `ScaleCalibrationController::update()`'s exactly, because
 * the two screens post the same payload from the same component and a
 * difference between them would be a difference in what a band is allowed to
 * be depending on who is editing it.
 */
class UpdateDefaultBandsRequest extends FormRequest
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
            'bands' => ['required', 'array', 'min:1', 'max:12'],
            /*
             * Nullable and never rendered (D44). An existing band carries back
             * the code it was sent — which is what freezes a code across a
             * rename — and a band the editor created sends null, for
             * `LevelCodeDeriver` to name from the label.
             *
             * The regex still constrains a carried code, because that is what
             * stops a hand-made request writing a code no catalogue fixture
             * could name.
             */
            'bands.*.code' => ['nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
            'bands.*.label' => ['required', 'string', 'max:64'],
            // No `color`: there is no colour input (D45), and accepting the
            // field would leave a writable surface for a value the interface
            // has decided not to have.
            'bands.*.lower_bound' => ['required', 'numeric', 'min:0'],
            'bands.*.upper_bound' => ['nullable', 'numeric', 'min:0'],
            'bands.*.representative' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bands(): array
    {
        /** @var list<array<string, mixed>> $bands */
        $bands = $this->validated()['bands'];

        return $bands;
    }
}
