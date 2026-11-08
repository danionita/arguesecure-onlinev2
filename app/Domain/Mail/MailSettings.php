<?php

namespace App\Domain\Mail;

use App\Models\InstanceSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Mail;

/**
 * The application's outbound email: one SMTP server per instance. Each field
 * comes from the environment, through config/arguesecure.php, when it is set
 * there, and otherwise from what an admin stored on the Email tab, so a
 * deployment that sets MAIL_* is unchanged and `.env` stays the way back in.
 * It is the same rule as OidcConfig.
 *
 * Mail is off until a test message has been delivered with the settings now
 * in force (enabled()). Every feature that sends mail asks enabled() and does
 * nothing otherwise, so an instance without a relay behaves as it always did.
 *
 * The password is stored encrypted under the app key and is never given to a
 * page or written to a log.
 */
final class MailSettings
{
    public const SOURCE_ENVIRONMENT = 'environment';

    public const SOURCE_STORED = 'stored';

    public const SOURCE_NONE = 'none';

    public const ENCRYPTIONS = ['tls', 'starttls', 'none'];

    /** Fields that can be set, mapped to their `config('arguesecure.mail.*')` key and stored column. */
    private const FIELDS = [
        'host' => ['host', 'mail_host'],
        'port' => ['port', 'mail_port'],
        'encryption' => ['encryption', 'mail_encryption'],
        'username' => ['username', 'mail_username'],
        'password' => ['password', 'mail_password'],
        'from_address' => ['from_address', 'mail_from_address'],
        'from_name' => ['from_name', 'mail_from_name'],
    ];

    /** SMTP is configured and the settings now in force have delivered a test message. */
    public static function enabled(): bool
    {
        if (! self::configured()) {
            return false;
        }

        $tested = InstanceSetting::singleton()->mail_tested_fingerprint;

        return $tested !== null && hash_equals($tested, self::fingerprint());
    }

    /** A server and a sender are set. Says nothing about whether they work. */
    public static function configured(): bool
    {
        return self::host() !== '' && self::fromAddress() !== '';
    }

    public static function host(): string
    {
        return self::string('host');
    }

    public static function port(): int
    {
        $port = (int) self::string('port');

        if ($port > 0) {
            return $port;
        }

        return self::encryption() === 'tls' ? 465 : 587;
    }

    /** @return 'tls'|'starttls'|'none' */
    public static function encryption(): string
    {
        $value = strtolower(self::string('encryption'));

        return in_array($value, self::ENCRYPTIONS, true) ? $value : 'starttls';
    }

    public static function username(): string
    {
        return self::string('username');
    }

    /** Kept exactly as typed: a space at either end can be part of a password. */
    public static function password(): string
    {
        $env = config('arguesecure.mail.password');

        if (self::filled($env)) {
            return (string) $env;
        }

        try {
            $stored = InstanceSetting::singleton()->mail_password;
        } catch (DecryptException) {
            // APP_KEY was rotated: treat it as not set so the admin can enter
            // it again, as OidcConfig does for the client secret.
            return '';
        }

        return self::filled($stored) ? (string) $stored : '';
    }

    public static function fromAddress(): string
    {
        return self::string('from_address');
    }

    public static function fromName(): string
    {
        $name = self::string('from_name');

        return $name !== '' ? $name : (string) config('app.name');
    }

    /**
     * Where a field's effective value comes from.
     *
     * @param  'host'|'port'|'encryption'|'username'|'password'|'from_address'|'from_name'  $field
     * @return 'environment'|'stored'|'none'
     */
    public static function source(string $field): string
    {
        [$configKey, $column] = self::FIELDS[$field];

        if (self::filled(config("arguesecure.mail.{$configKey}"))) {
            return self::SOURCE_ENVIRONMENT;
        }

        if ($field === 'password') {
            return self::password() !== '' ? self::SOURCE_STORED : self::SOURCE_NONE;
        }

        return self::filled(InstanceSetting::singleton()->{$column}) ? self::SOURCE_STORED : self::SOURCE_NONE;
    }

    /**
     * A hash of everything that decides where a message goes and who it
     * claims to be from. A test message vouches for exactly these settings.
     */
    public static function fingerprint(): string
    {
        // Keyed by APP_KEY: a hash of the password alone would let anyone with
        // a copy of the database test guesses against it offline.
        return hash_hmac('sha256', implode("\n", [
            self::host(),
            self::port(),
            self::encryption(),
            self::username(),
            self::password(),
            self::fromAddress(),
            self::fromName(),
        ]), (string) config('app.key'));
    }

    /**
     * Points the `smtp` mailer at these settings. Called before sending, in the
     * request or the queued job that sends, because a queue worker lives for
     * an hour and must not hold on to settings an admin has since changed.
     */
    public static function apply(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                // Implicit TLS is the `smtps` scheme.
                'scheme' => self::encryption() === 'tls' ? 'smtps' : 'smtp',
                'url' => null,
                'host' => self::host(),
                'port' => self::port(),
                'username' => self::username() !== '' ? self::username() : null,
                'password' => self::password() !== '' ? self::password() : null,
                'timeout' => 15,
                // Read by Symfony's SMTP factory from these options. `starttls`
                // refuses to send unless the server upgrades; `none` never
                // upgrades. `tls` is already encrypted by the `smtps` scheme.
                'require_tls' => self::encryption() === 'starttls',
                'auto_tls' => self::encryption() !== 'none',
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            ],
            'mail.from' => ['address' => self::fromAddress(), 'name' => self::fromName()],
        ]);

        Mail::purge('smtp');
    }

    private static function string(string $field): string
    {
        [$configKey, $column] = self::FIELDS[$field];

        $env = config("arguesecure.mail.{$configKey}");

        if (self::filled($env)) {
            return trim((string) $env);
        }

        $stored = InstanceSetting::singleton()->{$column};

        return self::filled($stored) ? trim((string) $stored) : '';
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
