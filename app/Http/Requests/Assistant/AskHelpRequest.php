<?php

namespace App\Http\Requests\Assistant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AskHelpRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any signed-in, active user. There is no assessment subject here —
        // job A reads the product's own documentation and touches no
        // assessment data — so there is nothing for AssessmentPolicy to
        // authorize. The route's `auth` and `active` middleware are the
        // boundary, and `assistant` has already refused the request on a
        // deployment with no runtime.
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Bounded, because the question goes into a prompt whose remaining
            // room is finite and because a megabyte of text costs real CPU to
            // tokenise before the model refuses it. 500 characters is more
            // than any genuine question about a help page and small enough
            // that the cost of a bad one is nothing.
            'question' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
