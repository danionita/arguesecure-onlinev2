<?php

namespace App\Domain\Identity\Oidc;

use App\Models\InstanceSetting;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * The one identity provider an instance can have (D74, D75). Each field comes
 * from the environment, through config/arguesecure.php, when it is set there,
 * and otherwise from what an admin stored on the Authentication tab (W133,
 * D106). The environment wins field by field, so a deployment that already
 * configures SSO there is unchanged and `.env` stays the way back in.
 *
 * The client secret is stored encrypted under the app key (InstanceSetting's
 * `encrypted` cast) and is never given to a page.
 */
final class OidcConfig
{
    public const SOURCE_ENVIRONMENT = 'environment';

    public const SOURCE_STORED = 'stored';

    public const SOURCE_NONE = 'none';

    public const DEFAULT_BUTTON_LABEL = 'single sign-on';

    /** Fields that can be set, mapped to their `config('arguesecure.oidc.*')` key and stored column. */
    private const FIELDS = [
        'issuer' => ['issuer', 'oidc_issuer'],
        'client_id' => ['client_id', 'oidc_client_id'],
        'client_secret' => ['client_secret', 'oidc_client_secret'],
        'button_label' => ['button_label', 'oidc_button_label'],
        'allowed_domains' => ['allowed_domains', 'oidc_allowed_domains'],
        'trust_email' => ['trust_email', 'oidc_trust_email'],
    ];

    public static function enabled(): bool
    {
        return self::issuer() !== '' && self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function issuer(): string
    {
        return rtrim(self::string('issuer'), '/');
    }

    public static function clientId(): string
    {
        return self::string('client_id');
    }

    public static function clientSecret(): string
    {
        return self::string('client_secret');
    }

    public static function buttonLabel(): string
    {
        $label = self::string('button_label');

        return $label !== '' ? $label : self::DEFAULT_BUTTON_LABEL;
    }

    public static function trustEmail(): bool
    {
        $env = config('arguesecure.oidc.trust_email');

        return $env !== null ? (bool) $env : (bool) InstanceSetting::singleton()->oidc_trust_email;
    }

    /** @return list<string> lower-case, exact domains */
    public static function allowedDomains(): array
    {
        $raw = self::raw('allowed_domains');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(fn ($d) => strtolower(trim((string) $d)), $list)));
    }

    /**
     * Where a field's effective value comes from: the environment, the
     * database, or nowhere. A boolean the environment leaves unset is "none"
     * until an admin stores `true`, so the page does not claim a default is
     * stored.
     *
     * @param  'issuer'|'client_id'|'client_secret'|'button_label'|'allowed_domains'|'trust_email'  $field
     * @return 'environment'|'stored'|'none'
     */
    public static function source(string $field): string
    {
        [$configKey, $column] = self::FIELDS[$field];
        $env = config("arguesecure.oidc.{$configKey}");

        if ($field === 'trust_email') {
            if ($env !== null) {
                return self::SOURCE_ENVIRONMENT;
            }

            return InstanceSetting::singleton()->oidc_trust_email ? self::SOURCE_STORED : self::SOURCE_NONE;
        }

        if (self::filled($env)) {
            return self::SOURCE_ENVIRONMENT;
        }

        return self::filled(self::raw($field)) ? self::SOURCE_STORED : self::SOURCE_NONE;
    }

    private static function string(string $field): string
    {
        return trim((string) self::raw($field));
    }

    private static function raw(string $field): mixed
    {
        [$configKey, $column] = self::FIELDS[$field];
        $env = config("arguesecure.oidc.{$configKey}");

        if (self::filled($env)) {
            return $env;
        }

        try {
            return InstanceSetting::singleton()->getAttribute($column);
        } catch (DecryptException) {
            // APP_KEY changed since the secret was stored. Treated as not set,
            // so sign-in and the admin tab still load and the secret can be
            // entered again; a 500 here would take the way to fix it with it.
            return null;
        }
    }

    private static function filled(mixed $value): bool
    {
        return is_array($value) ? $value !== [] : trim((string) $value) !== '';
    }
}
