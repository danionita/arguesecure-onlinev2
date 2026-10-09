<?php

namespace App\Http\Requests\Sharing;

use App\Domain\Sharing\CollaboratorService;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddCollaboratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return (bool) $this->user()?->can('manage', $assessment);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists(User::class, 'id')->where('is_active', true),
                // Never the owner (already has every ability) and never
                // an existing collaborator (use the update-role route).
                Rule::notIn([$assessment->owner_user_id]),
                Rule::unique('assessment_user', 'user_id')->where('assessment_id', $assessment->id),
            ],
            // Whitelisted enum — this is the field a privilege-escalation
            // attempt would target, so 'owner' is not, and can never be, a
            // valid value here (security.md §7, CLAUDE.md sharing rules).
            'role' => ['required', 'string', Rule::in(CollaboratorService::ROLES)],
        ];
    }
}
