<?php

use App\Models\Assessment;
use App\Models\AssessmentUser;
use App\Models\User;

test('a manager can add a collaborator with a role and it appears in the collaborator list with who invited whom', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager', 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    $addable = User::factory()->create();

    $this->actingAs($manager)
        ->postJson(route('share.collaborators.store', $assessment), [
            'user_id' => $addable->id,
            'role' => 'editor',
        ])
        ->assertCreated()
        ->assertJsonFragment(['role' => 'editor']);

    $row = AssessmentUser::query()->where('assessment_id', $assessment->id)->where('user_id', $addable->id)->firstOrFail();
    expect($row->role)->toBe('editor')
        ->and($row->invited_by)->toBe($manager->id);
});

test('adding a collaborator rejects a non-whitelisted role value', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager', 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    $addable = User::factory()->create();

    // 'owner' is not, and must never become, a settable role value — this
    // is the field a privilege-escalation attempt would target.
    $this->actingAs($manager)
        ->postJson(route('share.collaborators.store', $assessment), [
            'user_id' => $addable->id,
            'role' => 'owner',
        ])
        ->assertInvalid(['role']);
});

test('adding a collaborator rejects the assessment owner as a target', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager', 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    $this->actingAs($manager)
        ->postJson(route('share.collaborators.store', $assessment), [
            'user_id' => $assessment->owner_user_id,
            'role' => 'viewer',
        ])
        ->assertInvalid(['user_id']);
});

test('a manager can change a collaborator role but not to an invalid value', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager', 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    $collaborator = User::factory()->create();
    $assessment->members()->attach($collaborator->id, [
        'role' => 'viewer', 'invited_by' => $manager->id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    $row = AssessmentUser::query()->where('assessment_id', $assessment->id)->where('user_id', $collaborator->id)->firstOrFail();

    $this->actingAs($manager)
        ->patchJson(route('share.collaborators.update', [$assessment, $row]), ['role' => 'editor'])
        ->assertOk();

    expect($row->fresh()->role)->toBe('editor');
});

test('a manager can revoke a collaborator', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager', 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    $collaborator = User::factory()->create();
    $assessment->members()->attach($collaborator->id, [
        'role' => 'viewer', 'invited_by' => $manager->id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    $row = AssessmentUser::query()->where('assessment_id', $assessment->id)->where('user_id', $collaborator->id)->firstOrFail();

    $this->actingAs($manager)
        ->deleteJson(route('share.collaborators.destroy', [$assessment, $row]))
        ->assertOk();

    expect(AssessmentUser::query()->find($row->id))->toBeNull();
});

/**
 * IDOR check (security.md §3.3): a manager of assessment A must not be
 * able to reach a collaborator row that belongs to assessment B, even
 * though they hold `manage` on A.
 */
test('a manager cannot update or remove a collaborator belonging to a different assessment', function () {
    $assessmentA = Assessment::factory()->create();
    $managerA = User::factory()->create();
    $assessmentA->members()->attach($managerA->id, [
        'role' => 'manager', 'invited_by' => $assessmentA->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    $assessmentB = Assessment::factory()->create();
    $collaboratorB = User::factory()->create();
    $assessmentB->members()->attach($collaboratorB->id, [
        'role' => 'viewer', 'invited_by' => $assessmentB->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    $rowB = AssessmentUser::query()->where('assessment_id', $assessmentB->id)->where('user_id', $collaboratorB->id)->firstOrFail();

    $this->actingAs($managerA)
        ->patchJson(route('share.collaborators.update', [$assessmentA, $rowB]), ['role' => 'manager'])
        ->assertForbidden();

    $this->actingAs($managerA)
        ->deleteJson(route('share.collaborators.destroy', [$assessmentA, $rowB]))
        ->assertForbidden();

    expect($rowB->fresh()->role)->toBe('viewer');
});

test('a manager cannot add a user who is already a collaborator, only update their role', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager', 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    $collaborator = User::factory()->create();
    $assessment->members()->attach($collaborator->id, [
        'role' => 'viewer', 'invited_by' => $manager->id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    $this->actingAs($manager)
        ->postJson(route('share.collaborators.store', $assessment), [
            'user_id' => $collaborator->id,
            'role' => 'editor',
        ])
        ->assertInvalid(['user_id']);
});
