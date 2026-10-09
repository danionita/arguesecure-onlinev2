<?php

use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\NodeRevision;
use App\Models\Risk;
use App\Models\User;

/*
 * An independent end-to-end check of the recorder, written without reference
 * to the package's own tests: drive the real HTTP route a user actually hits
 * and assert the history a user would actually see.
 */
it('records a field edit made through the real update route', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Old title']);

    $this->actingAs($assessment->owner)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'New title',
            'updated_at' => $risk->updated_at?->toIso8601String(),
        ])
        ->assertSuccessful();

    $revisions = NodeRevision::query()->where('node_id', $risk->id)->get();

    expect($revisions)->toHaveCount(1);
    expect($revisions->first()->changes)->toHaveKey('title');
});

/*
 * Per-node history and the undo stack are deliberately separate mechanisms:
 * a field revert is an ordinary edit, not an undo. If recording a field edit
 * also pushed onto assessment_operations, the next Ctrl+Z would undo a typo
 * instead of the structural change the user meant, and the undo prompt would
 * name the wrong action.
 */
it('leaves the undo stack alone when recording a field edit', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $before = AssessmentOperation::query()->where('assessment_id', $assessment->id)->count();

    $this->actingAs($assessment->owner)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'Changed',
            'updated_at' => $risk->updated_at?->toIso8601String(),
        ])
        ->assertSuccessful();

    expect(AssessmentOperation::query()->where('assessment_id', $assessment->id)->count())->toBe($before);
    expect(NodeRevision::query()->where('node_id', $risk->id)->count())->toBe(1);
});

it('writes nothing when a save changes nothing', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Same']);

    $this->actingAs($assessment->owner)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'Same',
            'updated_at' => $risk->updated_at?->toIso8601String(),
        ])
        ->assertSuccessful();

    expect(NodeRevision::query()->where('node_id', $risk->id)->count())->toBe(0);
});

/*
 * The history list is reachable by anyone who can `view`, which includes a
 * public assessment and a share-link guest. Old and new values of every
 * edited field pass through it, so it must not carry the editor's address.
 */
it('never exposes an editor email through the revision list', function () {
    $assessment = Assessment::factory()->public()->create();
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_at' => now(),
        'invited_by' => $assessment->owner_user_id,
    ]);
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'Edited by someone',
            'updated_at' => $risk->updated_at?->toIso8601String(),
        ])
        ->assertSuccessful();

    $this->get(route('assessments.nodes.revisions.index', [$assessment, $risk]))
        ->assertOk()
        ->assertDontSee($editor->email, escape: false);
});
