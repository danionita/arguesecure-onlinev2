<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use App\Models\Node;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removes a reference from a node modal's References tab (ui.md §7). No
 * body is submitted; the assessment/node/reference membership chain is
 * checked here plus, for the reference itself, in
 * ReferenceController::destroy() — {reference} binds by plain id (no uuid
 * column on node_references, schema.md §3.12) so it is not scoped by
 * route-model binding the way {node:uuid} is, the same reasoning
 * NodeRevisionController::revert() documents for its own plain-id-bound
 * {revision}.
 */
class DestroyNodeReferenceRequest extends FormRequest
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
        return [];
    }
}
