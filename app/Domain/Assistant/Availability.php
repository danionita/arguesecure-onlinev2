<?php

namespace App\Domain\Assistant;

use App\Domain\Accounts\InstanceSettings;

/**
 * Whether the assistant can be used, and if not, why (PLAN-2.3-ai.md D25).
 *
 * Three conditions, and every one of them must hold:
 *
 * 1. **The deployment has a runtime** — `config('assistant.enabled')`, set by
 *    whoever wrote the compose file.
 * 2. **An admin switched it on** — `instance_settings.assistant_enabled`.
 * 3. **The runtime answers** — a cached health probe.
 *
 * They are separate because they fail differently and a user should be told
 * different things. A deployment without a runtime shows *nothing at all*: no
 * disabled buttons, no empty panels, no "assistant unavailable" banner on an
 * instance that never had one. A deployment whose runtime is merely wedged
 * shows the action, disabled, with the reason — because there something is
 * wrong and somebody should fix it.
 *
 * `CLAUDE.md`, via W44's `registration_locked`: "a control that appears to do
 * something and does not is worse than no control". The distinction between
 * `NOT_CONFIGURED` and `UNREACHABLE` is that rule applied to a feature that is
 * optional by design.
 *
 * **Every call site asks this class, never one of the three conditions.** A
 * check that reads only the instance setting will offer a control that cannot
 * work; a check that reads only the config will offer one nobody enabled.
 */
class Availability
{
    /** No inference runtime in this deployment. The feature does not exist. */
    public const NOT_CONFIGURED = 'not_configured';

    /** A runtime exists, but no admin has switched the feature on. */
    public const DISABLED = 'disabled';

    /**
     * The assistant is on, but *this* feature is switched off (W87, D48).
     *
     * A separate state from `DISABLED` because it needs a different sentence:
     * "the assistant is off" is wrong and misleading on an instance where the
     * other two features are working.
     */
    public const FEATURE_DISABLED = 'feature_disabled';

    /** Switched on, but the runtime is not answering. */
    public const UNREACHABLE = 'unreachable';

    public const AVAILABLE = 'available';

    /** Answering questions from the help corpus (D25 job A). */
    public const FEATURE_HELP = 'help';

    /** Suggesting risks, attacks and defences under a node (job B). */
    public const FEATURE_SUGGESTIONS = 'suggestions';

    /** Drafting the report's executive summary (job C). */
    public const FEATURE_SUMMARY = 'summary';

    /** @var list<string> */
    public const FEATURES = [
        self::FEATURE_HELP,
        self::FEATURE_SUGGESTIONS,
        self::FEATURE_SUMMARY,
    ];

    public function __construct(private readonly Health $health) {}

    /**
     * The current state, as one of the four constants above.
     *
     * Ordered cheapest-first on purpose: a deployment with no runtime — the
     * common case — returns without touching the database or the network, so
     * the overwhelming majority of installations pay nothing for a feature
     * they do not have.
     */
    public function state(?string $feature = null): string
    {
        if (! $this->configured()) {
            return self::NOT_CONFIGURED;
        }

        if (! InstanceSettings::assistantEnabled()) {
            return self::DISABLED;
        }

        // Fourth condition, and it can only ever subtract (W87, D48). It is
        // checked *after* the two above, so no value of a feature switch can
        // make a feature work on a deployment with no runtime or on an
        // instance where the assistant is off — and *before* the health probe,
        // so a feature nobody wants costs no network call.
        if ($feature !== null && ! InstanceSettings::assistantFeatureEnabled($feature)) {
            return self::FEATURE_DISABLED;
        }

        return $this->health->isUp() ? self::AVAILABLE : self::UNREACHABLE;
    }

    /**
     * Whether the assistant — or one named feature of it — can be used now.
     *
     * **Every call site that offers one of the three features passes its
     * name.** Calling this bare answers "is the assistant usable at all",
     * which is the right question for the status endpoint and the admin
     * screen and the wrong one for a control that runs a specific job: it
     * would offer a summary button on an instance that has switched summaries
     * off.
     */
    public function isAvailable(?string $feature = null): bool
    {
        return $this->state($feature) === self::AVAILABLE;
    }

    /**
     * Whether the deployment has a runtime at all.
     *
     * Static and config-only, because the route registration in
     * `routes/web.php` needs it before any container is resolved, and because
     * this is the one condition that can be answered without I/O.
     */
    public static function configured(): bool
    {
        return (bool) config('assistant.enabled');
    }

    /**
     * What to tell the user, or null when there is nothing to say.
     *
     * `NOT_CONFIGURED` deliberately returns null: silence is the correct
     * interface for a feature this installation does not have.
     */
    public function reason(?string $feature = null): ?string
    {
        return match ($this->state($feature)) {
            self::DISABLED => 'The assistant is switched off for this instance.',
            // Deliberately different words from DISABLED: on an instance where
            // the other two features work, "the assistant is switched off" is
            // a false statement that would send someone looking in the wrong
            // place.
            self::FEATURE_DISABLED => 'This assistant feature is switched off for this instance.',
            self::UNREACHABLE => 'The assistant is switched on but its runtime is not responding.',
            default => null,
        };
    }
}
