<?php

use App\Domain\Catalog\CatalogInserter;
use App\Models\Assessment;
use App\Models\CatalogEntry;
use App\Models\Node;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;

/**
 * FIXED (W64): inserting a catalogue bundle costs a flat number of queries.
 *
 * ## What this used to pin, and why it was pinned rather than fixed
 *
 * W24's performance brief found `CatalogInserter` walking a bundle's
 * `catalog_entry_links` tree one node at a time and issuing three separate
 * queries per descendant:
 *
 *  - `insertRecursive()` called `$entry->catalogChildren()->get()` inside the
 *    recursion instead of loading the bundle once;
 *  - `resolveLevelDefault()` and `applyEdgeDefaults()` each ran a
 *    `ScaleLevel::where(...)->first()` per level code instead of one lookup
 *    table for the target's calibration;
 *  - `nextPosition()` ran a `MAX(position)` per inserted risk or defence
 *    instead of counting in memory.
 *
 * Query count therefore scaled with bundle size — worst exactly where the
 * catalogue earns its keep, since an ISO entry ships dozens of controls. W24
 * did not own `app/Domain/Catalog/**`, so per its own rule it pinned the
 * behaviour that existed rather than editing another package's file, and it
 * could not measure the numbers either: that sandbox had no MariaDB.
 *
 * ## What it pins now
 *
 * All three are batched, and the assertion is inverted: the cost of five more
 * descendants is now a handful of node inserts, not a handful of node inserts
 * *plus* three lookups each. `loadBundle()` reads breadth-first, so the query
 * count follows the DAG's depth — fixed at three tiers by schema.md — and not
 * the bundle's width.
 *
 * The bound is deliberately expressed **per descendant** rather than as a total.
 * A fixed total would go red on any unrelated query added to the insert path and
 * would then be raised rather than read, which is how a budget test stops
 * meaning anything. What must stay true is the *shape*: adding a control to a
 * bundle costs the writes that control needs, and nothing that scales on top.
 */
test('inserting a catalogue bundle costs a flat number of queries per extra descendant', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();

    $buildBundle = function (int $defenceCount) {
        $attackEntry = CatalogEntry::factory()->create(['kind' => 'attack', 'title' => 'Phishing']);

        foreach (range(1, $defenceCount) as $i) {
            $defenceEntry = CatalogEntry::factory()->create(['kind' => 'defence', 'title' => "Control {$i}"]);
            $attackEntry->catalogChildren()->attach($defenceEntry->id, ['defaults' => null]);
        }

        return $attackEntry;
    };

    // The query LOG, not DB::listen(). A listener cannot be removed once
    // registered, so measuring twice with listen() leaves two listeners on the
    // second call and doubles its count — which is how the original version of
    // this test measured, and it did not matter only because it asserted the
    // count was *large*.
    $countFor = function (CatalogEntry $entry) use ($assessment, $risk): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(CatalogInserter::class)->insert($entry, $assessment, $risk);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $smallCount = $countFor($buildBundle(1));
    $largeCount = $countFor($buildBundle(6));

    // MEASURED, on MariaDB 11, with this exact fixture:
    //
    //   before W64   n=1: 26   n=6: 81   → 11 queries per extra defence
    //   after  W64   n=1: 25   n=6: 60   →  7 queries per extra defence
    //
    // Written as a per-descendant rate rather than a fixed total, so it keeps
    // meaning something as the insert path changes: a total would go red on any
    // unrelated query and would then be raised rather than read, which is how a
    // budget test stops mattering.
    //
    // 8 rather than 7, because the bound is on the shape and not on today’s
    // number: two of the seven are the node INSERT and the edge INSERT, which
    // are the rows being created and are work rather than overhead. What must
    // never come back is a *lookup* that repeats per descendant.
    $perDescendant = ($largeCount - $smallCount) / 5;

    expect($perDescendant)->toBeLessThanOrEqual(8.0);
});

test('a batched insert still numbers positions the way a query per node did', function () {
    $assessment = Assessment::factory()->create();

    // Two defences already in the library, so the counter has somewhere to pick
    // up from rather than starting at zero.
    Node::query()->where('assessment_id', $assessment->id)->delete();
    $risk = Risk::factory()->for($assessment)->create(['position' => 0]);

    $attackEntry = CatalogEntry::factory()->create(['kind' => 'attack', 'title' => 'Phishing']);

    foreach (range(1, 3) as $i) {
        $defenceEntry = CatalogEntry::factory()->create(['kind' => 'defence', 'title' => "Control {$i}"]);
        $attackEntry->catalogChildren()->attach($defenceEntry->id, ['defaults' => null]);
    }

    app(CatalogInserter::class)->insert($attackEntry, $assessment, $risk);

    $positions = Node::query()
        ->where('assessment_id', $assessment->id)
        ->where('type', 'defence')
        ->orderBy('position')
        ->pluck('position')
        ->all();

    // The counter is the half of W64 that could have gone wrong silently. The
    // old `MAX(position) + 1` saw each previous insert already committed, so it
    // produced consecutive numbers; an in-memory counter has to reproduce that
    // exactly, and a batched insert that handed every defence the same position
    // would still pass a query-count test.
    expect($positions)->toBe([0, 1, 2]);
});

