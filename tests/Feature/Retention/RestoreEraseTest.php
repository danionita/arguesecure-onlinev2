<?php

use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * W113 — the *Deleted* list, Restore and Erase permanently.
 *
 * Restore is the `delete` ability (owner, admin, manager): whoever could put
 * an assessment in the trash can take it back out. Erase is `forceDelete`
 * (owner and admin, security.md §3.1): a manager can delete and restore the
 * owner's work but never destroy it. Both routes bind trashed rows and answer
 * 404 for a live one, after the policy.
 */
beforeEach(function () {
    (new SystemScaleSeeder)->run();
});

function retentionMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

/** @return array{0: Assessment, 1: User|null} a deleted assessment and the actor in that role */
function deletedAssessmentWith(string $role): array
{
    $assessment = Assessment::factory()->create();
    $actor = match ($role) {
        'guest' => null,
        'owner' => $assessment->owner,
        'admin' => User::factory()->admin()->create(),
        'stranger' => User::factory()->create(),
        default => tap(User::factory()->create(), fn (User $user) => retentionMember($assessment, $user, $role)),
    };
    $assessment->delete();

    return [$assessment, $actor];
}

// --- restore -------------------------------------------------------------

dataset('restorers', ['owner', 'admin', 'manager']);
dataset('non-restorers', ['editor', 'commenter', 'viewer', 'stranger']);

test('restore is allowed to whoever could delete', function (string $role) {
    [$assessment, $actor] = deletedAssessmentWith($role);

    $this->actingAs($actor)
        ->post(route('assessments.restore', $assessment->uuid))
        ->assertRedirect(route('assessments.show', $assessment->uuid));

    expect($assessment->fresh()->trashed())->toBeFalse();
})->with('restorers');

test('restore is forbidden to everyone else', function (string $role) {
    [$assessment, $actor] = deletedAssessmentWith($role);

    $this->actingAs($actor)
        ->post(route('assessments.restore', $assessment->uuid))
        ->assertForbidden();

    expect($assessment->fresh()->trashed())->toBeTrue();
})->with('non-restorers');

test('a guest is sent to sign in, and nothing is restored', function () {
    [$assessment] = deletedAssessmentWith('guest');

    $this->post(route('assessments.restore', $assessment->uuid))->assertRedirect(route('login'));

    expect($assessment->fresh()->trashed())->toBeTrue();
});

test('restoring a live assessment answers 404', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->post(route('assessments.restore', $assessment->uuid))
        ->assertNotFound();
});

