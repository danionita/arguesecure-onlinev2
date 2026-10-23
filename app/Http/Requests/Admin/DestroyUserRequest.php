<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `transfer_to_user_id` is required whenever the target user owns at
 * least one assessment — `assessments.owner_user_id` is `NULL` only for a
 * template (W141) and otherwise `... ON DELETE CASCADE` (schema.md §3.2), so deleting an owning user without
 * reassigning ownership first would silently cascade-delete every
 * assessment they own. ui.md §10: "Deleting a user warns about their
 * owned assessments and offers transfer instead."
 */
class DestroyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');
        $ownsAssessments = $target->ownedAssessments()->exists();

        return [
            'transfer_to_user_id' => [
                $ownsAssessments ? 'required' : 'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where('is_active', true),
                Rule::notIn([$target->id]),
            ],
        ];
    }
}
