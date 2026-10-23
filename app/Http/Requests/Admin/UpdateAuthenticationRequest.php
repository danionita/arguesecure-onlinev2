<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Oidc\OidcConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The admin **Authentication** tab (W133, D105-D108): the two sign-in
 * switches and the OpenID Connect provider's settings.
 *
 * The client secret is write-only. Blank keeps what is stored,
 * `clear_client_secret` removes it, and nothing here or in the controller
 * ever reads it back. A field the environment fixes is dropped by the
 * controller, so a client cannot override it by sending a value.
 */
class UpdateAuthenticationRequest extends FormRequest
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
            'require_two_factor' => ['required', 'boolean'],
            'sso_only' => ['required', 'boolean'],
            // https, or http for a provider on this machine, which is what
            // OidcClient::discovery() accepts too.
            'oidc_issuer' => ['nullable', 'string', 'max:255', 'regex:~^(https://[^\s/?#]+|http://(localhost|127\.0\.0\.1)(:\d+)?)(/\S*)?$~i'],
            'oidc_client_id' => ['nullable', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string', 'max:1000'],
            'clear_client_secret' => ['sometimes', 'boolean'],
            'oidc_button_label' => ['nullable', 'string', 'max:100'],
            'oidc_allowed_domains' => ['nullable', 'string', 'max:1000', function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (explode(',', (string) $value) as $domain) {
                    $domain = trim($domain);

                    if ($domain !== '' && ! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain)) {
                        $fail('Use domains such as example.org, separated by commas.');

                        return;
                    }
                }
            }],
            'oidc_trust_email' => ['sometimes', 'boolean'],
        ];

        // A field the environment fixes is not validated: the controller
        // ignores it, and a bad environment value must not block saving the
        // rest of the tab.
        foreach (['issuer' => 'oidc_issuer', 'client_id' => 'oidc_client_id', 'client_secret' => 'oidc_client_secret', 'button_label' => 'oidc_button_label', 'allowed_domains' => 'oidc_allowed_domains', 'trust_email' => 'oidc_trust_email'] as $field => $key) {
            if (OidcConfig::source($field) === OidcConfig::SOURCE_ENVIRONMENT) {
                unset($rules[$key]);
            }
        }

        return $rules;
    }
}
