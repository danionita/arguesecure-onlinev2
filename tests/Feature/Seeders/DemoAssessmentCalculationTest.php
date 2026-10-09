<?php

use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\User;
use Database\Seeders\SampleContentSeeder;

/*
 * W22's "the demo assessment, once seeded, computes: assert RiskCalculator
 * returns a complete result with no missing-value warnings, since 'fully
 * populated' is the entire point of it" requirement.
 */

test('the seeded demo assessment computes a complete result on all three baselines', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $root = Node::withoutGlobalScopes()->where('uuid', 'a1000000-0000-4000-8000-000000000001')->firstOrFail();
    $assessment = Assessment::findOrFail($root->assessment_id);

    $graph = (new AssessmentGraphLoader)->load($assessment);
    $results = (new RiskCalculator)->compute($graph);

    foreach ([$results->inherent, $results->current, $results->target] as $result) {
        expect($result->completeness->isPartial)->toBeFalse(
            "{$result->baseline} baseline is partial: {$result->completeness->pairsValued}/{$result->completeness->pairsTotal} pairs valued"
        );
        expect($result->completeness->coverage)->toBe(1.0);
        expect($result->completeness->pairsTotal)->toBeGreaterThan(0);
    }

    // Sanity-check plausibility (CLAUDE.md: impact is loss-per-occurrence,
    // likelihood a rate-per-year — getting these backwards would make the
    // totals implausible by orders of magnitude). Controls should reduce
    // the total monotonically: inherent > current >= target is not
    // guaranteed in general (adding a todo control never increases the
    // total, but target only ever adds to current's defence set), so
    // target <= current <= inherent always holds here.
    expect($results->inherent->total)->toBeGreaterThan($results->current->total)
        ->and($results->current->total)->toBeGreaterThanOrEqual($results->target->total)
        ->and($results->target->total)->toBeGreaterThan(0);

    // Loose plausibility band around the hand-computed figures for this
    // fixture (inherent ~289,869; current ~92,621; target ~48,062) — wide
    // tolerance so the test survives minor future edits to the fixture's
    // numbers without becoming a brittle golden vector.
    expect($results->inherent->total)->toBeGreaterThan(150000)->toBeLessThan(500000);
    expect($results->current->total)->toBeGreaterThan(30000)->toBeLessThan(200000);
    expect($results->target->total)->toBeGreaterThan(15000)->toBeLessThan(150000);
});

test('the seeded demo assessment has the shared-attack DAG case reachable from the graph', function () {
    User::factory()->admin()->create();
    (new SampleContentSeeder)->run();

    $root = Node::withoutGlobalScopes()->where('uuid', 'a1000000-0000-4000-8000-000000000001')->firstOrFail();
    $assessment = Assessment::findOrFail($root->assessment_id);

    $graph = (new AssessmentGraphLoader)->load($assessment);
    $results = (new RiskCalculator)->compute($graph);

    $sharedAttackWarning = collect($results->current->warnings)->first(fn ($w) => $w->code === 'shared_attack');

    expect($sharedAttackWarning)->not->toBeNull();
});
