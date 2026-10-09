<?php

namespace App\Http\Requests\Sharing;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ownership transfer is deliberately its own ability
 * (`AssessmentPolicy::transferOwnership`), satisfied only by the current
 * owner or an admin — never `manage` alone. This is what stops a
 * `manager` from promoting themselves to owner: they can reach every
 * other route in this controller, but not this one (security.md §3.1;
 * W9 brief, "classic privilege-escalation hole").
 */
class TransferOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return (bool) $this->user()?->can('transferOwnership', $assessment);
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
                Rule::notIn([$assessment->owner_user_id]),
                // Must already be a collaborator (OwnershipTransferService's
                // judgement call, documented on that class): validated here
                // too so a rejected transfer is a 422 the dialog can show
                // inline, not an unhandled exception.
                Rule::exists('assessment_user', 'user_id')->where('assessment_id', $assessment->id),
            ],
        ];
    }
}