test('restoring brings the assessment back with its content', function () {
    [$assessment, $owner] = deletedAssessmentWith('owner');
    $title = $assessment->title;

    $this->actingAs($owner)->post(route('assessments.restore', $assessment->uuid));

    $this->actingAs($owner)
        ->get(route('assessments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('assessments.0.uuid', $assessment->uuid)
            ->where('assessments.0.title', $title)
            ->where('deleted', []));
});

// --- erase ---------------------------------------------------------------

dataset('erasers', ['owner', 'admin']);
dataset('non-erasers', ['manager', 'editor', 'commenter', 'viewer', 'stranger']);

test('erase is allowed to the owner and an admin', function (string $role) {
    [$assessment, $actor] = deletedAssessmentWith($role);

    $this->actingAs($actor)
        ->delete(route('assessments.erase', $assessment->uuid))
        ->assertRedirect(route('assessments.index', ['show' => 'deleted']));

    expect(Assessment::withTrashed()->whereKey($assessment->id)->exists())->toBeFalse();
    expect(DB::table('nodes')->where('assessment_id', $assessment->id)->count())->toBe(0);
})->with('erasers');

test('erase is forbidden to everyone else, a manager included', function (string $role) {
    [$assessment, $actor] = deletedAssessmentWith($role);

    $this->actingAs($actor)
        ->delete(route('assessments.erase', $assessment->uuid))
        ->assertForbidden();

    expect(Assessment::withTrashed()->whereKey($assessment->id)->exists())->toBeTrue();
})->with('non-erasers');

test('a guest cannot erase', function () {
    [$assessment] = deletedAssessmentWith('guest');

    $this->delete(route('assessments.erase', $assessment->uuid))->assertRedirect(route('login'));

    expect(Assessment::withTrashed()->whereKey($assessment->id)->exists())->toBeTrue();
});

test('a live assessment cannot be erased in one step', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->delete(route('assessments.erase', $assessment->uuid))
        ->assertNotFound();

    expect(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

test('erasing an assessment with its own scales works through the route', function () {
    [$assessment, $owner] = deletedAssessmentWith('owner');
    $scale = Scale::factory()->forAssessment($assessment->id)->create();
    ScaleLevel::factory()->create(['scale_id' => $scale->id]);
    DB::table('assessments')->where('id', $assessment->id)->update(['impact_scale_id' => $scale->id]);

    $this->actingAs($owner)
        ->delete(route('assessments.erase', $assessment->uuid))
        ->assertRedirect();

    expect(Scale::query()->whereKey($scale->id)->exists())->toBeFalse();
    expect(Assessment::withTrashed()->whereKey($assessment->id)->exists())->toBeFalse();
});

// --- the Deleted list ----------------------------------------------------

test('the Deleted list holds what the caller could restore, and nothing else', function () {
    $user = User::factory()->create();

    $own = Assessment::factory()->create(['owner_user_id' => $user->id]);
    $managed = Assessment::factory()->create();
    retentionMember($managed, $user, 'manager');
    $edited = Assessment::factory()->create();
    retentionMember($edited, $user, 'editor');
    $stranger = Assessment::factory()->create();
    $live = Assessment::factory()->create(['owner_user_id' => $user->id]);

    foreach ([$own, $managed, $edited, $stranger] as $assessment) {
        $assessment->delete();
    }

    $this->actingAs($user)
        ->get(route('assessments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Assessment/Index')
            ->has('deleted', 2)
            ->where('deleted', fn ($rows) => collect($rows)->pluck('uuid')->sort()->values()->all()
                === collect([$own->uuid, $managed->uuid])->sort()->values()->all())
            ->has('assessments', 1)
            ->where('assessments.0.uuid', $live->uuid));
});

test('each Deleted row says whether the caller may erase it, and when the purge will', function () {
    config(['arguesecure.trash_retention_days' => 12]);
    $user = User::factory()->create();

    $own = Assessment::factory()->create(['owner_user_id' => $user->id]);
    $managed = Assessment::factory()->create();
    retentionMember($managed, $user, 'manager');
    $own->delete();
    $managed->delete();

    $deletedAt = $own->fresh()->deleted_at;

    $this->actingAs($user)
        ->get(route('assessments.index'))
        ->assertInertia(function (Assert $page) use ($own, $managed, $deletedAt) {
            $rows = collect($page->toArray()['props']['deleted'])->keyBy('uuid');

            expect($rows[$own->uuid]['can_erase'])->toBeTrue();
            expect($rows[$own->uuid]['is_owner'])->toBeTrue();
            expect($rows[$own->uuid]['erase_after'])->toBe($deletedAt->copy()->addDays(12)->toJSON());
            expect($rows[$managed->uuid]['can_erase'])->toBeFalse();
            expect($rows[$managed->uuid]['is_owner'])->toBeFalse();
            expect($rows[$managed->uuid]['owner_name'])->toBe($managed->owner->name);

            $page->where('retentionDays', 12)->where('show', 'active');
        });
});

test('an admin sees every deleted assessment', function () {
    $admin = User::factory()->admin()->create();
    $a = Assessment::factory()->create();
    $b = Assessment::factory()->create();
    $a->delete();
    $b->delete();

    $this->actingAs($admin)
        ->get(route('assessments.index', ['show' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('deleted', 2)
            ->where('show', 'deleted'));
});

test('only "deleted" opens the Deleted tab', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('assessments.index', ['show' => 'anything']))
        ->assertInertia(fn (Assert $page) => $page->where('show', 'active'));
});
