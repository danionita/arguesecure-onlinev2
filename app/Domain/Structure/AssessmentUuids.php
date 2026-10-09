<?php

namespace App\Domain\Structure;

use App\Models\Assessment;
use App\Models\Node;

/**
 * Resolves an assessment id to the uuid a broadcast channel is named after,
 * once per request rather than once per mutation (W68).
 *
 * ## The lookup this removes
 *
 * Every broadcast event carries `assessment.<uuid>` as its channel
 * (Events\Concerns\BroadcastsToAssessmentChannel, security.md §8), and each one
 * used to read it as `$node->assessment->uuid` — a lazy-loaded
 * `SELECT * FROM assessments` per event. One per node and one per edge, so a
 * catalogue bundle of forty controls issued eighty of them for a string that
 * cannot change between the first and the eightieth.
 *
 * It was invisible in the source of any single mutation, which is why it went
 * unnamed until W64 dumped a query log
 * (tests/Feature/Performance/CatalogInsertQueryBudgetTest.php).
 *
 * ## Why memoising is safe here and would not be elsewhere
 *
 * `assessments.uuid` is written once, at creation, and never updated —
 * schema.md §3.4 makes it the stable identity that survives cloning and
 * snapshot restore precisely *because* nothing rewrites it. A memo of an
 * immutable column cannot go stale within the process that filled it.
 *
 * The memo is per-container, registered `scoped()` in AppServiceProvider, not
 * static. That distinction is load-bearing for the test suite rather than for
 * production: a rolled-back test transaction hands the next test the same
 * auto-increment ids attached to different uuids, so a process-lifetime static
 * would answer the second test with the first test's uuid — a broadcast to a
 * channel nobody is listening on, and green tests either way. A scoped binding
 * dies with the application instance each test rebuilds.
 *
 * The queue worker is the other long-lived process that mutates nodes, and it
 * is covered by the same choice: Laravel's worker calls
 * `forgetScopedInstances()` between jobs, so a queued restore or recompute
 * starts with an empty memo rather than one filled by the job before it.
 */
final class AssessmentUuids
{
    /** @var array<int, string> */
    private array $memo = [];

    /**
     * The uuid of the assessment a node belongs to.
     *
     * Prefers an already-loaded relation over both the memo and a query, so a
     * caller that had the assessment in hand pays nothing at all — and seeds
     * the memo for everyone after it.
     */
    public function forNode(?Node $node): string
    {
        if ($node === null) {
            return '';
        }

        if ($node->relationLoaded('assessment') && $node->assessment !== null) {
            return $this->memo[(int) $node->assessment_id] = (string) $node->assessment->uuid;
        }

        return $this->forId($node->assessment_id);
    }

    /**
     * Returns '' for an id that resolves to nothing.
     *
     * Deliberately total rather than throwing. The previous
     * `$node->assessment->uuid` would fatal on a null relation, and the one
     * moment that can happen is a cascading delete — an observer firing for a
     * row whose assessment has already gone. An event with an empty channel
     * name broadcasts to a channel no client can have subscribed to, which is
     * the harmless outcome; a TypeError inside a delete transaction is not.
     * LinkChanged already cast its nullable lookups this way for the same
     * reason.
     */
    public function forId(?int $assessmentId): string
    {
        if ($assessmentId === null) {
            return '';
        }

        if (isset($this->memo[$assessmentId])) {
            return $this->memo[$assessmentId];
        }

        // `value()` rather than a model: this needs one column, and hydrating
        // an Assessment here is how the lazy load this class exists to remove
        // got its cost in the first place.
        return $this->memo[$assessmentId] = (string) Assessment::query()
            ->whereKey($assessmentId)
            ->value('uuid');
    }
}
