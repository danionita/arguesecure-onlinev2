<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Node;
use App\Models\Risk;

/*
 * Node's relations are declared on the base class, but newFromBuilder() maps
 * `type` to an STI subclass, so every node loaded from the database is a Risk,
 * Attack, Defence or RootNode — never a Node. Eloquent guesses a hasMany /
 * belongsToMany key from the *runtime* class name, so those relations guessed
 * `risk_id`, `attack_id`, `defence_id` — columns that do not exist. The
 * relations were therefore unusable on exactly the objects the application
 * actually has, and failed as a hard SQL error rather than an empty result.
 *
 * Eager-loading from Node::query() happened to work, because the builder's
 * model is a plain `new Node`, which masked this everywhere the graph loader
 * and the report do their four-query loads. It broke on the ordinary thing:
 * touching $node->comments on a node you already have.
 */
it('resolves node relations by node_id from every STI subclass, not by the subclass name', function () {
    foreach ([new Node, new Risk, new Attack] as $model) {
        expect($model->comments()->getForeignKeyName())->toBe('node_id')
            ->and($model->revisions()->getForeignKeyName())->toBe('node_id')
            ->and($model->references()->getForeignKeyName())->toBe('node_id')
            ->and($model->tags()->getForeignPivotKeyName())->toBe('node_id');
    }
});

it('lazy-loads a relation on a hydrated subclass without a SQL error', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    // The hydrated instance, not the one we just built in memory.
    $loaded = Node::query()->findOrFail($risk->id);
    expect($loaded)->toBeInstanceOf(Risk::class);

    expect($loaded->comments)->toHaveCount(0)
        ->and($loaded->references)->toHaveCount(0)
        ->and($loaded->revisions)->toHaveCount(0)
        ->and($loaded->tags)->toHaveCount(0);
});
