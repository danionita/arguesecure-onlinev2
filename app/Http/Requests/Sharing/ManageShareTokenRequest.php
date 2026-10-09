<?php

namespace App\Http\Requests\Sharing;

use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;

/** Shared by both generate/regenerate (POST) and revoke (DELETE) — neither takes a body. */
class ManageShareTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return (bool) $this->user()?->can('manage', $assessment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
