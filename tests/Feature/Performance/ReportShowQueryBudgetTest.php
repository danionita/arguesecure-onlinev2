<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;

/**
 * W24 (performance). CLAUDE.md: "Eager-load everything... a whole assessment
 * must load in four queries" and "the assessment page, the report,
 * snapshots, the catalogue and the dashboard all load data too" — this
 * package's brief was to verify that claim against every one of those
 * pages, not just AssessmentGraphLoader itself. The tree editor already has
 * this shape of test (tests/Feature/Assessment/AssessmentShowTest.php,
 * "the show route query count does not grow with the number of nodes"),
 * added by W12. ReportController (W17) had no equivalent — this fills that
 * gap without editing ReportController.php itself, which this package does
 * not own (W24's owned paths are docker/**, Dockerfile, vite.config.ts,
 * tests/Feature/Performance/** and non-security config — not
 * app/Http/Controllers/**).
 *
 * Verified by neutering: could not be run in this sandbox (no MariaDB/Redis
 * available — see the W24 performance report), so this was not executed or
 * confirmed red-then-green by temporarily removing an eager load. It
 * mirrors the already-passing AssessmentShowTest pattern closely enough
 * that the same DB::listen technique is trusted to work, but this specific
 * file needs a first real run (locally with the stack up, or in CI) before
 * anyone relies on it.
 */
test('the report page query count does not grow with the number of nodes', function () {
    $buildAssessment = function (int $riskCount): Assessment {
        $assessment = Assessment::factory()->create();
        foreach (range(1, $riskCount) as $i) {
            $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
            $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
            $defence = Defence::factory()->create(['assessment_id' => $assessment->id, 'status' => 'implemented']);
            $risk->attacks()->attach($attack->id, ['position' => 0]);
            $attack->defences()->attach($defence->id, ['position' => 0]);
        }

        return $assessment;
    };

    $small = $buildAssessment(1);
    $large = $buildAssessment(8);

    $countFor = function (Assessment $assessment): array {
        // Warm-up request first, same reasoning as AssessmentShowTest: a
        // one-time lazy-init cost (e.g. InstanceSettings' first-row insert)
        // must not be attributed to graph size.
        $this->actingAs($assessment->owner)->get(route('assessments.report.show', $assessment));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($assessment->owner)->get(route('assessments.report.show', $assessment));

        DB::flushQueryLog();

        return $queries;
    };

    $smallQueries = $countFor($small);
    $largeQueries = $countFor($large);

    expect(count($largeQueries))->toBe(
        count($smallQueries),
        "large:\n".implode("\n", $largeQueries)."\n---\nsmall:\n".implode("\n", $smallQueries),
    );
});

/**
 * A concrete ceiling, not just "flat" — catches the case where every graph
 * size is flat but the flat number itself is absurd (e.g. a query per
 * report section instead of one for the whole page). Derived from reading
 * ReportController::show(): AssessmentGraphLoader (4) + the report's own
 * node query with `references` eager-loaded (1) + `owner` (1, loadMissing)
 * + assessment route-model-binding (1) + AssessmentPolicy's collaborator
 * check when the caller is not the owner (0 for an owner, who is asserted
 * here). Budget is set well above that reading so it will not flap on
 * a legitimate, deliberate query addition, but will still catch an N+1
 * introduced by a careless future change.
 *
 * **Raised from 12 to 13 by W47**, deliberately and after reading the query
 * log rather than by nudging until green. The added query is the sidebar's
 * unread-notification count, a shared Inertia prop and therefore one indexed
 * `select count(*) from notifications` on every authenticated request in the
 * application. It is flat — the "does not grow with the number of nodes" test
 * above still passes untouched — and the alternative, having the sidebar fetch
 * the count on mount, is the same query plus a round trip and a frame of the
 * badge showing the wrong number. `notifications_unread_index` is what keeps
 * it a seek.
 *
 * This file belongs to W24. It is edited here because a budget that cannot be
 * raised for a deliberate addition stops being a budget and starts being an
 * obstacle people route around — and this is the second time it has caught a
 * real cost (W44's lazy-loaded root title, which was removed rather than
 * budgeted for).
 */
test('the report page issues no more than 13 queries for a small assessment', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id, 'status' => 'implemented']);
    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $attack->defences()->attach($defence->id, ['position' => 0]);

    // Warm-up, same reasoning as above.
    $this->actingAs($assessment->owner)->get(route('assessments.report.show', $assessment));

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->actingAs($assessment->owner)->get(route('assessments.report.show', $assessment))->assertOk();

    expect(count($queries))->toBeLessThanOrEqual(13, implode("\n", $queries));
});
