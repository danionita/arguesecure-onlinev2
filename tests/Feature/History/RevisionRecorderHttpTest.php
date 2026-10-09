<?php

use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\NodeRevision;
use App\Models\Risk;
use App\Models\User;

/*
 * W18b, end-to-end: confirms RevisionRecorder actually fires on a real
 * edit made through the HTTP route (NodeController::update()), not only
 * when exercised directly in a unit test — and that doing so never
 * touches W14's separate assessment_operations undo stack (schema.md
 * §3.10, CLAUDE.md's Job 1 non-negotiable for this package).
 */
function attachRecorderMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('a PATCH through NodeController::update() writes a node_revisions row', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Old title']);
    $editor = User::factory()->create();
    attachRecorderMember($assessment, $editor, 'editor');

    expect(NodeRevision::query()->count())->toBe(0);

    $response = $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'New title',
            'updated_at' => $risk->updated_at->toJSON(),
        ]);

    $response->assertOk();

    $revision = NodeRevision::query()->where('node_id', $risk->id)->latest('id')->first();
    expect($revision)->not->toBeNull();
    expect($revision->action)->toBe('update');
    expect($revision->user_id)->toBe($editor->id);
    expect($revision->changes)->toBe([
        'title' => ['from' => 'Old title', 'to' => 'New title'],
    ]);
    expect($revision->operation_id)->toBeNull();
});

test('recording a field edit through the HTTP route leaves the undo stack untouched', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Old title']);
    $editor = User::factory()->create();
    attachRecorderMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'New title',
            'updated_at' => $risk->updated_at->toJSON(),
        ])
        ->assertOk();

    expect(AssessmentOperation::query()->where('assessment_id', $assessment->id)->count())->toBe(0);
});

test('a PATCH that changes nothing writes no revision', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Same title']);
    $editor = User::factory()->create();
    attachRecorderMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'Same title',
            'updated_at' => $risk->updated_at->toJSON(),
        ])
        ->assertOk();

    expect(NodeRevision::query()->where('node_id', $risk->id)->count())->toBe(0);
});

test('the revision list route now returns rows written by a real edit', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Old title']);
    $editor = User::factory()->create();
    attachRecorderMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'New title',
            'updated_at' => $risk->updated_at->toJSON(),
        ])
        ->assertOk();

    $this->actingAs($editor)
        ->getJson(route('assessments.nodes.revisions.index', [$assessment, $risk]))
        ->assertOk()
        ->assertJsonCount(1, 'revisions')
        ->assertJsonPath('revisions.0.changes.title.from', 'Old title')
        ->assertJsonPath('revisions.0.changes.title.to', 'New title')
        ->assertJsonPath('revisions.0.user.id', $editor->id)
        ->assertJsonPath('revisions.0.user.name', $editor->name)
        ->assertJsonMissingPath('revisions.0.user.email');
});
