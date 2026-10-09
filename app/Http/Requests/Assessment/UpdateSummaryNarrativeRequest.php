<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing the report's executive summary (W54).
 *
 * **Deliberately not gated on the assistant.** The narrative is a field of the
 * assessment that a person may type themselves and most will; a deployment
 * with no inference runtime — which is the overwhelming majority (D25) — still
 * writes, edits and clears it. Only the *drafting* route sits behind the
 * assistant middleware.
 */
class UpdateSummaryNarrativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return (bool) $this->user()?->can('update', $assessment);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Nullable clears it. An executive summary is a few paragraphs; a
            // bound well above that still refuses a paste of the whole report.
            'narrative' => ['present', 'nullable', 'string', 'max:8000'],
            // Whether this text started as a model's draft. The client sends
            // it, which is worth being honest about: a determined user can
            // send `false` for text a model wrote and defeat the disclosure.
            // The alternative — comparing the submission against the stored
            // draft — would clear the mark for anyone who edited a word, which
            // is the far more common case and fails in the direction that
            // hides a model's involvement rather than over-declaring it.
            'from_draft' => ['sometimes', 'boolean'],
        ];
    }
}
