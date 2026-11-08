<?php

namespace App\Http\Requests\Admin;

use App\Domain\Mail\MailSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The admin **Email** tab: the SMTP server and the sender.
 *
 * The password is write-only. Blank keeps what is stored, `clear_password`
 * removes it, and nothing here or in the controller reads it back. A field the
 * environment fixes is dropped by the controller, so a client cannot override
 * it by sending a value.
 */
class UpdateMailRequest extends FormRequest
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
        $rules = [
            // A name or an IPv4 address (IPv6 literals are not accepted): no scheme, no path, no port.
            'mail_host' => ['nullable', 'string', 'max:255', 'regex:/\A(?!-)[a-z0-9_-]{1,63}(?<!-)(\.(?!-)[a-z0-9_-]{1,63}(?<!-))*\z/i'],
            'mail_port' => ['nullable', 'integer', 'between:1,65535'],
            'mail_encryption' => ['nullable', Rule::in(MailSettings::ENCRYPTIONS)],
            'mail_username' => ['nullable', 'string', 'max:255', 'regex:/\A[^\r\n]*\z/'],
            'mail_password' => ['nullable', 'string', 'max:1000'],
            'clear_password' => ['sometimes', 'boolean'],
            'mail_from_address' => ['nullable', 'string', 'max:255', 'email:rfc'],
            // No line breaks: this ends up in a message header.
            'mail_from_name' => ['nullable', 'string', 'max:100', 'regex:/\A[^\r\n]*\z/'],
        ];

        // A field the environment fixes is not validated: the controller
        // ignores it, and a bad environment value must not block saving the rest.
        foreach (['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'] as $field) {
            if (MailSettings::source($field) === MailSettings::SOURCE_ENVIRONMENT) {
                unset($rules["mail_{$field}"]);
            }
        }

        return $rules;
    }
}
