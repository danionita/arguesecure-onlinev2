<?php

use App\Domain\Graph\AssessmentGraphLoader;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->loader = new AssessmentGraphLoader;
});

/**
 * CLAUDE.md and schema.md §1, §4: "a whole assessment must load in four
 * queries" is a stated requirement, not a hope — asserted here by counting
 * actual queries via DB::listen rather than trusting the docblock.
 */
it('loads a whole assessment in exactly four queries', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $attack->defences()->attach($defence->id, ['position' => 0]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->loader->load($assessment);

    expect($queries)->toHaveCount(4);
});

/**
 * Regression: the loader used Node::withoutGlobalScopes(), intending to bypass
 * the STI 'type' scope. But that scope is declared on the subclasses, never on
 * Node, so the base-class query had none to strip — the call only removed
 * SoftDeletingScope, readmitting deleted nodes into the graph the calculation
 * engine consumes. A deleted risk or attack would keep contributing to every
 * total, which a security tool must never do silently.
 */
it('excludes soft-deleted nodes from the graph', function () {
    $assessment = Assessment::factory()->create();
    $liveRisk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $deletedRisk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $liveAttack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $deletedAttack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $deletedDefence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $deletedRisk->delete();
    $deletedAttack->delete();
    $deletedDefence->delete();

    $graph = $this->loader->load($assessment);

    expect($graph->risks)->toHaveKey($liveRisk->uuid)
        ->and($graph->risks)->not->toHaveKey($deletedRisk->uuid)
        ->and($graph->attacks)->toHaveKey($liveAttack->uuid)
        ->and($graph->attacks)->not->toHaveKey($deletedAttack->uuid)
        ->and($graph->defences)->not->toHaveKey($deletedDefence->uuid);
});

/**
 * Regression: the loader dropped attack_defence.rationale, so the node modal's
 * Links tab could save the reasoning behind a strength rating and never show it
 * again after a reload — silent data loss from the user's point of view, since
 * the text was in the database the whole time.
 */
it('carries an attack-defence link rationale into the graph', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $attack->defences()->attach($defence->id, [
        'position' => 0,
        'rationale' => 'Only covers the corporate VPN, not contractor laptops.',
    ]);

    $graph = $this->loader->load($assessment);

    expect($graph->attackDefenceLinks[0]['rationale'])
        ->toBe('Only covers the corporate VPN, not contractor laptops.');
});

it('maps risks, attacks, defences and both edge tables into the graph DTO', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Data breach']);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => 'MFA']);
    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $attack->defences()->attach($defence->id, ['position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.7]);

    $graph = $this->loader->load($assessment);

    expect($graph->assessmentUuid)->toBe($assessment->uuid)
        ->and($graph->risks[$risk->uuid]['title'])->toBe('Data breach')
        ->and($graph->attacks[$attack->uuid]['title'])->toBe('Phishing')
        ->and($graph->defences[$defence->uuid]['title'])->toBe('MFA')
        ->and($graph->riskAttackLinks)->toHaveCount(1)
        ->and($graph->riskAttackLinks[0]['risk_uuid'])->toBe($risk->uuid)
        ->and($graph->riskAttackLinks[0]['attack_uuid'])->toBe($attack->uuid)
        ->and($graph->attackDefenceLinks)->toHaveCount(1)
        ->and($graph->attackDefenceLinks[0]['attack_uuid'])->toBe($attack->uuid)
        ->and($graph->attackDefenceLinks[0]['defence_uuid'])->toBe($defence->uuid)
        ->and($graph->attackDefenceLinks[0]['strength_fraction'])->toBe(0.7);
});

it('carries the root title and description into the graph', function () {
    $assessment = Assessment::factory()->create();
    $assessment->rootNode->update(['title' => 'My ToA', 'description' => 'desc']);

    $graph = $this->loader->load($assessment);

    expect($graph->rootTitle)->toBe('My ToA')
        ->and($graph->rootDescription)->toBe('desc');
});

it('loads scale levels for all three configured scales, carrying kind and unit', function () {
    $assessment = Assessment::factory()->create();

    $graph = $this->loader->load($assessment);

    expect($graph->scaleLevels)->not->toBe([]);
    $kinds = collect($graph->scaleLevels)->pluck('kind')->unique()->sort()->values()->all();
    expect($kinds)->toBe(['impact', 'likelihood', 'strength']);
});

/**
 * The loader skips the risk_attack/attack_defence queries entirely when
 * there are no risks/attacks to join against (AssessmentGraphLoader guards
 * both with ->isEmpty() checks) — a brand-new assessment costs two queries
 * (nodes, scale levels), not four.
 */
it('skips the edge-table queries for an assessment with no risks or attacks yet', function () {
    $assessment = Assessment::factory()->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->loader->load($assessment);

    expect($queries)->toHaveCount(2);
});
