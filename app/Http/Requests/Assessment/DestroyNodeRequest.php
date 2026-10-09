<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;

class DestroyNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');
        /** @var Node $node */
        $node = $this->route('node');

        if (! $this->user()?->can('delete', $node)) {
            return false;
        }

        return $node->assessment_id === $assessment->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
