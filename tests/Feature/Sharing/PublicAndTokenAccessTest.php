<?php

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Sharing\ShareTokenService;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * security.md §3.1/§3.2: is_public and a valid share token both grant
 * view only, never write, and neither is a route into this package's own
 * mutation endpoints. Asserted against a real mutation endpoint
 * (share/public — one of the two this package owns) rather than only the
 * read route, per the W9 acceptance criteria.
 */
test('a public assessment is readable by a stranger but its sharing cannot be mutated', function () {
    $assessment = Assessment::factory()->public()->create();
    $stranger = User::factory()->create();

    // Read access elsewhere in the app is out of this package's ownership
    // (no assessment show route exists yet — W12), but the policy read
    // check itself is directly assertable here.
    expect($stranger->can('view', $assessment))->toBeTrue();

    $this->actingAs($stranger)
        ->patchJson(route('share.public.update', $assessment), ['is_public' => false])
        ->assertForbidden();

    $this->actingAs($stranger)
        ->postJson(route('share.collaborators.store', $assessment), [
            'user_id' => User::factory()->create()->id,
            'role' => 'editor',
        ])
        ->assertForbidden();

    expect($assessment->fresh()->is_public)->toBeTrue();
});

test('a guest on a public assessment still cannot mutate sharing', function () {
    $assessment = Assessment::factory()->public()->create();

    $this->patchJson(route('share.public.update', $assessment), ['is_public' => false])
        ->assertUnauthorized();
});

test('resolving a valid share token establishes read access via the session flag', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $token = ShareTokenService::generate($assessment);

    $response = $this->get('/s/'.$token);

    $response->assertRedirect(route('dashboard'));

    $flag = session("share_token.{$assessment->id}");
    expect($flag)->not->toBeNull()
        ->and($flag['token'])->toBe($token);

    // The session flag is exactly what AssessmentPolicy::view() checks.
    expect(Gate::forUser(null)->allows('view', $assessment))->toBeTrue();
});

test('resolving an unknown token 404s and grants nothing', function () {
    $this->get('/s/'.str_repeat('a', 43))->assertNotFound();
});

test('a revoked token immediately stops granting access even with a live session flag', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $token = ShareTokenService::generate($assessment);

    $this->get('/s/'.$token)->assertRedirect(route('dashboard'));
    expect(Gate::forUser(null)->allows('view', $assessment))->toBeTrue();

    ShareTokenService::revoke($assessment->fresh());

    // Same session flag, but the column is now null: hasValidShareToken()
    // rejects on the null check before it even compares tokens.
    expect(Gate::forUser(null)->allows('view', $assessment->fresh()))->toBeFalse();

    // Presenting the now-revoked token again also fails outright.
    $this->get('/s/'.$token)->assertNotFound();
});

test('a token-only session cannot reach any mutation endpoint', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $token = ShareTokenService::generate($assessment);

    $this->get('/s/'.$token)->assertRedirect(route('dashboard'));

    // No auth session was ever established — token-only access is
    // anonymous — so every share route (all auth-gated) rejects it exactly
    // as it would an anonymous guest.
    $this->patchJson(route('share.public.update', $assessment), ['is_public' => true])
        ->assertUnauthorized();

    $this->postJson(route('share.collaborators.store', $assessment), [
        'user_id' => User::factory()->create()->id,
        'role' => 'editor',
    ])->assertUnauthorized();

    $this->postJson(route('share.transfer', $assessment), ['user_id' => User::factory()->create()->id])
        ->assertUnauthorized();
});

/**
 * W8b: an instance that forbids public assessments must refuse the flip
 * server-side (ShareController::updatePublic), not merely hide the switch
 * in the UI.
 */
test('the owner cannot make an assessment public when the instance forbids it', function () {
    InstanceSettings::update(['public_assessments_allowed' => false]);

    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id, 'is_public' => false]);

    $response = $this->actingAs($owner)
        ->patchJson(route('share.public.update', $assessment), ['is_public' => true]);

    $response->assertStatus(422);
    expect($assessment->fresh()->is_public)->toBeFalse();
});

test('turning an already-public assessment private is still allowed when the instance forbids public assessments', function () {
    InstanceSettings::update(['public_assessments_allowed' => false]);

    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id, 'is_public' => true]);

    $response = $this->actingAs($owner)
        ->patchJson(route('share.public.update', $assessment), ['is_public' => false]);

    $response->assertOk();
    expect($assessment->fresh()->is_public)->toBeFalse();
});

test('the owner can make an assessment public when the instance allows it', function () {
    InstanceSettings::update(['public_assessments_allowed' => true]);

    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id, 'is_public' => false]);

    $response = $this->actingAs($owner)
        ->patchJson(route('share.public.update', $assessment), ['is_public' => true]);

    $response->assertOk();
    expect($assessment->fresh()->is_public)->toBeTrue();
});
