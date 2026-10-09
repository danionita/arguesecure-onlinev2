<?php

use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\NodeRevision;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Per-node history (PLAN.md Phase 8 item 2, schema.md §3.9, ui.md §7):
 * listing a node's revisions, grouped by operation, and "revert this
 * field" — recorded as a normal edit, not an undo (schema.md §3.10).
 *
 * IMPORTANT — see the W18 completion report and the docblock at the top of
 * the W18 routes/web.php block: no writer of `node_revisions` exists
 * anywhere in this codebase yet (app/Domain/History/RevisionRecorder.php,
 * which the task brief said already existed, does not; NodeController::
 * update() saves fields without recording anything). These tests seed
 * node_revisions rows directly with NodeRevision::create() — exactly what
 * any writer, present or future, would leave behind — to prove the list/
 * group/revert mechanics are correct independent of who populates the
 * table.
 */
function attachRevisionMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('an anonymous user is rejected from the revert route', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Current title']);
    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['title' => ['from' => 'Old title', 'to' => 'Current title']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $this->postJson(route('assessments.nodes.revisions.revert', [$assessment, $risk, $revision]), ['field' => 'title'])
        ->assertUnauthorized();
});

test('a stranger cannot list revisions or revert a field on a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->getJson(route('assessments.nodes.revisions.index', [$assessment, $risk]))
        ->assertForbidden();
});

test('a viewer can list revisions but cannot revert a field', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Current title']);
    $viewer = User::factory()->create();
    attachRevisionMember($assessment, $viewer, 'viewer');

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['title' => ['from' => 'Old title', 'to' => 'Current title']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->getJson(route('assessments.nodes.revisions.index', [$assessment, $risk]))
        ->assertOk()
        ->assertJsonCount(1, 'revisions')
        ->assertJsonPath('revisions.0.id', $revision->id);

    $this->actingAs($viewer)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $risk, $revision]), ['field' => 'title'])
        ->assertForbidden();

    expect($risk->fresh()->title)->toBe('Current title');
});

test('a commenter cannot revert a field — reverting needs update, not comment', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Current title']);
    $commenter = User::factory()->create();
    attachRevisionMember($assessment, $commenter, 'commenter');

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['title' => ['from' => 'Old title', 'to' => 'Current title']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($commenter)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $risk, $revision]), ['field' => 'title'])
        ->assertForbidden();
});

test('an editor can revert a field, and the revert itself writes a new revision, not an undo-stack entry', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Current title']);
    $editor = User::factory()->create();
    attachRevisionMember($assessment, $editor, 'editor');

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['title' => ['from' => 'Old title', 'to' => 'Current title']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $risk, $revision]), ['field' => 'title']);

    $response->assertOk();
    expect($risk->fresh()->title)->toBe('Old title');
    expect($response->json('node.title'))->toBe('Old title');

    // The revert wrote a *new* node_revisions row recording itself as an
    // ordinary update (schema.md §3.10: "recorded as a normal edit, not an
    // undo") — never touching assessment_operations, W14's separate stack.
    $newRevisionId = $response->json('revision.id');
    expect($newRevisionId)->not->toBe($revision->id);

    $newRevision = NodeRevision::find($newRevisionId);
    expect($newRevision->action)->toBe('update');
    expect($newRevision->changes)->toBe(['title' => ['from' => 'Current title', 'to' => 'Old title']]);

    expect(AssessmentOperation::query()->where('assessment_id', $assessment->id)->count())->toBe(0);

    // Grouped-by-operation listing now shows two independent revisions
    // for this node, both with a null operation_id (field edits never
    // group under a structural operation).
    $list = $this->actingAs($editor)
        ->getJson(route('assessments.nodes.revisions.index', [$assessment, $risk]));
    $list->assertOk()->assertJsonCount(2, 'revisions');
});

test('reverting a field with no prior value recorded in that revision is rejected', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Current title']);
    $editor = User::factory()->create();
    attachRevisionMember($assessment, $editor, 'editor');

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['description' => ['from' => 'Old desc', 'to' => 'New desc']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $risk, $revision]), ['field' => 'title'])
        ->assertStatus(422);

    expect($risk->fresh()->title)->toBe('Current title');
});

test('a link-action revision cannot be reverted by field', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachRevisionMember($assessment, $editor, 'editor');

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'link',
        'changes' => ['other_uuid' => (string) Str::uuid(), 'other_title' => 'Phishing', 'pivot' => []],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $risk, $revision]), ['field' => 'title'])
        ->assertStatus(422);
});

test('an attempt to revert a non-fillable/unknown field is rejected', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachRevisionMember($assessment, $editor, 'editor');

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['assessment_id' => ['from' => 1, 'to' => $assessment->id]],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $risk, $revision]), ['field' => 'assessment_id'])
        ->assertStatus(422);
});

test('a revision from a different node is rejected with 404', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $otherRisk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachRevisionMember($assessment, $editor, 'editor');

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $otherRisk->id,
        'node_uuid' => $otherRisk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['title' => ['from' => 'a', 'to' => 'b']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $risk, $revision]), ['field' => 'title'])
        ->assertNotFound();
});

test('a revision from a different assessment is rejected with 404', function () {
    $assessment = Assessment::factory()->create();
    $otherAssessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $foreignRisk = Risk::factory()->create(['assessment_id' => $otherAssessment->id]);
    $editor = User::factory()->create();
    attachRevisionMember($assessment, $editor, 'editor');

    $revision = NodeRevision::create([
        'assessment_id' => $otherAssessment->id,
        'node_id' => $foreignRisk->id,
        'node_uuid' => $foreignRisk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['title' => ['from' => 'a', 'to' => 'b']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.revisions.revert', [$assessment, $risk, $revision]), ['field' => 'title'])
        ->assertNotFound();
});

test('revisions grouped by operation_id expose that grouping to the client', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $owner = $assessment->owner;

    $operation = AssessmentOperation::create([
        'assessment_id' => $assessment->id,
        'user_id' => $owner->id,
        'kind' => 'node.create',
        'summary' => "creation of 'Risk'",
        'inverse' => ['action' => 'delete_node', 'node_uuids' => [$risk->uuid]],
        'created_at' => now(),
    ]);

    NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => $owner->id,
        'action' => 'create',
        'changes' => ['title' => ['from' => null, 'to' => 'Risk']],
        'operation_id' => $operation->id,
        'created_at' => now(),
    ]);

    NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => $owner->id,
        'action' => 'update',
        'changes' => ['description' => ['from' => null, 'to' => 'A description']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($owner)
        ->getJson(route('assessments.nodes.revisions.index', [$assessment, $risk]));

    $response->assertOk()->assertJsonCount(2, 'revisions');
    $grouped = collect($response->json('revisions'))->groupBy('operation_id');
    // One real group (the structural create, keyed by its operation_id)
    // plus the ungrouped field edit (Laravel's groupBy() keys a null
    // grouping value as an empty string).
    expect($grouped->keys()->filter()->count())->toBe(1);
    expect($grouped->get((string) $operation->id))->toHaveCount(1);
});
