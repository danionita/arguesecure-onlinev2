<?php

use App\Domain\Dashboard\DashboardData;
use App\Models\Assessment;
use App\Models\AssessmentUser;
use App\Models\Risk;
use App\Models\User;

/*
 * Assignees (W46, item 16; PLAN-2.2.md D13).
 *
 * The authorization here is deliberately small, and the tests exist to keep it
 * that way. Assigning is `update` on the assessment; being assigned grants
 * **nothing**. There is no node-scoped ability anywhere in this codebase and
 * this package does not introduce the first one — which is a claim worth
 * asserting rather than asserting the absence of code.
 */
function collaborator(Assessment $assessment, string $role): User
{
    $user = User::factory()->create();

    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $user;
}

// --- who may assign -------------------------------------------------------

test('an editor can assign a collaborator', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $editor = collaborator($assessment, 'editor');
    $viewer = collaborator($assessment, 'viewer');

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $viewer->id,
        ])
        ->assertCreated()
        ->assertJsonPath('assignees.0.name', $viewer->name);

    expect($risk->fresh()->assignees()->count())->toBe(1);
});

test('a viewer cannot assign anyone', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $viewer = collaborator($assessment, 'viewer');

    $this->actingAs($viewer)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $viewer->id,
        ])
        ->assertForbidden();

    expect($risk->fresh()->assignees()->count())->toBe(0);
});

test('a stranger cannot assign anyone', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $stranger->id,
        ])
        ->assertForbidden();
});

test('a node from another assessment 404s rather than leaking through the route', function () {
    $assessment = Assessment::factory()->create();
    $other = Assessment::factory()->create();
    $foreignRisk = Risk::factory()->for($other)->create();
    $viewer = collaborator($assessment, 'viewer');

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $foreignRisk->uuid]), [
            'user_id' => $viewer->id,
        ])
        ->assertNotFound();

    expect($foreignRisk->fresh()->assignees()->count())->toBe(0);
});

test('assigning someone twice is a no-op rather than a duplicate-key error', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $editor = collaborator($assessment, 'editor');

    foreach ([1, 2] as $ignored) {
        $this->actingAs($assessment->owner)
            ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
                'user_id' => $editor->id,
            ])
            ->assertCreated();
    }

    expect($risk->fresh()->assignees()->count())->toBe(1);
});

// --- an assignment grants nothing (the point of the package) ---------------

test('a viewer assignee still cannot PATCH the node they are assigned', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create(['title' => 'Untouched']);
    $viewer = collaborator($assessment, 'viewer');

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $viewer->id,
        ])
        ->assertCreated();

    $this->actingAs($viewer)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk->uuid]), [
            'title' => 'Edited by the assignee',
        ])
        ->assertForbidden();

    expect($risk->fresh()->title)->toBe('Untouched');
});

test('a viewer assignee still cannot delete or comment on the node', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $viewer = collaborator($assessment, 'viewer');
    $risk->assignees()->attach($viewer->id);

    $this->actingAs($viewer)
        ->deleteJson(route('assessments.nodes.destroy', [$assessment, $risk->uuid]))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson(route('assessments.comments.store', [$assessment, $risk->uuid]), ['body' => 'Hello'])
        ->assertForbidden();

    expect($risk->fresh()->deleted_at)->toBeNull();
});

// --- D13: an assignee must already be a collaborator ----------------------

test('assigning a non-collaborator is refused rather than silently sharing', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $outsider = User::factory()->create();

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $outsider->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('requires_share', true);

    expect($risk->fresh()->assignees()->count())->toBe(0)
        // The assertion that matters: nothing was shared as a side effect.
        ->and($assessment->fresh()->members()->count())->toBe(0);
});

test('the confirmed share step adds a viewer row and nothing more', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $outsider = User::factory()->create();

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $outsider->id,
            'share_as_viewer' => true,
        ])
        ->assertCreated();

    $membership = $assessment->fresh()->members()->where('users.id', $outsider->id)->firstOrFail();

    expect($membership->pivot->role)->toBe('viewer')
        ->and($risk->fresh()->assignees()->count())->toBe(1);

    // `viewer` and nothing more: still no write anywhere.
    $this->actingAs($outsider)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk->uuid]), ['title' => 'Nope'])
        ->assertForbidden();
});

