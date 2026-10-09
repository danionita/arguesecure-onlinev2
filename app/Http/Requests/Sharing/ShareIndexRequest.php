<?php

namespace App\Http\Requests\Sharing;

use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Viewing the share dialog's contents (collaborator list, public flag,
 * token status) is itself gated on `manage` — a viewer/commenter/editor
 * has no business seeing who else has access or the share link, since
 * they cannot act on any of it (security.md §3.1 ability table).
 */
class ShareIndexRequest extends FormRequest
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
