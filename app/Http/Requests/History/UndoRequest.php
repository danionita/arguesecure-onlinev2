<?php

namespace App\Http\Requests\History;

use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Gates POST .../undo and .../redo. Undo is available to any editor, not
 * only the operation's author (ui.md §3.6, PLAN.md W14) — the `update`
 * ability is per-assessment role, not per-operation authorship, so no
 * additional "am I the author" check belongs here or in UndoService.
 */
class UndoRequest extends FormRequest
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
