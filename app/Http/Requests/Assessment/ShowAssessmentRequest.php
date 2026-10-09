<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Gates the tree editor's read route (docs/specs/ui.md §3, CLAUDE.md
 * "Every route touching an assessment goes through AssessmentPolicy"). This
 * is exactly the route the legacy app's defect #4 lived in (security.md) —
 * an authenticated stranger must get 403, not a rendered tree.
 *
 * Uses Gate::forUser() rather than `$this->user()?->can(...)`: this route
 * is deliberately reachable by guests (routes/web.php), and the nullsafe
 * form would short-circuit a guest straight to `false` before
 * AssessmentPolicy::view() ever runs its is_public / share-token checks,
 * which explicitly accept a null `$user`.
 */
class ShowAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return Gate::forUser($this->user())->allows('view', $assessment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
