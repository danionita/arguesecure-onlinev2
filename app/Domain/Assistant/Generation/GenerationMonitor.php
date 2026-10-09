<?php

namespace App\Domain\Assistant\Generation;

use App\Domain\Assistant\Client;
use App\Models\Assessment;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/**
 * What a running generation is doing, and how to tell it to stop.
 *
 * ## Why this exists at all
 *
 * CPU inference is slow enough to change what the interface owes the user. A
 * help answer takes seconds; a suggestion takes about half a minute; an
 * executive-summary draft measured **249 seconds** for 73 nodes on a machine
 * faster than most deployment hosts (docs/spikes/2.3-inference.md). A spinner
 * that says nothing for four minutes is indistinguishable from a hung request,
 * and the thing a user does about a hung request — reload, click again — is
 * exactly the thing that costs the shared CPU another four minutes.
 *
 * So two facts have to leave the generation while it is still running: **how
 * far it has got**, and **whether someone has asked it to stop**. Both live in
 * the cache rather than in the process, because the reader is always a
 * different request — a poll from the browser, on another php-fpm worker, or
 * against a queue worker in another container entirely.
 *
 * ## The token count is approximate, deliberately
 *
 * llama.cpp emits one SSE frame per token in practice, but that is an
 * implementation detail of the runtime rather than a promise, so this counts
 * *frames* and calls them tokens. The number drives a progress bar and an
 * "about a minute left" reading; it is not billed, audited or asserted against
 * the runtime's own `predicted_n`. Where the exact figure matters — the
 * benchmark — it is read from `timings`, not from here.
 *
 * ## Progress writes are throttled; the cancellation check is not
 *
 * A cache write per token would be sixty round trips a second on a fast host,
 * for a bar nobody reads more than once a second and a poll that runs every
 * two. So writes are throttled and the **cancellation check is not**: a Stop
 * that takes a second to bite is a Stop the user presses again, and a cache
 * read is nothing beside the token that just arrived, which cost a tenth of a
 * second of every core this host has.
 */
class GenerationMonitor
{
    /**
     * Scope strings, not model objects, because the two scopes are genuinely
     * different units and the callers already know which they are in.
     *
     * Job A and job B are **per user**: they run inside the request that asked
     * for them, and `SingleFlight` already makes one user's generation the
     * unit of contention. Job C is **per assessment**: it runs on a queue,
     * outlives the request that queued it, and two collaborators drafting the
     * same summary is the collision that matters — so the person who can stop
     * it is anyone who could have started it, not only whoever did.
     */
    public static function userScope(Authenticatable $user): string
    {
        return 'user:'.$user->getAuthIdentifier();
    }

    public static function assessmentScope(Assessment $assessment): string
    {
        return 'assessment:'.$assessment->id;
    }

    /**
     * Clears whatever a previous generation left behind. Call this at the
     * moment the *new* one is decided on.
     *
     * Deliberately separate from `start()`, and the separation is the whole
     * reason a queued generation can be cancelled before it begins. Job C is
     * queued in one request and starts in a worker minutes later; if `start()`
     * cleared the flag, a Stop pressed during that gap would be wiped by the
     * job it was meant to stop. So the controller resets when it queues, and
     * the job checks the flag before it starts — a cancel in the gap survives.
     *
     * Not doing this at all is the opposite bug: a cancellation that arrived
     * moments after the previous generation ended would sit in the cache and
     * kill the *next* one a second after it started, which would present as an
     * intermittently broken assistant and be very hard to attribute.
     */
    public function reset(string $scope): void
    {
        Cache::forget($this->cancelKey($scope));
        Cache::forget($this->progressKey($scope));
    }

    /** Opens a generation: publishes a zeroed progress row. */
    public function start(string $scope, int $expectedTokens): void
    {
        Cache::put($this->progressKey($scope), [
            'started_at' => time(),
            'updated_at' => time(),
            'tokens' => 0,
            'expected_tokens' => $expectedTokens,
        ], $this->ttlSeconds());
    }

    /**
     * The `$onToken` callback to hand to {@see Client}.
     *
     * Throwing from inside the callback is how the cancel actually stops work.
     * The exception unwinds out of the client's read loop, which abandons the
     * response body; the connection to llama.cpp closes, and the runtime stops
     * generating. There is no cancel endpoint on llama.cpp's side to call and
     * none is needed — dropping the connection *is* the protocol.
     */
    public function observer(string $scope): Closure
    {
        $tokens = 0;
        $lastWrite = 0.0;

        return function (string $chunk) use ($scope, &$tokens, &$lastWrite): void {
            $tokens++;

            // Checked on every token, unlike the progress write below. A Stop
            // that takes a second to bite is a Stop the user presses twice,
            // and a cache read is cheap next to generating the token that just
            // arrived — which cost between a tenth of a second and half a
            // second of every core on this host.
            if ($this->isCancelled($scope)) {
                throw new GenerationCancelled;
            }

            $now = microtime(true);

            // The write is throttled because nobody reads a progress bar sixty
            // times a second, and the poll behind it runs every two.
            if ($now - $lastWrite < 1.0) {
                return;
            }

            $lastWrite = $now;

            $this->advance($scope, $tokens);
        };
    }

    /**
     * @return array{started_at: int, updated_at: int, tokens: int, expected_tokens: int, elapsed_seconds: int}|null
     */
    public function progress(string $scope): ?array
    {
        /** @var array{started_at: int, updated_at: int, tokens: int, expected_tokens: int}|null $row */
        $row = Cache::get($this->progressKey($scope));

        if (! is_array($row)) {
            return null;
        }

        return $row + ['elapsed_seconds' => max(0, time() - (int) $row['started_at'])];
    }

    /**
     * Asks a running generation to stop.
     *
     * Deliberately does not check that one *is* running. The race is real —
     * the generation can finish between the browser deciding to cancel and the
     * request arriving — and "there was nothing to cancel" is not something to
     * put in front of a user who just pressed Stop. The flag is cleared by the
     * next `start()` regardless, so a cancellation with nothing to cancel
     * costs one short-lived cache key.
     */
    public function cancel(string $scope): void
    {
        Cache::put($this->cancelKey($scope), true, $this->ttlSeconds());
    }

    public function isCancelled(string $scope): bool
    {
        return (bool) Cache::get($this->cancelKey($scope), false);
    }

    /** Closes a generation, successful or not. Always call this in a `finally`. */
    public function finish(string $scope): void
    {
        Cache::forget($this->progressKey($scope));
        Cache::forget($this->cancelKey($scope));
    }

    private function advance(string $scope, int $tokens): void
    {
        $row = Cache::get($this->progressKey($scope));

        if (! is_array($row)) {
            // The row expired under a generation still running, which means
            // the TTL is too short for this host. Re-opening it loses the
            // original start time, so the elapsed reading would jump backwards
            // — better to show nothing than to show a lie.
            return;
        }

        $row['tokens'] = $tokens;
        $row['updated_at'] = time();

        Cache::put($this->progressKey($scope), $row, $this->ttlSeconds());
    }

    /**
     * Outlives the longest generation the client will allow, by a margin.
     *
     * Tied to the same config value `SingleFlight` uses, for the same reason:
     * raising how long a generation may run must not silently leave its
     * progress row expiring underneath it, which would show a user a finished
     * generation that is still burning their CPU.
     */
    private function ttlSeconds(): int
    {
        return max(120, (int) config('assistant.timeouts.total') + 60);
    }

    private function progressKey(string $scope): string
    {
        return "assistant:progress:{$scope}";
    }

    private function cancelKey(string $scope): string
    {
        return "assistant:cancel:{$scope}";
    }
}
