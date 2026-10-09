<?php

use App\Models\Assessment;
use App\Models\User;

/**
 * security.md §7: search must not become a user-enumeration oracle.
 * Restricted to callers who already hold `manage` on a real assessment
 * (see ShareAuthorizationTest), and — asserted here — the result set
 * itself excludes people who add no value to an "add a collaborator"
 * search: the owner, existing collaborators, and deactivated accounts.
 */
test('search excludes the owner, existing collaborators and deactivated users, and returns only id/name/email', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create(['name' => 'Searching Manager']);
    $assessment->members()->attach($manager->id, [
        'role' => 'manager', 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    $findable = User::factory()->create(['name' => 'Zzyx Findable', 'email' => 'zzyx-findable@example.com']);
    $existingCollaborator = User::factory()->create(['name' => 'Zzyx Already Shared']);
    $assessment->members()->attach($existingCollaborator->id, [
        'role' => 'viewer', 'invited_by' => $manager->id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    $inactive = User::factory()->inactive()->create(['name' => 'Zzyx Deactivated']);
    $ownerMatchingQuery = $assessment->owner;
    $ownerMatchingQuery->update(['name' => 'Zzyx Owner']);

    $response = $this->actingAs($manager)
        ->getJson(route('share.users.search', $assessment).'?query=Zzyx')
        ->assertOk();

    $names = collect($response->json('users'))->pluck('name');

    expect($names)->toContain('Zzyx Findable')
        ->and($names)->not->toContain('Zzyx Already Shared')
        ->and($names)->not->toContain('Zzyx Deactivated')
        ->and($names)->not->toContain('Zzyx Owner');

    $returned = collect($response->json('users'))->firstWhere('id', $findable->id);
    expect(array_keys($returned))->toEqualCanonicalizing(['id', 'name', 'email']);
});

test('search requires at least two characters', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager', 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    $this->actingAs($manager)
        ->getJson(route('share.users.search', $assessment).'?query=a')
        ->assertInvalid(['query']);
});
