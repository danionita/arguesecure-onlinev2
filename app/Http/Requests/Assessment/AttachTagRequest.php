<?php

namespace App\Http\Requests\Assessment;

use App\Domain\Tags\TagService;
use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Labelling a node (W43, ui.md §7).
 *
 * Gated on `update`, like every other node field. A label is an edit to the
 * assessment, not a personal bookmark — everyone working on it sees the same
 * ones — so a read-only viewer, including anyone holding a share link or
 * reading a public assessment, cannot add one. `is_public` grants read only
 * (CLAUDE.md).
 */
class AttachTagRequest extends FormRequest
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
            // By name, not by id: naming a label that does not exist yet is
            // how labels are created (TagService), so there is no id to send
            // the first time anyone uses one.
            //
            // `regex:/\S/` because `required` accepts "   ": that trims to
            // an empty name and an empty slug, and the slug is uniquely
            // indexed per assessment — so the first all-whitespace label
            // would succeed and the second would collide with it, on a value
            // no one can see or type on purpose.
            'name' => ['required', 'string', 'regex:/\S/', 'max:'.TagService::MAX_NAME_LENGTH],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.max' => 'A label can be at most '.TagService::MAX_NAME_LENGTH.' characters.',
            'name.regex' => 'A label needs a name.',
        ];
    }
}
