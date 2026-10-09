<?php

namespace App\Http\Requests\Assistant;

use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Job C's request (W54).
 *
 * `update`, for the same reason job B requires it: the only thing a draft can
 * become is text in this assessment's report, and an ability to ask for one
 * that is looser than the ability to accept it would offer a control to
 * someone who cannot use its result. A viewer sees no draft action.
 *
 * There is nothing to validate. The draft takes no parameters — its input is
 * the assessment, and the route already resolved that.
 */
class DraftSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return (bool) $this->user()?->can('update', $assessment);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