/*
 * The escalation this package must not open. Adding a collaborator has always
 * been a `manage` action; routing it through a route whose name says "assign"
 * must not turn it into an `update` one, or any editor could hand read access
 * to a whole assessment to anyone with an account.
 */
test('an editor cannot use the share step to grant access', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $editor = collaborator($assessment, 'editor');
    $outsider = User::factory()->create();

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $outsider->id,
            'share_as_viewer' => true,
        ])
        ->assertForbidden();

    expect($assessment->fresh()->members()->where('users.id', $outsider->id)->exists())->toBeFalse()
        ->and($risk->fresh()->assignees()->count())->toBe(0);
});

test('a manager can use the share step', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $manager = collaborator($assessment, 'manager');
    $outsider = User::factory()->create();

    $this->actingAs($manager)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $outsider->id,
            'share_as_viewer' => true,
        ])
        ->assertCreated();

    expect($assessment->fresh()->members()->where('users.id', $outsider->id)->exists())->toBeTrue();
});

test('a deactivated account cannot be shared in through the assign route', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $outsider = User::factory()->create(['is_active' => false]);

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $risk->uuid]), [
            'user_id' => $outsider->id,
            'share_as_viewer' => true,
        ])
        ->assertStatus(422);

    expect($assessment->fresh()->members()->count())->toBe(0);
});

// --- removing a collaborator ---------------------------------------------

/*
 * The decision the brief asked to be made explicitly. Removing a collaborator
 * removes their assignments: the alternative leaves a row saying "assigned to
 * Dana" on a node Dana can no longer open, which either shows her work she
 * cannot reach or names her to everyone else indefinitely. See the docblock on
 * AssessmentUser.
 */
test('removing a collaborator removes their assignments', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $editor = collaborator($assessment, 'editor');
    $risk->assignees()->attach($editor->id);

    $membership = AssessmentUser::query()->where('assessment_id', $assessment->id)->where('user_id', $editor->id)->firstOrFail();

    $this->actingAs($assessment->owner)
        ->deleteJson(route('share.collaborators.destroy', [$assessment, $membership->id]))
        ->assertOk();

    expect($risk->fresh()->assignees()->count())->toBe(0);
});

test('removing a collaborator leaves their assignments on other assessments alone', function () {
    $assessment = Assessment::factory()->create();
    $other = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $otherRisk = Risk::factory()->for($other)->create();

    $person = collaborator($assessment, 'editor');
    $other->members()->attach($person->id, [
        'role' => 'editor',
        'invited_by' => $other->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $risk->assignees()->attach($person->id);
    $otherRisk->assignees()->attach($person->id);

    $membership = AssessmentUser::query()->where('assessment_id', $assessment->id)->where('user_id', $person->id)->firstOrFail();

    $this->actingAs($assessment->owner)
        ->deleteJson(route('share.collaborators.destroy', [$assessment, $membership->id]))
        ->assertOk();

    expect($risk->fresh()->assignees()->count())->toBe(0)
        ->and($otherRisk->fresh()->assignees()->count())->toBe(1);
});

/*
 * A role change is a different event and deliberately keeps the assignment: a
 * viewer can still be the person responsible for a node. This is what pins the
 * hook to `deleted` rather than `saved`.
 */
test('demoting a collaborator keeps their assignments', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $editor = collaborator($assessment, 'editor');
    $risk->assignees()->attach($editor->id);

    $membership = AssessmentUser::query()->where('assessment_id', $assessment->id)->where('user_id', $editor->id)->firstOrFail();

    $this->actingAs($assessment->owner)
        ->patchJson(route('share.collaborators.update', [$assessment, $membership->id]), ['role' => 'viewer'])
        ->assertOk();

    expect($risk->fresh()->assignees()->count())->toBe(1);
});

// --- unassigning ----------------------------------------------------------

test('unassigning removes the assignment but never the collaborator', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $editor = collaborator($assessment, 'editor');
    $risk->assignees()->attach($editor->id);

    $this->actingAs($assessment->owner)
        ->deleteJson(route('assessments.nodes.assignees.destroy', [$assessment, $risk->uuid, $editor->id]))
        ->assertOk();

    expect($risk->fresh()->assignees()->count())->toBe(0)
        // Silently un-sharing is as surprising as silently sharing, and by now
        // they may be reading the assessment for unrelated reasons.
        ->and($assessment->fresh()->members()->where('users.id', $editor->id)->exists())->toBeTrue();
});

