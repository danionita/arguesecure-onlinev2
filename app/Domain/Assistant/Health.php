<?php

namespace App\Domain\Assistant;

use Illuminate\Support\Facades\Cache;

/**
 * Is the inference runtime answering?
 *
 * **Cached, and never on the render path** (PLAN-2.3-ai.md D25). An editor
 * that takes thirty seconds to paint because an inference container is wedged
 * is a worse outcome than having no assistant, so the probe result is trusted
 * for `assistant.health_ttl` seconds and a failure is cached exactly as an
 * success is. Caching only the successes would mean an absent runtime is
 * re-probed on every single request, which is the behaviour this exists to
 * prevent.
 *
 * The probe uses a short connect timeout for the same reason: a runtime that
 * is up answers a TCP connect immediately, so a slow connect means "absent",
 * and absent should degrade quickly.
 */
class Health
{
    private const CACHE_KEY = 'assistant:health';

    public function __construct(private readonly Client $client) {}

    public function isUp(): bool
    {
        $ttl = (int) config('assistant.health_ttl');

        // `flexible` is deliberately not used: a stale-while-revalidate probe
        // would put the refresh on whichever unlucky request triggers it,
        // which is the render-path cost this method exists to avoid.
        return (bool) Cache::remember(
            self::CACHE_KEY,
            $ttl,
            fn (): bool => $this->probe(),
        );
    }

    /**
     * Drops the cached result.
     *
     * For the admin switching the feature on: the probe from thirty seconds
     * ago, taken while the runtime was still loading its weights, should not
     * decide what they see next.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * `/health` returns 503 while the weights are loading and 200 once they
     * are, so a successful answer means ready, and anything else — including
     * no answer — means not up. Client::probe() owns the timeouts.
     */
    private function probe(): bool
    {
        return $this->client->probe('/health')?->successful() ?? false;
    }
}
