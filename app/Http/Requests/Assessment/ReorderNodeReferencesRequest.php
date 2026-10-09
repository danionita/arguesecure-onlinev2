<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use App\Models\Node;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Re-sequences a node's references (schema.md §3.12's `position` column)
 * from the node modal's References tab. `order` must be the *complete* set
 * of the node's own reference ids — Rule::exists scopes every id to
 * `node_id = $node->id`, so an id belonging to another node (or another
 * assessment entirely) 422s instead of being silently reassigned.
 */
class ReorderNodeReferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');
        /** @var Node $node */
        $node = $this->route('node');

        if (! $this->user()?->can('update', $node)) {
            return false;
        }

        return $node->assessment_id === $assessment->id;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var Node $node */
        $node = $this->route('node');

        return [
            'order' => ['required', 'array', 'min:1'],
            'order.*' => [
                'integer',
                Rule::exists('node_references', 'id')->where(
                    fn ($query) => $query->where('node_id', $node->id)
                ),
            ],
        ];
    }
}
