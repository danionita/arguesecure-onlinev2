<?php

namespace App\Http\Requests\History;

use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Gates GET .../undo — the "Undo Dana's deletion of 'Phishing'?" prompt
 * data shown before the user confirms. Requires the same `update` ability
 * as performing the undo itself (ui.md §3.6): a viewer must not learn what
 * the next undo would do any more than they may trigger it.
 */
class PeekUndoRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return (bool) $this->user()?->can('update', $assessment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
