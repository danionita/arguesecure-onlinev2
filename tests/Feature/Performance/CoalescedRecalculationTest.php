<?php

use App\Domain\Catalog\CatalogInserter;
use App\Models\Assessment;
use App\Models\CatalogEntry;
use App\Models\Risk;
use App\Observers\Concerns\TriggersRecalculation;

/**
 * Collapsing the per-write calculation-version bump into one per block (W64).
 *
 * `calc_version` is a cache key, not a counter anyone reads the value of
 * (calculation-engine.md §11.4): what has to hold is that it *differs* after a
 * mutation, so every figure cached against the old graph becomes unreachable.
 * One increment for forty inserted nodes does that as completely as forty do,
 * and forty UPDATEs to change one number is the bulk of what a bundle insert
 * used to cost.
 *
 * The risk this file exists for is the opposite of a performance one: a
 * coalescing block that swallowed the bump entirely, or that dropped it when
 * the work threw, would leave a stale figure being served for a graph that has
 * already changed — silently, and only for the assessments someone actually
 * edited in bulk.
 */
test('a bundle insert bumps the calculation version exactly once', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();

    $attackEntry = CatalogEntry::factory()->create(['kind' => 'attack', 'title' => 'Phishing']);

    foreach (range(1, 5) as $i) {
        $defenceEntry = CatalogEntry::factory()->create(['kind' => 'defence', 'title' => "Control {$i}"]);
        $attackEntry->catalogChildren()->attach($defenceEntry->id, ['defaults' => null]);
    }

    $before = (int) $assessment->fresh()->calc_version;

    app(CatalogInserter::class)->insert($attackEntry, $assessment, $risk);

    // Once, not twelve times — and, crucially, not zero. The cache key has to
    // move, or the assessment page keeps serving figures computed before any of
    // this existed.
    expect((int) $assessment->fresh()->calc_version)->toBe($before + 1);
});

test('a coalesced block still bumps when the work throws', function () {
    $assessment = Assessment::factory()->create();
    $before = (int) $assessment->fresh()->calc_version;

    try {
        TriggersRecalculation::coalesce(function () use ($assessment) {
            TriggersRecalculation::bumpCalcVersionAndDebounce($assessment->id);

            throw new RuntimeException('halfway');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    // The bump is in a `finally`, so a block that dies partway still invalidates
    // whatever it managed to write. Losing it would be the unsafe direction:
    // rows changed, cache key did not, stale figures served. When the caller
    // wraps this in a transaction — as CatalogInserter does — the rollback
    // takes the bump with the rows, which is the same guarantee reached the
    // other way.
    expect((int) $assessment->fresh()->calc_version)->toBe($before + 1);
});

test('a nested coalesce joins the outer block rather than bumping early', function () {
    $assessment = Assessment::factory()->create();
    $before = (int) $assessment->fresh()->calc_version;

    TriggersRecalculation::coalesce(function () use ($assessment, $before) {
        TriggersRecalculation::coalesce(function () use ($assessment) {
            TriggersRecalculation::bumpCalcVersionAndDebounce($assessment->id);
        });

        // Re-entrancy is what makes this safe to add to a method that another
        // wrapped method calls. If the inner block closed the outer one, the
        // writes after it would bump on their own and the collapse would
        // quietly stop working at exactly the call depth nobody tests.
        expect((int) $assessment->fresh()->calc_version)->toBe($before);
    });

    expect((int) $assessment->fresh()->calc_version)->toBe($before + 1);
});

test('two assessments touched in one block each get their own bump', function () {
    $first = Assessment::factory()->create();
    $second = Assessment::factory()->create();

    $firstBefore = (int) $first->fresh()->calc_version;
    $secondBefore = (int) $second->fresh()->calc_version;

    TriggersRecalculation::coalesce(function () use ($first, $second) {
        TriggersRecalculation::bumpCalcVersionAndDebounce($first->id);
        TriggersRecalculation::bumpCalcVersionAndDebounce($second->id);
        TriggersRecalculation::bumpCalcVersionAndDebounce($first->id);
    });

    // Keyed by assessment, not a single flag. A block that bumped only the
    // first assessment it saw would leave the second serving stale figures —
    // and a subtree clone across assessments is a real path that does this.
    expect((int) $first->fresh()->calc_version)->toBe($firstBefore + 1)
        ->and((int) $second->fresh()->calc_version)->toBe($secondBefore + 1);
});

test('a single mutation outside a block still bumps immediately', function () {
    $assessment = Assessment::factory()->create();
    $before = (int) $assessment->fresh()->calc_version;

    Risk::factory()->for($assessment)->create();

    // Coalescing is opt-in for a reason: deferring an ordinary single edit would
    // open a window where a reader serves a cached figure for a graph that has
    // already changed. Everything not inside a block keeps bumping as it did.
    expect((int) $assessment->fresh()->calc_version)->toBeGreaterThan($before);
});
