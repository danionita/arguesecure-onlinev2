<?php

use App\Domain\Locking\LockManager;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

/*
 * PLAN.md Phase 6 item 3 / security.md: field-level locking through
 * LockController, backed by app/Domain/Locking/LockManager.php. The
 * required cases from the W16 brief: a viewer may join (view the
 * assessment) but may not acquire a lock; a lock cannot be stolen by a
 * second user while held; a lock refresh by a non-holder fails; a
 * released lock is immediately acquirable.
 */
function lockingEditor(Assessment $assessment): User
{
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $editor;
}

function lockingViewer(Assessment $assessment): User
{
    $viewer = User::factory()->create();
    $assessment->members()->attach($viewer->id, [
        'role' => 'viewer',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $viewer;
}

afterEach(function () {
    $keys = Redis::keys('lock:*');
    if ($keys !== []) {
        Redis::del($keys);
    }
});

test('an anonymous user is rejected from every lock route', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $this->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))->assertUnauthorized();
    $this->patchJson(route('assessments.locks.update', [$assessment, $risk, 'title']))->assertUnauthorized();
    $this->deleteJson(route('assessments.locks.destroy', [$assessment, $risk, 'title']))->assertUnauthorized();
});

test('a viewer may view the assessment but may not acquire a lock', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $viewer = lockingViewer($assessment);

    $this->actingAs($viewer)->get(route('assessments.show', $assessment))->assertOk();

    $this->actingAs($viewer)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))
        ->assertForbidden();

    expect(app(LockManager::class)->currentHolder($assessment->uuid, $risk->uuid, 'title'))->toBeNull();
});

test('an editor can acquire a lock on a field', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = lockingEditor($assessment);

    $this->actingAs($editor)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))
        ->assertCreated()
        ->assertJson(['holder' => ['user_id' => $editor->id, 'name' => $editor->name]]);
});

test('a lock cannot be stolen by a second user while held', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $first = lockingEditor($assessment);
    $second = lockingEditor($assessment);

    $this->actingAs($first)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))
        ->assertCreated();

    $this->actingAs($second)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))
        ->assertStatus(409)
        ->assertJson(['holder' => ['user_id' => $first->id]]);
});

test('a lock refresh by a non-holder fails', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $first = lockingEditor($assessment);
    $second = lockingEditor($assessment);

    $this->actingAs($first)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))
        ->assertCreated();

    $this->actingAs($second)
        ->patchJson(route('assessments.locks.update', [$assessment, $risk, 'title']))
        ->assertStatus(409);
});

test('a lock refresh by the actual holder succeeds', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = lockingEditor($assessment);

    $this->actingAs($editor)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))
        ->assertCreated();

    $this->actingAs($editor)
        ->patchJson(route('assessments.locks.update', [$assessment, $risk, 'title']))
        ->assertOk();
});

test('a released lock is immediately acquirable by someone else', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $first = lockingEditor($assessment);
    $second = lockingEditor($assessment);

    $this->actingAs($first)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))
        ->assertCreated();

    $this->actingAs($first)
        ->deleteJson(route('assessments.locks.destroy', [$assessment, $risk, 'title']))
        ->assertNoContent();

    $this->actingAs($second)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))
        ->assertCreated()
        ->assertJson(['holder' => ['user_id' => $second->id]]);
});

test('a release by a non-holder does not release someone else\'s lock', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $first = lockingEditor($assessment);
    $second = lockingEditor($assessment);

    $this->actingAs($first)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'title']))
        ->assertCreated();

    // A non-holder's release request always 204s (never trust a client's
    // claim about which lock it holds — LockController just runs the
    // compare-and-delete, which is a safe no-op here), but the lock itself
    // must survive it.
    $this->actingAs($second)
        ->deleteJson(route('assessments.locks.destroy', [$assessment, $risk, 'title']))
        ->assertNoContent();

    expect(app(LockManager::class)->currentHolder($assessment->uuid, $risk->uuid, 'title'))
        ->toBe(['user_id' => $first->id, 'name' => $first->name]);
});

test('a lock on a node belonging to a different assessment is rejected', function () {
    $assessment = Assessment::factory()->create();
    $otherAssessment = Assessment::factory()->create();
    $foreignRisk = Risk::factory()->create(['assessment_id' => $otherAssessment->id]);
    $editor = lockingEditor($assessment);

    $this->actingAs($editor)
        ->postJson(route('assessments.locks.store', [$assessment, $foreignRisk, 'title']))
        ->assertNotFound();
});

test('an unknown field name is rejected', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = lockingEditor($assessment);

    $this->actingAs($editor)
        ->postJson(route('assessments.locks.store', [$assessment, $risk, 'owner_user_id']))
        ->assertStatus(422);
});
