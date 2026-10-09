<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Since 2.5.1 a node is created only when a person confirms the draft in the
 * node modal, so the client always sends a title; `title` stays nullable so
 * older clients and the API's existing contract keep working. `type` is a fixed three-way
 * whitelist and is never used to build a class name (security.md defect #7,
 * CLAUDE.md "No dynamic class instantiation... from request input") —
 * NodeController maps it through an explicit match(), the same pattern
 * Node::$typeMap uses for hydration.
 */
class StoreNodeRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(['risk', 'attack', 'defence'])],
            'title' => ['nullable', 'string', 'max:255'],
            // W52: accepting an assistant suggestion carries a description as
            // well as a title, and it comes through *this* endpoint rather
            // than a second write path of its own — same policy check, same
            // validation, same undo entry as a node anyone types by hand.
            // Optional, so the ordinary "add a child and start typing" flow is
            // unchanged.
            'description' => ['nullable', 'string', 'max:2000'],
            // A defence's draft carries its status and transfer flag, because
            // the modal offers both before the node exists. Ignored for other
            // types by NodeController.
            'status' => ['nullable', 'string', Rule::in(['implemented', 'todo', 'shelved'])],
            'is_transfer' => ['sometimes', 'boolean'],
            // A new attack links to an existing risk; a new defence to an
            // existing attack. A new risk needs no parent link — it belongs
            // directly under the root via assessment_id (schema.md §3.3/§3.4).
            'risk_uuid' => [
                'required_if:type,attack',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query->where('assessment_id', $assessment->id)
                        ->where('type', 'risk')
                        ->whereNull('deleted_at')
                ),
            ],
            'attack_uuid' => [
                'required_if:type,defence',
                'string',
                Rule::exists('nodes', 'uuid')->where(
                    fn ($query) => $query->where('assessment_id', $assessment->id)
                        ->where('type', 'attack')
                        ->whereNull('deleted_at')
                ),
            ],
        ];
    }
}
