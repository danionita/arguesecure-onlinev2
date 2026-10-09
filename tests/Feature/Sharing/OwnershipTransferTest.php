<?php

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('the owner can transfer ownership to an existing collaborator, and the assessment stays reachable by the new owner', function () {
    $assessment = Assessment::factory()->create();
    $owner = $assessment->owner;
    $newOwner = User::factory()->create();
    $assessment->members()->attach($newOwner->id, [
        'role' => 'editor', 'invited_by' => $owner->id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    $this->actingAs($owner)
        ->postJson(route('share.transfer', $assessment), ['user_id' => $newOwner->id])
        ->assertOk();

    $fresh = $assessment->fresh();
    expect($fresh->owner_user_id)->toBe($newOwner->id)
        ->and(Gate::forUser($newOwner)->allows('manage', $fresh))->toBeTrue()
        ->and(Gate::forUser($newOwner)->allows('transferOwnership', $fresh))->toBeTrue();

    // The former owner's old assessment_user row (if any) is superseded —
    // ownership lives solely in owner_user_id — and they land back as a
    // manager collaborator rather than being locked out of their own
    // former assessment.
    $formerOwnerRole = $fresh->members()->where('users.id', $owner->id)->value('role');
    expect($formerOwnerRole)->toBe('manager')
        ->and(Gate::forUser($owner)->allows('view', $fresh))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('transferOwnership', $fresh))->toBeFalse();
});

test('ownership cannot be transferred to a user who is not already a collaborator', function () {
    $assessment = Assessment::factory()->create();
    $owner = $assessment->owner;
    $outsider = User::factory()->create();

    $this->actingAs($owner)
        ->postJson(route('share.transfer', $assessment), ['user_id' => $outsider->id])
        ->assertInvalid(['user_id']);

    expect($assessment->fresh()->owner_user_id)->toBe($owner->id);
});

test('an admin can transfer ownership on behalf of the owner', function () {
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();
    $newOwner = User::factory()->create();
    $assessment->members()->attach($newOwner->id, [
        'role' => 'viewer', 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->postJson(route('share.transfer', $assessment), ['user_id' => $newOwner->id])
        ->assertOk();

    expect($assessment->fresh()->owner_user_id)->toBe($newOwner->id);
});

test('transferring ownership to the current owner is rejected', function () {
    $assessment = Assessment::factory()->create();
    $owner = $assessment->owner;

    $this->actingAs($owner)
        ->postJson(route('share.transfer', $assessment), ['user_id' => $owner->id])
        ->assertInvalid(['user_id']);
});
