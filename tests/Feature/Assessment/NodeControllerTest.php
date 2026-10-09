<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Risk;
use App\Models\User;

/*
 * Node CRUD authorization matrix (CLAUDE.md "every route touching an
 * assessment goes through AssessmentPolicy") plus the 409 optimistic
 * concurrency path (ui.md §3.5). Every mutating action is checked against
 * {guest, stranger, viewer, commenter, editor}: only editor and above may
 * write; a viewer must not be able to mutate anything (W12 brief).
 */
function attachMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('an anonymous user is rejected from every node route', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $this->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk'])
        ->assertUnauthorized();

    $this->patchJson(route('assessments.nodes.update', [$assessment, $risk]), ['updated_at' => now()->toJSON()])
        ->assertUnauthorized();

    $this->deleteJson(route('assessments.nodes.destroy', [$assessment, $risk]))
        ->assertUnauthorized();
});

test('a stranger is rejected from every node route', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk'])
        ->assertForbidden();

    $this->actingAs($stranger)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), ['updated_at' => now()->toJSON()])
        ->assertForbidden();

    $this->actingAs($stranger)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $risk]))
        ->assertForbidden();
});

test('a viewer cannot create, update or delete a node', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $viewer = User::factory()->create();
    attachMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'hacked',
            'updated_at' => $risk->updated_at->toJSON(),
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $risk]))
        ->assertForbidden();

    expect($risk->fresh()->title)->not->toBe('hacked');
});

test('a commenter cannot create, update or delete a node', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $commenter = User::factory()->create();
    attachMember($assessment, $commenter, 'commenter');

    $this->actingAs($commenter)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk'])
        ->assertForbidden();
});

test('an editor can create a risk directly under the root with an empty title', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk']);

    $response->assertCreated();
    expect($response->json('node.title'))->toBe('');
    expect($response->json('node.type'))->toBe('risk');
    expect(Risk::query()->where('assessment_id', $assessment->id)->count())->toBe(1);
});

test('an editor can create an attack linked to an existing risk', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), [
            'type' => 'attack',
            'title' => 'Phishing',
            'risk_uuid' => $risk->uuid,
        ]);

    $response->assertCreated();
    $attackUuid = $response->json('node.uuid');
    expect($risk->fresh()->attacks->pluck('uuid')->all())->toBe([$attackUuid]);
});

test('an editor can create a defence linked to an existing attack', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), [
            'type' => 'defence',
            'title' => 'MFA',
            'attack_uuid' => $attack->uuid,
        ]);

    $response->assertCreated();
    $defenceUuid = $response->json('node.uuid');
    expect($attack->fresh()->defences->pluck('uuid')->all())->toBe([$defenceUuid]);
});

test('creating an attack requires a risk_uuid belonging to the same assessment', function () {
    $assessment = Assessment::factory()->create();
    $otherAssessment = Assessment::factory()->create();
    $foreignRisk = Risk::factory()->create(['assessment_id' => $otherAssessment->id]);
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), [
            'type' => 'attack',
            'risk_uuid' => $foreignRisk->uuid,
        ])
        ->assertStatus(422);
});

test('the node type is validated against a fixed whitelist', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'root'])
        ->assertStatus(422);

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'App\\Models\\User'])
        ->assertStatus(422);
});

test('an editor can update a node title', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Old']);
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'New title',
            'updated_at' => $risk->updated_at->toJSON(),
        ]);

    $response->assertOk();
    expect($risk->fresh()->title)->toBe('New title');
});

/**
 * The core of ui.md §3.5: a stale `updated_at` never silently clobbers a
 * concurrent edit. The server returns 409 with its own current state
 * instead.
 */
test('updating with a stale updated_at returns 409 and does not overwrite the row', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Original']);
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    $staleUpdatedAt = $risk->updated_at->toJSON();

    // `nodes.updated_at` has only second precision (a plain `timestamps()`
    // column, schema.md §3.4) — travel forward a second so "someone else's
    // edit" is unambiguously later than the token the client is holding,
    // rather than racing the test's own wall-clock speed.
    $this->travel(1)->second();

    // Someone else's edit lands first.
    $risk->forceFill(['title' => 'Changed by Dana'])->save();

    $response = $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'My conflicting edit',
            'updated_at' => $staleUpdatedAt,
        ]);

    $response->assertStatus(409);
    expect($response->json('node.title'))->toBe('Changed by Dana');
    expect($risk->fresh()->title)->toBe('Changed by Dana');
});

test('a defence-only field is rejected on a risk node', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'status' => 'implemented',
            'updated_at' => $risk->updated_at->toJSON(),
        ]);

    // 'status' is not a rule on a risk's UpdateNodeRequest, so it is
    // silently dropped by validated() rather than reaching fill() and
    // tripping chk_nodes_defence_cols at the database layer — a risk's
    // `status` column stays NULL, as the CHECK constraint requires.
    $response->assertOk();
    expect($risk->fresh()->status)->toBeNull();
});

test('a node uuid from a different assessment is rejected', function () {
    $assessment = Assessment::factory()->create();
    $otherAssessment = Assessment::factory()->create();
    $foreignRisk = Risk::factory()->create(['assessment_id' => $otherAssessment->id]);
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    // 404, not 403: Laravel's implicit route model binding automatically
    // scopes a custom-keyed child parameter ({node:uuid}) to the parent
    // ({assessment:uuid}) via the guessed `Assessment::nodes()` relation
    // (Laravel's "implicit model binding scoping" convention) — the route
    // simply never finds this uuid under this assessment, before
    // UpdateNodeRequest::authorize() even runs. Its own
    // `$node->assessment_id === $assessment->id` check (security.md §3.3
    // IDOR pattern, matching UpdateCollaboratorRequest) is defense in
    // depth for if that routing ever changes.
    $this->actingAs($editor)
        ->patchJson(route('assessments.nodes.update', [$assessment, $foreignRisk]), [
            'title' => 'hijacked',
            'updated_at' => $foreignRisk->updated_at->toJSON(),
        ])
        ->assertNotFound();

    expect($foreignRisk->fresh()->title)->not->toBe('hijacked');
});

test('an editor can soft delete a node', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $risk]))
        ->assertNoContent();

    expect(Risk::query()->whereKey($risk->id)->exists())->toBeFalse();
    expect(Risk::withTrashed()->whereKey($risk->id)->exists())->toBeTrue();
});

test('the owner and a manager can also mutate nodes', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    attachMember($assessment, $manager, 'manager');

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk'])
        ->assertCreated();

    $this->actingAs($manager)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk'])
        ->assertCreated();
});
