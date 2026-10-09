<?php

namespace App\Http\Requests\Assistant;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Job B's request (W52).
 *
 * `update`, the same ability `StoreNodeRequest` requires — because the whole
 * purpose of a suggestion is to become a node, and an ability to *ask* that is
 * looser than the ability to *accept* would be a control offered to someone
 * who cannot use it. A viewer sees no suggest action at all.
 *
 * Every route touching an assessment goes through `AssessmentPolicy`, without
 * exception (CLAUDE.md). This one is no different for having a model behind
 * it.
 */
class SuggestChildrenRequest extends FormRequest
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
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return [
            // Scoped to this assessment, exactly as StoreNodeRequest scopes
            // its parent links: a uuid from another assessment must not
            // resolve, or the suggest endpoint becomes a way to read one
            // assessment's node titles from inside another
            // (security.md §3.3, the IDOR case).
            //
            // `defence` is absent from the type list because a defence has no
            // children in this model — asking for suggestions under one is a
            // request the schema has nowhere to put.
            'parent_uuid' => [
                'required',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query->where('assessment_id', $assessment->id)
                        ->whereIn('type', ['root', 'risk', 'attack'])
                        ->whereNull('deleted_at')
                ),
            ],
            // The latency dial, and the only one that matters much: output
            // tokens cost roughly ten times what prompt tokens do, so this is
            // very nearly the whole of what a user waits for. Capped at five
            // because a longer list is not more useful — it is the same
            // thinking, thinner.
            'count' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ];
    }
}
