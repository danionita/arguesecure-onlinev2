<?php

namespace App\Domain\Accounts;

use App\Domain\Assistant\Availability;
use App\Domain\Identity\Oidc\OidcConfig;
use App\Models\InstanceSetting;

/**
 * Instance-wide settings the admin area exposes (ui.md §10: "default
 * currency, default scale calibration, whether public assessments are
 * allowed, session lifetime, registration lockdown").
 *
 * W8b follow-up: this used to persist through Cache::forever() because no
 * table existed and database/migrations/** was outside W8's file ownership
 * — a cache flush silently reverted the instance to defaults. It now backs
 * onto the `instance_settings` table (schema.md's "Instance settings"
 * section) via App\Models\InstanceSetting, a real singleton row. This
 * class's public interface (all()/update()) is unchanged so
 * SettingsController needs no changes beyond what W8b's other gaps
 * require.
 *
 * `registration_locked` has been removed rather than wired up.
 * CLAUDE.md is explicit: "No public registration. Accounts are
 * admin-created." There is no registration route, guarded or otherwise, in
 * this application, in any state — so a toggle that claims to lock/unlock
 * "registration" has nothing to gate. Wiring it to something else (e.g.
 * gating first-run setup or admin user-creation) would just be renaming a
 * different, already-real control to reuse this key, which is more
 * confusing than removing it. A control that appears to do something and
 * does not is worse than no control (per this package's brief) — so it is
 * gone from storage, validation and the settings page rather than kept as
 * a permanently-disabled toggle.
 *
 * `session_lifetime_minutes` now genuinely governs session lifetime — see
 * App\Http\Middleware\ApplyInstanceSessionLifetime, which reads this class
 * and sets config('session.lifetime') before the session starts on every
 * request.
 *
 * `public_assessments_allowed` is enforced server-side in
 * ShareController::updatePublic(), not only reflected in the UI.
 */
class InstanceSettings
{
    /**
     * @return array{
     *     default_currency: string,
     *     public_assessments_allowed: bool,
     *     assistant_enabled: bool,
     *     assistant_help_enabled: bool,
     *     assistant_suggestions_enabled: bool,
     *     assistant_summary_enabled: bool,
     *     session_lifetime_minutes: int,
     *     require_two_factor: bool,
     *     sso_only: bool,
     * }
     */
    public static function all(): array
    {
        $row = InstanceSetting::singleton();

        return [
            'default_currency' => $row->default_currency,
            'public_assessments_allowed' => $row->public_assessments_allowed,
            'assistant_enabled' => $row->assistant_enabled,
            'assistant_help_enabled' => $row->assistant_help_enabled,
            'assistant_suggestions_enabled' => $row->assistant_suggestions_enabled,
            'assistant_summary_enabled' => $row->assistant_summary_enabled,
            'session_lifetime_minutes' => $row->session_lifetime_minutes,
            'require_two_factor' => $row->require_two_factor,
            'sso_only' => $row->sso_only,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function update(array $values): void
    {
        $row = InstanceSetting::singleton();
        $allowedKeys = array_keys(self::defaults());

        $row->fill(array_intersect_key($values, array_flip($allowedKeys)));
        $row->save();
    }

    /**
     * Whether the instance currently permits an assessment to be made
     * public. Read directly by ShareController rather than through
     * all() so the call site reads like the rule it's enforcing.
     */
    public static function publicAssessmentsAllowed(): bool
    {
        return InstanceSetting::singleton()->public_assessments_allowed;
    }

    /**
     * D79: non-admins sign in through the provider only. Meaningless without
     * a provider, so it is false unless one is configured: an instance whose
     * provider settings were removed must not refuse every password.
     */
    public static function ssoOnly(): bool
    {
        return OidcConfig::enabled() && InstanceSetting::singleton()->sso_only;
    }

    /** D78: password sign-in sends an account with no second factor to enrolment. */
    public static function requireTwoFactor(): bool
    {
        return InstanceSetting::singleton()->require_two_factor;
    }

    /**
     * Whether an admin has switched the assistant on for this instance.
     *
     * **This is only half the answer** — it says nothing about whether a
     * runtime exists or is reachable. Nothing outside
     * App\Domain\Assistant\Availability should call this: that class requires
     * the deployment flag, this setting and a healthy runtime together, and a
     * call site that checks only one of the three will show a control that
     * cannot work.
     */
    public static function assistantEnabled(): bool
    {
        return InstanceSetting::singleton()->assistant_enabled;
    }

    /**
     * The column each of D48's three feature switches is held by.
     *
     * A fixed map looked up by exact key, not `"assistant_{$feature}_enabled"`.
     * Building a column name from a string is a legacy defect this codebase
     * forbids by name, and the fact that the interpolation would happen to be
     * safe for three known values stops being true the moment a caller stops
     * validating.
     *
     * @var array<string, string>
     */
    private const ASSISTANT_FEATURE_COLUMNS = [
        Availability::FEATURE_HELP => 'assistant_help_enabled',
        Availability::FEATURE_SUGGESTIONS => 'assistant_suggestions_enabled',
        Availability::FEATURE_SUMMARY => 'assistant_summary_enabled',
    ];

    /**
     * Whether an admin has left one of the three assistant features switched
     * on (W87, item 20, D48).
     *
     * **This is a quarter of the answer, not the answer.** Like
     * `assistantEnabled()` above it says nothing about whether a runtime
     * exists, whether the assistant is on at all, or whether the runtime is
     * responding. Nothing outside `App\Domain\Assistant\Availability` should
     * call it: that class requires the deployment flag, the instance switch,
     * *this* switch and a healthy runtime together, and a call site reading
     * only one of the four will show a control that cannot work.
     *
     * An unknown feature name is false rather than true. A typo should hide a
     * control, not silently grant one.
     */
    public static function assistantFeatureEnabled(string $feature): bool
    {
        $column = self::ASSISTANT_FEATURE_COLUMNS[$feature] ?? null;

        if ($column === null) {
            return false;
        }

        return (bool) InstanceSetting::singleton()->getAttribute($column);
    }

    /**
     * @return array{
     *     default_currency: string,
     *     public_assessments_allowed: bool,
     *     assistant_enabled: bool,
     *     assistant_help_enabled: bool,
     *     assistant_suggestions_enabled: bool,
     *     assistant_summary_enabled: bool,
     *     session_lifetime_minutes: int,
     *     require_two_factor: bool,
     *     sso_only: bool,
     * }
     */
    private static function defaults(): array
    {
        return [
            'default_currency' => 'EUR',
            'public_assessments_allowed' => true,
            // Off, on every deployment, until somebody decides otherwise
            // (PLAN-2.3-ai.md D25).
            'assistant_enabled' => false,
            // On, unlike the switch above them, and the difference is the
            // point (W87, D48). These are only ever read once an admin has
            // already switched the assistant on; defaulting them off would
            // make enabling the assistant a two-step act whose second step is
            // invisible, and an admin who turned it on and found nothing
            // working would have been given a control that appears to do
            // something and does not.
            'assistant_help_enabled' => true,
            'assistant_suggestions_enabled' => true,
            'assistant_summary_enabled' => true,
            'session_lifetime_minutes' => 480,
            // Off (W116, D78): on, it would send every account to enrolment at
            // its next sign-in with no warning.
            'require_two_factor' => false,
            'sso_only' => false,
        ];
    }
}
