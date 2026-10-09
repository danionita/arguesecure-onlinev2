<?php

use App\Domain\Locking\LockManager;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

/*
 * 2.5.1: one editor per node. The node modal holds a whole-node lock
 * (`node`) while it is open; a second person opening the same node gets it
 * read-only, and NodeController::update refuses their writes as a backstop.
 */
function nodeLockEditor(Assessment $assessment, string $name): User
{
    $editor = User::factory()->create(['name' => $name]);
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $editor;
}

afterEach(function () {
    $keys = Redis::keys('lock:*');
    if ($keys !== []) {
        Redis::del($keys);
    }
});

test('a second editor cannot take a node someone has open, and is told who has it', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $dana = nodeLockEditor($assessment, 'Dana');
    $sam = nodeLockEditor($assessment, 'Sam');

    $this->actingAs($dana)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'node']))
        ->assertCreated();

    $this->actingAs($sam)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'node']))
        ->assertStatus(409)
        ->assertJsonPath('holder.name', 'Dana');
});

test('the server refuses a write to a node someone else has open', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Before']);
    $dana = nodeLockEditor($assessment, 'Dana');
    $sam = nodeLockEditor($assessment, 'Sam');

    app(LockManager::class)->acquire($assessment->uuid, $risk->uuid, 'node', $dana->id, 'Dana');

    $this->actingAs($sam)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'After',
            'updated_at' => $risk->updated_at->toJSON(),
        ])
        ->assertStatus(423)
        ->assertJsonPath('holder.name', 'Dana');

    expect($risk->fresh()->title)->toBe('Before');
});

test('the holder writes as normal', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Before']);
    $dana = nodeLockEditor($assessment, 'Dana');

    app(LockManager::class)->acquire($assessment->uuid, $risk->uuid, 'node', $dana->id, 'Dana');

    $this->actingAs($dana)
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'After',
            'updated_at' => $risk->updated_at->toJSON(),
        ])
        ->assertOk();

    expect($risk->fresh()->title)->toBe('After');
});

test('once released, the next person can take it', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $dana = nodeLockEditor($assessment, 'Dana');
    $sam = nodeLockEditor($assessment, 'Sam');

    $this->actingAs($dana)->postJson(route('assessments.locks.store', [$assessment, $risk, 'node']))->assertCreated();
    $this->actingAs($dana)->deleteJson(route('assessments.locks.destroy', [$assessment, $risk, 'node']))->assertNoContent();

    $this->actingAs($sam)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'node']))
        ->assertCreated();
});
