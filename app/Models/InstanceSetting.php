<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * The single durable row backing App\Domain\Accounts\InstanceSettings
 * (schema.md's "Instance settings" section documents the table). Deliberately
 * a plain Eloquent model with no public-facing behaviour of its own — the
 * domain class is the interface the rest of the app talks to, this is just
 * its storage.
 *
 * @property int $id
 * @property string $default_currency
 * @property bool $public_assessments_allowed
 * @property bool $assistant_enabled
 * @property bool $assistant_help_enabled
 * @property bool $assistant_suggestions_enabled
 * @property bool $assistant_summary_enabled
 * @property int $session_lifetime_minutes
 * @property bool $require_two_factor
 * @property bool $sso_only
 * @property string|null $oidc_issuer
 * @property string|null $oidc_client_id
 * @property string|null $oidc_client_secret
 * @property string|null $oidc_button_label
 * @property string|null $oidc_allowed_domains
 * @property bool $oidc_trust_email
 * @property bool $registration_enabled
 * @property string|null $registration_domains
 * @property int $registration_daily_cap
 * @property string|null $mail_host
 * @property int|null $mail_port
 * @property string|null $mail_encryption
 * @property string|null $mail_username
 * @property string|null $mail_password
 * @property string|null $mail_from_address
 * @property string|null $mail_from_name
 * @property string|null $mail_tested_fingerprint
 * @property bool $mail_notify_mentions
 * @property bool $mail_notify_assignments
 * @property bool $mail_notify_summaries
 * @property bool $mail_notify_titles
 */
class InstanceSetting extends Model
{
    /**
     * The primary key is not AUTO_INCREMENT (see the migration's comment on
     * why: MariaDB rejects a CHECK constraint on one), so Eloquent must not
     * try to read a database-generated id back after insert — that would
     * overwrite the id=1 this class explicitly assigns.
     */
    public $incrementing = false;

    protected $fillable = [
        'default_currency',
        'public_assessments_allowed',
        'assistant_enabled',
        // One switch per assistant feature, each subordinate to
        // Availability and able only to subtract from it.
        'assistant_help_enabled',
        'assistant_suggestions_enabled',
        'assistant_summary_enabled',
        'session_lifetime_minutes',
        'require_two_factor',
        'sso_only',
        // OidcConfig reads these only for a field the
        // environment leaves empty.
        'oidc_issuer',
        'oidc_client_id',
        'oidc_client_secret',
        'oidc_button_label',
        'oidc_allowed_domains',
        'oidc_trust_email',
        'registration_enabled',
        'registration_domains',
        'registration_daily_cap',
        // SMTP, set on the Email tab. MailSettings reads them only for a
        // field the environment leaves empty.
        'mail_host',
        'mail_port',
        'mail_encryption',
        'mail_username',
        'mail_password',
        'mail_from_address',
        'mail_from_name',
        'mail_tested_fingerprint',
        'mail_notify_mentions',
        'mail_notify_assignments',
        'mail_notify_summaries',
        'mail_notify_titles',
    ];

    /**
     * The secret never leaves the model by accident: not in toArray(), not in
     * an Inertia prop, not in a log context.
     *
     * @var list<string>
     */
    protected $hidden = ['oidc_client_secret', 'mail_password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'public_assessments_allowed' => 'boolean',
            'assistant_enabled' => 'boolean',
            'assistant_help_enabled' => 'boolean',
            'assistant_suggestions_enabled' => 'boolean',
            'assistant_summary_enabled' => 'boolean',
            'session_lifetime_minutes' => 'integer',
            'require_two_factor' => 'boolean',
            'sso_only' => 'boolean',
            'oidc_client_secret' => 'encrypted',
            'oidc_trust_email' => 'boolean',
            'mail_password' => 'encrypted',
            'mail_port' => 'integer',
            'mail_notify_mentions' => 'boolean',
            'mail_notify_assignments' => 'boolean',
            'mail_notify_summaries' => 'boolean',
            'mail_notify_titles' => 'boolean',
        ];
    }

    /**
     * The one and only row, created with defaults on first use so a fresh
     * `migrate:fresh` doesn't need a seeder run before the admin settings
     * page works. `id` is set directly rather than through mass assignment
     * (it's deliberately absent from $fillable, like every other model's
     * primary key per security.md §7) — this is the one legitimate place
     * that assigns it, to pin the singleton row to id 1 as the migration's
     * `chk_instance_settings_singleton` constraint requires.
     */
    public static function singleton(): self
    {
        $row = self::query()->find(1);

        if ($row === null) {
            try {
                $row = new self;
                $row->id = 1;
                $row->save();
                // save() only inserts the attributes explicitly set above
                // (id, plus timestamps); every other column is left to the
                // migration's DB-level defaults, which the in-memory model
                // never sees unless it's re-read — without this, callers
                // would get null back for default_currency and friends
                // instead of 'EUR'.
                $row->refresh();
            } catch (QueryException) {
                // Another concurrent request won the race to create row 1
                // first; re-fetch rather than treating this as an error.
                $row = self::query()->findOrFail(1);
            }
        }

        return $row;
    }
}
