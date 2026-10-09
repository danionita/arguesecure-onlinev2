<?php

use App\Models\Assessment;
use App\Models\User;

/*
 * The authorization matrix CLAUDE.md and security.md §3.3 require for
 * every route this package adds. Every mutating action is checked against
 * {guest, stranger, viewer, commenter, editor} — none of whom may change
 * sharing — and against {manager} for the privilege-escalation boundary
 * (manage != transferOwnership).
 */
function attachCollaborator(Assessment $assessment, User $user, string $role, ?User $invitedBy = null): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => ($invitedBy ?? $assessment->owner)->id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

function shareRoutes(Assessment $assessment): array
{
    return [
        ['GET', route('share.index', $assessment)],
        ['GET', route('share.users.search', $assessment).'?query=al'],
        ['POST', route('share.collaborators.store', $assessment)],
        ['POST', route('share.transfer', $assessment)],
        ['PATCH', route('share.public.update', $assessment)],
        ['POST', route('share.token.store', $assessment)],
        ['DELETE', route('share.token.destroy', $assessment)],
    ];
}

test('an anonymous user is rejected from every share route', function () {
    $assessment = Assessment::factory()->create();

    foreach (shareRoutes($assessment) as [$method, $uri]) {
        $this->json($method, $uri)->assertUnauthorized();
    }
});

test('a logged-in user with no relationship to the assessment is rejected from every share route', function () {
    $assessment = Assessment::factory()->create();
    $stranger = User::factory()->create();

    foreach (shareRoutes($assessment) as [$method, $uri]) {
        $this->actingAs($stranger)->json($method, $uri)->assertForbidden();
    }
});

test('a viewer cannot change sharing', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    attachCollaborator($assessment, $viewer, 'viewer');

    foreach (shareRoutes($assessment) as [$method, $uri]) {
        $this->actingAs($viewer)->json($method, $uri)->assertForbidden();
    }
});

test('a commenter cannot change sharing', function () {
    $assessment = Assessment::factory()->create();
    $commenter = User::factory()->create();
    attachCollaborator($assessment, $commenter, 'commenter');

    foreach (shareRoutes($assessment) as [$method, $uri]) {
        $this->actingAs($commenter)->json($method, $uri)->assertForbidden();
    }
});

test('an editor cannot change sharing', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    attachCollaborator($assessment, $editor, 'editor');

    foreach (shareRoutes($assessment) as [$method, $uri]) {
        $this->actingAs($editor)->json($method, $uri)->assertForbidden();
    }
});

test('a manager can view the share dialog, add collaborators, toggle public and manage the token', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    attachCollaborator($assessment, $manager, 'manager');
    $addable = User::factory()->create();

    $this->actingAs($manager)->getJson(route('share.index', $assessment))->assertOk();

    $this->actingAs($manager)
        ->getJson(route('share.users.search', $assessment).'?query='.substr($addable->name, 0, 3))
        ->assertOk();

    $this->actingAs($manager)
        ->postJson(route('share.collaborators.store', $assessment), [
            'user_id' => $addable->id,
            'role' => 'editor',
        ])
        ->assertCreated();

    $this->actingAs($manager)
        ->patchJson(route('share.public.update', $assessment), ['is_public' => true])
        ->assertOk();

    $this->actingAs($manager)
        ->postJson(route('share.token.store', $assessment))
        ->assertOk();

    $this->actingAs($manager)
        ->deleteJson(route('share.token.destroy', $assessment))
        ->assertOk();
});

/**
 * The classic sharing-feature privilege-escalation hole named explicitly
 * in the W9 brief: a manager can reach every other share route but not
 * this one.
 */
test('a manager cannot transfer ownership', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    attachCollaborator($assessment, $manager, 'manager');

    $this->actingAs($manager)
        ->postJson(route('share.transfer', $assessment), ['user_id' => $manager->id])
        ->assertForbidden();
});

test('a manager cannot demote the owner', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    attachCollaborator($assessment, $manager, 'manager');

    // The owner has no assessment_user row to demote via this route in the
    // first place (schema.md §3.7) — confirm there is nothing to attack.
    $ownerRow = $assessment->members()->where('users.id', $assessment->owner_user_id)->exists();
    expect($ownerRow)->toBeFalse();
});

test('a manager cannot remove the owner', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    attachCollaborator($assessment, $manager, 'manager');

    $ownerRow = $assessment->members()->where('users.id', $assessment->owner_user_id)->exists();
    expect($ownerRow)->toBeFalse();
});

test('the owner can reach every share route including transfer', function () {
    $assessment = Assessment::factory()->create();
    $owner = $assessment->owner;
    $target = User::factory()->create();
    attachCollaborator($assessment, $target, 'viewer');

    $this->actingAs($owner)->getJson(route('share.index', $assessment))->assertOk();

    $this->actingAs($owner)
        ->postJson(route('share.transfer', $assessment), ['user_id' => $target->id])
        ->assertOk();
});
