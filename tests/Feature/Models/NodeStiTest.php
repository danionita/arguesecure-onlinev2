<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\RootNode;

/**
 * Single-table-inheritance behaviour (schema.md §3.4, §4): the four
 * subclasses each carry a `type` global scope, so querying one subclass
 * never returns rows of another. Node itself is deliberately unscoped.
 */
it('scopes Risk::all() to risk rows only, never returning an attack or a defence', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $risks = Risk::all();

    expect($risks->pluck('id'))->toContain($risk->id)
        ->and($risks->pluck('id'))->not->toContain($attack->id)
        ->and($risks->pluck('id'))->not->toContain($defence->id)
        ->and($risks->every(fn (Risk $r) => $r->type === 'risk'))->toBeTrue();
});

it('scopes Attack::all() and Defence::all() symmetrically', function () {
    $assessment = Assessment::factory()->create();
    Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    expect(Attack::all()->pluck('id')->all())->toBe([$attack->id])
        ->and(Defence::all()->pluck('id')->all())->toBe([$defence->id]);
});

it('hydrates every node through Node::withoutGlobalScopes() as its correct STI subclass', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $nodes = Node::withoutGlobalScopes()
        ->where('assessment_id', $assessment->id)
        ->get()
        ->keyBy('id');

    expect($nodes[$risk->id])->toBeInstanceOf(Risk::class)
        ->and($nodes[$attack->id])->toBeInstanceOf(Attack::class)
        ->and($nodes[$defence->id])->toBeInstanceOf(Defence::class)
        ->and($nodes[$assessment->rootNode->id])->toBeInstanceOf(RootNode::class);
});

it('sets type and a uuid automatically on create', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    expect($risk->type)->toBe('risk')
        ->and($risk->uuid)->not->toBeEmpty();
});

it('loads the assessment relation from a node', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    expect($risk->assessment->id)->toBe($assessment->id);
});

/**
 * schema.md §1: this is a DAG, not a tree. An attack may belong to several
 * risks, and a defence to several attacks — via risk_attack/attack_defence
 * edges, with no parent_id anywhere.
 */
it('lets one attack be reachable from two different risks', function () {
    $assessment = Assessment::factory()->create();
    $riskA = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $riskB = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);

    $riskA->attacks()->attach($attack->id, ['position' => 0]);
    $riskB->attacks()->attach($attack->id, ['position' => 0]);

    expect($riskA->fresh()->attacks->pluck('id')->all())->toBe([$attack->id])
        ->and($riskB->fresh()->attacks->pluck('id')->all())->toBe([$attack->id])
        ->and($attack->fresh()->risks->pluck('id')->sort()->values()->all())
        ->toBe(collect([$riskA->id, $riskB->id])->sort()->values()->all());
});

it('lets one defence be reachable from two different attacks', function () {
    $assessment = Assessment::factory()->create();
    $attackA = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $attackB = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $attackA->defences()->attach($defence->id, ['position' => 0]);
    $attackB->defences()->attach($defence->id, ['position' => 0]);

    expect($attackA->fresh()->defences->pluck('id')->all())->toBe([$defence->id])
        ->and($attackB->fresh()->defences->pluck('id')->all())->toBe([$defence->id])
        ->and($defence->fresh()->attacks->pluck('id')->sort()->values()->all())
        ->toBe(collect([$attackA->id, $attackB->id])->sort()->values()->all());
});

it('carries strength on the attack_defence pivot, independently per attack', function () {
    $assessment = Assessment::factory()->create();
    $attackA = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $attackB = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $attackA->defences()->attach($defence->id, [
        'position' => 0,
        'strength_mode' => 'quantitative',
        'strength_fraction' => 0.8,
    ]);
    $attackB->defences()->attach($defence->id, [
        'position' => 0,
        'strength_mode' => 'quantitative',
        'strength_fraction' => 0.2,
    ]);

    $pivotA = $attackA->defences()->first()->pivot;
    $pivotB = $attackB->defences()->first()->pivot;

    expect($pivotA->strength_fraction)->toBe(0.8)
        ->and($pivotB->strength_fraction)->toBe(0.2);
});

it('resolves title and description from the root node, not from a column on Assessment', function () {
    $assessment = Assessment::factory()->create();
    $assessment->rootNode->update(['title' => 'Test ToA', 'description' => 'A description']);

    expect($assessment->fresh()->title)->toBe('Test ToA')
        ->and($assessment->fresh()->description)->toBe('A description');
});