// --- reading --------------------------------------------------------------

test('anyone who can view the assessment can see who is assigned', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $editor = collaborator($assessment, 'editor');
    $viewer = collaborator($assessment, 'viewer');
    $risk->assignees()->attach($editor->id);

    $this->actingAs($viewer)
        ->getJson(route('assessments.nodes.assignees.index', [$assessment, $risk->uuid]))
        ->assertOk()
        ->assertJsonPath('assignees.0.name', $editor->name)
        ->assertJsonPath('canAssign', false)
        ->assertJsonPath('canShare', false);
});

test('the assignee list never carries an email address', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $editor = collaborator($assessment, 'editor');
    $risk->assignees()->attach($editor->id);

    $response = $this->actingAs($assessment->owner)
        ->getJson(route('assessments.nodes.assignees.index', [$assessment, $risk->uuid]))
        ->assertOk();

    expect($response->getContent())->not->toContain($editor->email);
});

test('a stranger cannot read the assignee list of a private assessment', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('assessments.nodes.assignees.index', [$assessment, $risk->uuid]))
        ->assertForbidden();
});

test('the candidate list is the owner and the collaborators, and needs update', function () {
    $assessment = Assessment::factory()->create();
    $editor = collaborator($assessment, 'editor');
    $viewer = collaborator($assessment, 'viewer');
    User::factory()->create(['name' => 'Unrelated person']);

    $response = $this->actingAs($editor)
        ->getJson(route('assessments.assignee-candidates.index', $assessment))
        ->assertOk();

    $names = collect($response->json('users'))->pluck('name')->all();

    expect($names)->toContain($assessment->owner->name, $editor->name, $viewer->name)
        ->and($names)->not->toContain('Unrelated person');

    $this->actingAs($viewer)
        ->getJson(route('assessments.assignee-candidates.index', $assessment))
        ->assertForbidden();
});

// --- the dashboard --------------------------------------------------------

test('an assigned node appears on the assignee dashboard with what it still needs', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create([
        'title' => 'Cardholder data breach',
        'impact_mode' => null,
        'impact_amount' => null,
    ]);
    $editor = collaborator($assessment, 'editor');
    $risk->assignees()->attach($editor->id);

    $rows = app(DashboardData::class)->for($editor->fresh())['assignedToMe'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['title'])->toBe('Cardholder data breach')
        ->and($rows[0]['node_uuid'])->toBe($risk->uuid)
        ->and($rows[0]['assessment_uuid'])->toBe($assessment->uuid)
        ->and($rows[0]['missing'])->toBe('needs an impact');
});

test('a complete node is listed without a missing note', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create([
        'impact_mode' => 'quantitative',
        'impact_amount' => 50_000,
    ]);
    $editor = collaborator($assessment, 'editor');
    $risk->assignees()->attach($editor->id);

    $rows = app(DashboardData::class)->for($editor->fresh())['assignedToMe'];

    expect($rows[0]['missing'])->toBeNull();
});

test('the dashboard never lists a node from an assessment the user can no longer reach', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $person = User::factory()->create();

    // Written straight to the pivot, bypassing the route and the removal hook
    // both: this asserts the query's own scoping rather than the hook's, so
    // one covering for the other cannot hide a hole in the other.
    $risk->assignees()->attach($person->id);

    expect(app(DashboardData::class)->for($person)['assignedToMe'])->toBe([]);
});

test('another person’s assignments never appear on my dashboard', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $mine = collaborator($assessment, 'editor');
    $theirs = collaborator($assessment, 'editor');
    $risk->assignees()->attach($theirs->id);

    expect(app(DashboardData::class)->for($mine->fresh())['assignedToMe'])->toBe([]);
});
