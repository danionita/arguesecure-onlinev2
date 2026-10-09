<?php

namespace App\Domain\Assistant\Generation;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/**
 * One generation per user at a time (PLAN-2.3-ai.md, W51).
 *
 * ## Why a rate limit is not enough on its own
 *
 * `throttle:assistant` counts requests per minute. It cannot see *concurrency*
 * — six requests inside one second are six requests, well under any per-minute
 * ceiling, and on a CPU that is six generations competing for the same cores.
 * Each then runs several times slower than one would, so the limiter's own
 * accounting stays comfortable while the machine becomes unusable for
 * everybody, including the users who are not asking the assistant anything.
 *
 * That is the shape of the denial-of-service surface the plan calls out:
 * inference is a shared, easily exhausted resource, and on a shared instance
 * one person can exhaust it for everyone. The rate limit and this lock guard
 * different halves of it and neither replaces the other.
 *
 * ## Why per user rather than global
 *
 * A global lock would let one person's long summary block everyone else's
 * quick help question, which converts a resource problem into a fairness
 * problem. Per user, the cost of asking twice at once falls on the person who
 * did it.
 *
 * A server with very little headroom wants a global cap as well. That is a
 * deployment concern — llama.cpp's own `-np` parallel-slot setting expresses
 * it directly and is the right place for it, rather than a second lock here
 * pretending to know how much machine there is.
 */
class SingleFlight
{
    /**
     * Runs $work while holding the caller's slot, or returns null if they
     * already have a generation running.
     *
     * Null rather than an exception: "you are already asking something" is an
     * ordinary answer to give a user, not a failure, and the caller turns it
     * into a 429 with a sentence they can act on.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T|null
     */
    public function run(Authenticatable $user, callable $work): mixed
    {
        $lock = Cache::lock($this->key($user), $this->ttlSeconds());

        // Non-blocking on purpose. Waiting would queue a second request behind
        // a generation that may run for four minutes, holding a php-fpm worker
        // the whole time — which is the resource exhaustion this class exists
        // to prevent, arriving through the front door.
        if (! $lock->get()) {
            return null;
        }

        try {
            return $work();
        } finally {
            // Released even when the generation throws or the runtime times
            // out. Without this a single failure locks the user out of the
            // assistant until the TTL expires, and the TTL is deliberately
            // long because generations are.
            $lock->release();
        }
    }

    public function isRunningFor(Authenticatable $user): bool
    {
        $lock = Cache::lock($this->key($user), $this->ttlSeconds());

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * The lock's own expiry, as a backstop for a process that dies without
     * reaching its `finally` — a fatal error, or a worker killed mid-request.
     *
     * Tied to the total generation timeout rather than a number of its own, so
     * raising how long a generation may take cannot silently leave the lock
     * expiring underneath it and readmit a second request.
     */
    private function ttlSeconds(): int
    {
        return max(60, (int) config('assistant.timeouts.total') + 30);
    }

    private function key(Authenticatable $user): string
    {
        return 'assistant:generating:'.$user->getAuthIdentifier();
    }
}
