<?php

/*
 * The local inference assistant (PLAN-2.3-ai.md, W50).
 *
 * Everything here is deployment-level: whether this installation has an
 * inference runtime at all, where it is, and how long it is allowed to take.
 * Whether the feature is *switched on* is a separate, admin-controlled
 * decision living in `instance_settings` — see D25 and
 * App\Domain\Assistant\Availability, which requires both.
 *
 * `env()` is permitted here and nowhere else (CLAUDE.md: it returns null once
 * `config:cache` runs). Every value below is read through `config()`.
 *
 * ## Why the timeouts are enormous, and explicit
 *
 * D20. CPU inference runs for minutes: the spike measured 249s for a job C
 * draft and 94s for the first help answer of a session
 * (docs/spikes/2.3-inference.md). Every HTTP client in wide use defaults to
 * something between 5 and 30 seconds, and a non-streamed generation sends no
 * response headers at all until it has finished — so a default timeout does
 * not fail fast, it fails *late*, after the work is already done, and only on
 * assessments large enough to matter. That is exactly the shape of latent bug
 * the `env()` rule exists to prevent, and it destroyed half of the spike's
 * first benchmark before it was recognised.
 */
return [

    /*
     * Whether this deployment has an inference runtime at all.
     *
     * Off by default, and the overwhelmingly common case (D25): the assistant
     * needs a second container and 6 GiB of RAM for the small model, 28 GiB
     * for the large one (PLAN-2.6.md D52), and ArgueSecure must run anywhere. When this is false the assistant routes are not registered,
     * so the feature cannot be reached by URL by someone who read about it,
     * and the admin settings page does not offer a toggle for something the
     * deployment cannot do.
     */
    'enabled' => env('ASSISTANT_ENABLED', false),

    /*
     * The llama.cpp server (D17). An internal compose service name, never a
     * public hostname — nothing about the assistant is published to the host,
     * for the same reason Reverb is not (deployment.md §2.1).
     */
    'base_url' => env('ASSISTANT_BASE_URL', 'http://assistant:8080'),

    /*
     * There is no `model` key (2.6, D55). Which model runs is decided where
     * it runs — docker/assistant/assistant.sh, from ASSISTANT_MODEL or
     * ASSISTANT_MODEL_FILE — and the admin tab asks the runtime what it
     * loaded (App\Domain\Assistant\RuntimeModel) rather than trusting a label
     * here to agree with it. The presets are docker/assistant/models.conf.
     */

    /*
     * Seconds. Deliberately generous, deliberately explicit.
     *
     * `connect` is short because a runtime that is up answers a TCP connect
     * immediately; a slow connect means it is absent, and that should degrade
     * quickly rather than hang a request.
     *
     * `stream_idle` is the one that actually protects anything. Because every
     * request streams (D20), the meaningful failure is not "the answer took
     * too long" but "no token has arrived for a while", which catches a wedged
     * runtime without cutting off a job that is simply long.
     */
    'timeouts' => [
        'connect' => (int) env('ASSISTANT_CONNECT_TIMEOUT', 3),
        'stream_idle' => (int) env('ASSISTANT_STREAM_IDLE_TIMEOUT', 120),
        'total' => (int) env('ASSISTANT_TOTAL_TIMEOUT', 900),
    ],

    /*
     * How long a health probe result is trusted, in seconds.
     *
     * The probe must never sit on a page render (D25). Caching it means a
     * wedged or absent runtime costs one slow probe per interval rather than
     * one per request, and the editor still paints.
     */
    'health_ttl' => (int) env('ASSISTANT_HEALTH_TTL', 30),

    /*
     * Per-job generation budgets, in tokens.
     *
     * This is the latency dial and very nearly the only one: prompt tokens are
     * roughly ten times cheaper than output tokens on a CPU, so what a
     * generation costs is decided almost entirely here. It trades directly
     * against quality — the spike's terser model was faster and returned
     * generic filler — so lower these to make the feature quicker only in the
     * knowledge that the answers get worse.
     *
     * The jobs themselves land in W51, W52 and W54; the budgets live here so
     * one file answers "how long can the assistant take".
     */
    'max_tokens' => [
        'help' => (int) env('ASSISTANT_MAX_TOKENS_HELP', 320),
        'suggestions' => (int) env('ASSISTANT_MAX_TOKENS_SUGGESTIONS', 512),
        'summary' => (int) env('ASSISTANT_MAX_TOKENS_SUMMARY', 700),
    ],

    /*
     * Context window the runtime was started with (`-c`), in tokens.
     *
     * Held here so a prompt builder can refuse to send something that would be
     * silently truncated. Truncation is the failure mode that matters: the
     * runtime does not error, it just drops the front of the prompt and
     * answers from whatever survived — so job C would summarise half an
     * assessment and read as though it summarised all of it.
     */
    'context_tokens' => (int) env('ASSISTANT_CONTEXT_TOKENS', 12288),
];
