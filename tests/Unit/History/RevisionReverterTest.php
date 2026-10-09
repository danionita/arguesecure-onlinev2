<?php

use App\Domain\History\Exceptions\RevisionNotRevertibleException;
use App\Domain\History\RevisionReverter;
use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\NodeRevision;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in(__FILE__);

/*
 * W18b — RevisionReverter, the domain logic extracted from the W18 route
 * closure (routes/web.php's former "revert this field" handler). The HTTP
 * authorization/shape contract is pinned by the pre-existing
 * tests/Feature/History/NodeRevisionRevertTest.php, unchanged by this
 * package; these exercise the domain object directly.
 */
test('reverting a field applies the recorded prior value and returns the new revision', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Current title']);
    $editor = User::factory()->create();

    $oldRevision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['title' => ['from' => 'Old title', 'to' => 'Current title']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    $newRevision = (new RevisionReverter)->revert($risk, $oldRevision, 'title', $editor);

    expect($risk->fresh()->title)->toBe('Old title');
    expect($newRevision->id)->not->toBe($oldRevision->id);
    expect($newRevision->action)->toBe('update');
    expect($newRevision->changes)->toBe(['title' => ['from' => 'Current title', 'to' => 'Old title']]);
    expect(AssessmentOperation::query()->count())->toBe(0);
});

test('a link-shaped revision cannot be reverted by field', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'link',
        'changes' => ['other_uuid' => (string) Str::uuid(), 'other_title' => 'x', 'pivot' => []],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    (new RevisionReverter)->revert($risk, $revision, 'title', $editor);
})->throws(RevisionNotRevertibleException::class);

test('a field with no recorded prior value is rejected', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'update',
        'changes' => ['description' => ['from' => 'a', 'to' => 'b']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    (new RevisionReverter)->revert($risk, $revision, 'title', $editor);
})->throws(RevisionNotRevertibleException::class);

test('a non-fillable column cannot be reverted', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();

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

    (new RevisionReverter)->revert($risk, $revision, 'assessment_id', $editor);
})->throws(RevisionNotRevertibleException::class);

test('reverting to the value already on the node is rejected as a no-op', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Current title']);
    $editor = User::factory()->create();

    $revision = NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $risk->id,
        'node_uuid' => $risk->uuid,
        'user_id' => null,
        'action' => 'update',
        // The "prior" value already matches what the node currently holds.
        'changes' => ['title' => ['from' => 'Current title', 'to' => 'Something else']],
        'operation_id' => null,
        'created_at' => now(),
    ]);

    (new RevisionReverter)->revert($risk, $revision, 'title', $editor);
})->throws(RevisionNotRevertibleException::class);