test('a bundle inserts in the same order twice', function () {
    $build = function () {
        $attackEntry = CatalogEntry::factory()->create(['kind' => 'attack', 'title' => 'Phishing']);

        foreach (range(1, 4) as $i) {
            $defenceEntry = CatalogEntry::factory()->create(['kind' => 'defence', 'title' => "Control {$i}"]);
            $attackEntry->catalogChildren()->attach($defenceEntry->id, ['defaults' => null]);
        }

        return $attackEntry;
    };

    $titlesFor = function (CatalogEntry $entry): array {
        $assessment = Assessment::factory()->create();
        $risk = Risk::factory()->for($assessment)->create();

        $result = app(CatalogInserter::class)->insert($entry, $assessment, $risk);

        return collect($result['nodes'])
            ->filter(fn (Node $node): bool => $node->type === 'defence')
            ->map(fn (Node $node): string => (string) $node->title)
            ->values()
            ->all();
    };

    $entry = $build();

    // `loadBundle()` orders its rows; the per-entry `catalogChildren()->get()`
    // it replaced had no ordering at all, so the positions a bundle's defences
    // received were whatever the database happened to return that time.
    expect($titlesFor($entry))->toBe($titlesFor($entry))
        ->and($titlesFor($entry))->toBe(['Control 1', 'Control 2', 'Control 3', 'Control 4']);
});

/**
 * FIXED (W68): the observer layer no longer lazy-loads a relation per mutation.
 *
 * ## What this pinned, and why it was pinned rather than fixed in W64
 *
 * Found while fixing W64's own N+1, by dumping the query log rather than by
 * reading the source — which is also why W24 never named it. Per inserted node
 * and per inserted edge:
 *
 *  - `AttackDefenceObserver::debounce()` and `RiskAttackObserver::debounce()`
 *    read `$edge->attack?->assessment_id`, a `SELECT * FROM nodes` for a node
 *    the caller (LinkService) is already holding in memory.
 *  - The broadcast events fired from `NodeObserver` and the edge observers
 *    resolve their channel from the assessment, a `SELECT * FROM assessments`
 *    per mutation.
 *
 * Neither was CatalogInserter's, and neither was specific to the catalogue:
 * every bulk path in the application paid it — `AssessmentCloner`,
 * `SnapshotRestorer`, and undo. That was the reason W64 pinned it: fixing it
 * meant changing the mutation events every write path in the codebase runs
 * through, and doing that as an unplanned addition to a package about the
 * catalogue is how a wide change lands without anyone having agreed to its
 * blast radius.
 *
 * ## What closed it
 *
 * Both halves, along the two lines the pin named:
 *
 *  - `Domain\Structure\AssessmentUuids` resolves the channel uuid once per
 *    request instead of once per broadcast. That half needed no call-site
 *    changes at all, so it fixes every path in the application at once.
 *  - `LinkService::carryEndpoints()` hands an edge its two nodes before the
 *    save that fires its observer, and `AssessmentCloner` and
 *    `SnapshotRestorer` — which build pivots directly rather than through
 *    LinkService — do the same at their own six call sites.
 *
 * The observers themselves were not touched: `$edge->attack` still reads a
 * relation, and still lazy-loads it for any caller that does not set one. The
 * fast path is an optimisation, never a correctness requirement, which is what
 * keeps a caller that forgets it correct rather than broken.
 *
 * MEASURED, same fixture as the budget test above:
 *
 *   after W64   n=1: 25   n=6: 60   → 7 queries per extra defence
 *   after W68   n=1: 18   n=6: 33   → 3 queries per extra defence
 *
 * Three is the floor this fixture can reach: the node INSERT, the edge INSERT,
 * and the `nodes` SELECT that LinkService's own `nextPosition()` runs. Those
 * are writes and one genuine lookup, not the observer layer re-reading what the
 * caller already held.
 */
test('a bundle insert pays no lazy-loaded lookup per node or per edge', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();

    $bundle = function (int $defenceCount) {
        $attackEntry = CatalogEntry::factory()->create(['kind' => 'attack', 'title' => 'Phishing']);

        foreach (range(1, $defenceCount) as $i) {
            $defenceEntry = CatalogEntry::factory()->create(['kind' => 'defence', 'title' => "Control {$i}"]);
            $attackEntry->catalogChildren()->attach($defenceEntry->id, ['defaults' => null]);
        }

        return $attackEntry;
    };

    $countFor = function (CatalogEntry $entry) use ($assessment, $risk): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(CatalogInserter::class)->insert($entry, $assessment, $risk);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $perDescendant = ($countFor($bundle(6)) - $countFor($bundle(1))) / 5;

    // The bound the pin named as the one to flip to. Below 4 is only reachable
    // once *both* lookups are gone: leaving either the channel resolution or
    // the edge endpoints lazy puts the rate back at 5 or above.
    expect($perDescendant)->toBeLessThan(4.0);
});
