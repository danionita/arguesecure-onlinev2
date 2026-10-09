<?php

namespace App\Observers\Concerns;

use App\Jobs\RecalculateAssessment;
use App\Models\Assessment;
use Illuminate\Support\Facades\Redis;

/**
 * calculation-engine.md §11.4 / PLAN.md Phase 6 item 4: every observer that
 * changes something the calculation depends on (a node, a risk_attack or
 * attack_defence edge — scale changes are schema.md territory this
 * package does not own a model for) bumps `assessments.calc_version` and
 * debounces one recompute, rather than each doing its own version of
 * either.
 *
 * A plain static-method class, not a trait: RecalculateAssessment (a
 * different class hierarchy — a queued job, not a model observer) also
 * needs {@see self::clearDebounce()}, and a trait can only be called
 * through a class that `use`s it, not by name.
 *
 * `calc_version` is bumped via a query-builder `increment()`, never
 * `$assessment->save()`: the latter would fire Assessment's own model
 * events for a column this package has no business writing through
 * Eloquent's normal change-tracking (and `calc_version` is deliberately
 * absent from Assessment::$fillable — schema.md, security.md §7).
 */
final class TriggersRecalculation
{
    /**
     * PLAN.md Phase 6 item 4: "debounced 250ms per assessment". The
     * `SET NX EX` below is the debounce: whichever mutation in a burst
     * arrives first claims the flag and schedules the one delayed job that
     * will eventually run for the whole burst; every mutation after it
     * that lands before the flag expires sees NX fail and dispatches
     * nothing further. The flag's own 2s TTL is a safety net, not the
     * debounce window itself — the job clears it explicitly the moment it
     * starts running (RecalculateAssessment::handle()), so the *typical*
     * window a burst can land in is bounded by the job's own 250ms delay,
     * not by this TTL.
     */
    /**
     * Assessment ids collected while a coalescing block is open, or null when
     * none is (W64).
     *
     * Static for the same reason RevisionRecorder's operation flag is: the
     * observers that call in here are resolved by Laravel independently of
     * whoever opened the block, so an instance property would be set on one
     * object and read from another — silently doing nothing.
     *
     * @var array<int, true>|null
     */
    private static ?array $coalescing = null;

    /**
     * Runs `$work` with the per-write version bump collapsed into one bump per
     * assessment at the end (W64).
     *
     * ## Why this is safe
     *
     * `calc_version` is a cache key, not a counter anybody reads the value of
     * (calculation-engine.md §11.4): what has to be true is that it *differs*
     * after a mutation, so every cached figure computed against the old graph
     * is unreachable. One increment for forty inserted nodes achieves that as
     * completely as forty do. Nothing anywhere compares two versions for
     * distance, and CalculationCache only ever uses it as part of a key.
     *
     * The bump still lands **inside** the caller's transaction, so a rolled-back
     * bulk insert rolls back its bump too — and the recompute job is dispatched
     * `afterCommit` exactly as before, so it still cannot read a graph
     * mid-flight.
     *
     * ## Why it is not the default
     *
     * A single mutation should bump immediately: deferring it would open a
     * window where a reader could serve a stale cached figure for a graph that
     * has already changed. This is opt-in around a block that is known to be
     * one logical operation — a catalogue bundle insert, and any other bulk
     * path that adopts it.
     *
     * Re-entrant: a nested call joins the outer block rather than opening a
     * second one, so wrapping a method that already wraps is harmless.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function coalesce(callable $work): mixed
    {
        if (self::$coalescing !== null) {
            return $work();
        }

        self::$coalescing = [];

        try {
            $result = $work();
        } finally {
            // Through a method rather than reading the property inline:
            // static analysis narrows `self::$coalescing` to the empty array
            // assigned three lines above, because the writes that fill it come
            // from observers it cannot see reaching this class. The boundary is
            // what keeps the type honest.
            foreach (array_keys(self::takePending()) as $assessmentId) {
                self::bumpCalcVersionAndDebounce($assessmentId);
            }
        }

        return $result;
    }

    /** @return array<int, true> */
    private static function takePending(): array
    {
        $pending = self::$coalescing ?? [];
        self::$coalescing = null;

        return $pending;
    }

    public static function bumpCalcVersionAndDebounce(int $assessmentId): void
    {
        if (self::$coalescing !== null) {
            // Recorded, not dropped. The bump happens once for this assessment
            // when the block closes — see coalesce().
            self::$coalescing[$assessmentId] = true;

            return;
        }

        Assessment::query()->where('id', $assessmentId)->increment('calc_version');

        $key = self::debounceKey($assessmentId);
        $claimed = Redis::connection()->command('set', [$key, '1', ['NX', 'EX' => 2]]);

        if ($claimed) {
            // ->afterCommit(): several callers (UndoService's restore,
            // NodeController::store()'s create-then-link) run multiple
            // mutations inside one DB transaction. Without this, a job
            // claimed mid-transaction runs immediately under the `sync`
            // queue driver and reads a graph mid-flight — e.g. a node
            // already restored but its edges not yet re-created —
            // producing exactly the inconsistent read
            // AssessmentGraphLoader's four-query model assumes never
            // happens. Deferring to the commit callback (Laravel's queue
            // layer honours this for the `sync` driver too, not only real
            // queues) guarantees the job only ever sees a fully-committed
            // state.
            RecalculateAssessment::dispatch($assessmentId)
                ->delay(now()->addMilliseconds(250))
                ->afterCommit();
        }
    }

    public static function clearDebounce(int $assessmentId): void
    {
        Redis::del(self::debounceKey($assessmentId));
    }

    private static function debounceKey(int $assessmentId): string
    {
        return "calc:pending:{$assessmentId}";
    }
}
