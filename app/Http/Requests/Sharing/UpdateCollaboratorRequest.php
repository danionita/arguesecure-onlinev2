<?php

namespace App\Http\Requests\Sharing;

use App\Domain\Sharing\CollaboratorService;
use App\Models\Assessment;
use App\Models\AssessmentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCollaboratorRequest extends FormRequest
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

        // Belt-and-suspenders alongside scopeBindings() on the route
        // group (routes/web.php) and CollaboratorService::assertBelongsTo():
        // a collaborator id from a different assessment must 404/403, not
        // silently operate cross-assessment (security.md §3.3 IDOR test).
        return $collaborator->assessment_id === $assessment->id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(CollaboratorService::ROLES)],
        ];
    }
}
