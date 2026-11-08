<?php

namespace App\Http\Requests\Admin;

use App\Domain\Mail\MailTemplates;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One message kind's subject and body (also used by the preview). Placeholders
 * must be ones the kind offers; the subject is one line.
 */
class UpdateMailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()?->is_admin) {
            return false;
        }

        // An unknown kind is a 404 here as it is on delete.
        abort_unless(MailTemplates::exists((string) $this->route('kind')), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $kind = (string) $this->route('kind');

        $placeholders = fn (bool $subject) => function (string $attribute, mixed $value, \Closure $fail) use ($kind, $subject): void {
            $unknown = MailTemplates::unknownPlaceholders($kind, (string) $value, $subject);

            if ($unknown !== []) {
                $fail(($subject ? 'Not allowed in a subject (links and summaries never go there): ' : 'Unknown placeholder: ').'{{'.implode('}}, {{', $unknown).'}}.');
            }
        };

        return [
            'subject' => ['required', 'string', 'max:'.MailTemplates::SUBJECT_MAX, 'regex:/\A[^\r\n]*\z/', $placeholders(true)],
            'body' => ['required', 'string', 'max:'.MailTemplates::BODY_MAX, $placeholders(false), function (string $attribute, mixed $value, \Closure $fail) use ($kind): void {
                $missing = MailTemplates::missingRequired($kind, (string) $value);

                if ($missing !== []) {
                    $fail('The message needs {{'.implode('}}, {{', $missing).'}}, or the person cannot act on it.');
                }
            }],
        ];
    }
}
