<?php

use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\NodeRevision;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in(__FILE__);

/*
 * W18b — the RevisionRecorder that PLAN.md Phase 2 named but was never
 * built (schema.md §3.9). Node's own `updated` model event only carries
 * post-write dirty state via getChanges()/getOriginal() *during* that
 * event (Model::performUpdate() calls syncChanges() before firing
 * 'updated', and defers syncOriginal() to finishSave(), after) — so this
 * exercises RevisionRecorder through a real Node::save(), the same
 * integration point NodeObserver::updated() uses in production, rather
 * than hand-constructing Eloquent's internal change-tracking state.
 * tests/Feature/History/RevisionRecorderHttpTest.php covers the same
 * behaviour one layer up, through NodeController::update().
 */
test('a field edit is recorded with the old and new value', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Old title']);
    $user = User::factory()->create();

    $this->actingAs($user);
    $risk->title = 'New title';
    $risk->save();

    $revision = NodeRevision::query()->where('node_id', $risk->id)->latest('id')->first();

    expect($revision)->not->toBeNull();
    expect($revision->action)->toBe('update');
    expect($revision->user_id)->toBe($user->id);
    expect($revision->node_id)->toBe($risk->id);
    expect($revision->node_uuid)->toBe($risk->uuid);
    expect($revision->assessment_id)->toBe($assessment->id);
    expect($revision->operation_id)->toBeNull();
    expect($revision->changes)->toBe([
        'title' => ['from' => 'Old title', 'to' => 'New title'],
    ]);
});

test('several fields dirtied in one save read as one entry', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'title' => 'Old title',
        'description' => 'Old description',
    ]);
    $user = User::factory()->create();

    $this->actingAs($user);
    $risk->title = 'New title';
    $risk->description = 'New description';
    $risk->save();

    expect(NodeRevision::query()->where('node_id', $risk->id)->count())->toBe(1);

    $revision = NodeRevision::query()->where('node_id', $risk->id)->latest('id')->first();
    expect($revision->changes)->toBe([
        'title' => ['from' => 'Old title', 'to' => 'New title'],
        'description' => ['from' => 'Old description', 'to' => 'New description'],
    ]);
});

test('a no-op save (nothing actually different) records nothing', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Same title']);
    $user = User::factory()->create();

    $this->actingAs($user);
    // Re-assigning the identical value leaves the attribute clean —
    // nothing dirty at all, matching a save that touches no field.
    $risk->title = 'Same title';
    $risk->save();

    expect(NodeRevision::query()->where('node_id', $risk->id)->count())->toBe(0);
});

test('a change to a non-tracked audit column alone records nothing', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'updated_by' => null]);
    $user = User::factory()->create();

    $this->actingAs($user);
    // Simulates NodeController::update()'s unconditional `$node->updated_by
    // = $request->user()?->id;` when no business field actually changed:
    // the save is genuinely dirty (updated_by differs), so 'updated' does
    // fire, but updated_by is not fillable and therefore not tracked.
    $risk->updated_by = $user->id;
    $risk->save();

    expect(NodeRevision::query()->where('node_id', $risk->id)->count())->toBe(0);
});

test("position changes are never recorded — that is a structural/move concern, not this recorder's", function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'position' => 0]);
    $user = User::factory()->create();

    $this->actingAs($user);
    $risk->position = 5;
    $risk->save();

    expect(NodeRevision::query()->where('node_id', $risk->id)->count())->toBe(0);
});

test('recording a field edit never writes to assessment_operations', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Old title']);
    $user = User::factory()->create();

    $this->actingAs($user);
    $risk->title = 'New title';
    $risk->save();

    expect(AssessmentOperation::query()->count())->toBe(0);
});

test('an anonymous actor (no authenticated user) records a null user_id', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Old title']);

    // No actingAs() — the save happens as a guest.
    $risk->title = 'New title';
    $risk->save();

    $revision = NodeRevision::query()->where('node_id', $risk->id)->latest('id')->first();
    expect($revision)->not->toBeNull();
    expect($revision->user_id)->toBeNull();
});

test('creating a node writes no revision', function () {
    $assessment = Assessment::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user);
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Brand new']);

    expect(NodeRevision::query()->where('node_id', $risk->id)->count())->toBe(0);
});

test('deleting a node writes no revision', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $user = User::factory()->create();

    $this->actingAs($user);
    $risk->delete();

    expect(NodeRevision::query()->where('node_id', $risk->id)->count())->toBe(0);
});
