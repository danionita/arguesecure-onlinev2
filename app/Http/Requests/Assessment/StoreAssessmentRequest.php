<?php

namespace App\Http\Requests\Assessment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating an assessment needs no policy check: there is no subject to
 * authorize against yet, and any authenticated, active account may own
 * one. The `auth` and `active` middleware on the route are the boundary —
 * which is why this is the one assessment route in the application that
 * does not run AssessmentPolicy, and why that is stated here rather than
 * left to be noticed.
 *
 * The title is required because it is the root node's title, and an
 * assessment whose root has no title renders as "Untitled assessment"
 * everywhere and is unfindable in a list.
 */
class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // 255 matches nodes.title; description is TEXT, but a form
            // field with no ceiling is a denial-of-service affordance.
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
