<?php

namespace App\Events;

use App\Domain\Calculation\CalculationResults;
use App\Events\Concerns\BroadcastsToAssessmentChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PLAN.md Phase 6 item 4 — "totals recomputed", the last of the five named
 * broadcast events. Dispatched by App\Jobs\RecalculateAssessment once it
 * has produced a fresh CalculationResults (from cache or freshly computed),
 * carrying every baseline so the client replaces `store.results.value`
 * outright rather than re-deriving it — the same reasoning as every other
 * W16 event: send the changed data, not an invitation to re-fetch.
 *
 * Not dispatched inside a DB transaction (it comes from a queued job, not
 * a request), so it does not need ShouldDispatchAfterCommit the way the
 * node/link events do.
 */
class TotalsRecomputed implements ShouldBroadcast
{
    use BroadcastsToAssessmentChannel;
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $assessmentUuid;

    /** @var array<string, mixed> */
    public array $results;

    public function __construct(string $assessmentUuid, CalculationResults $results)
    {
        $this->assessmentUuid = $assessmentUuid;
        $this->results = self::toArray($results);
    }

    public function broadcastAs(): string
    {
        return 'totals.recomputed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['results' => $this->results];
    }

    /**
     * Same generic readonly-DTO -> array conversion
     * AssessmentController::toArray() uses (outside this package's
     * ownership, so duplicated rather than reused) — get_object_vars(),
     * called from outside the DTO's own class, only ever sees the public
     * (constructor-promoted, readonly) properties those classes declare.
     */
    private static function toArray(mixed $value): mixed
    {
        if (is_object($value)) {
            return array_map([self::class, 'toArray'], get_object_vars($value));
        }

        if (is_array($value)) {
            return array_map([self::class, 'toArray'], $value);
        }

        return $value;
    }
}
