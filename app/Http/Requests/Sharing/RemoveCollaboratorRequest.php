<?php

namespace App\Http\Requests\Sharing;

use App\Models\Assessment;
use App\Models\AssessmentUser;
use Illuminate\Foundation\Http\FormRequest;

class RemoveCollaboratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');
        /** @var AssessmentUser $collaborator */
        $collaborator = $this->route('collaborator');

        if (! $this->user()?->can('manage', $assessment)) {
            return false;
        }

        return $collaborator->assessment_id === $assessment->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
