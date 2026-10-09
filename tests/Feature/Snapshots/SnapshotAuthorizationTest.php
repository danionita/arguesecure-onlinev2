<?php

use App\Domain\Sharing\ShareTokenService;
use App\Domain\Snapshots\SnapshotService;
use App\Models\Assessment;
use App\Models\Snapshot;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Every route touching an assessment goes through AssessmentPolicy, no
 * exceptions (CLAUDE.md). Per the W19 task brief: creating and deleting a
 * snapshot need `update`; listing, viewing and diffing need only `view` —
 * the same broad audience (owner, any collaborator role, admin, a public
 * assessment, a valid share-link session) ReportController's read routes
 * already establish. Every case below proves a stranger with no share gets
 * 403 on all five routes, the class of defect security.md calls out as
 * legacy defect #4.
 */
function snapshotMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

function makeSnapshot(Assessment $assessment, ?int $userId = null): Snapshot
{
    return app(SnapshotService::class)->create($assessment, 'Baseline', null, $userId);
}

// --- index --------------------------------------------------------------

test('an anonymous user is rejected from the snapshot list of a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);

    $this->get(route('assessments.snapshots.index', $assessment))->assertForbidden();
});

test('a logged-in stranger with no share is rejected from the snapshot list', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('assessments.snapshots.index', $assessment))
        ->assertForbidden();
});

test('the owner is sent from the old snapshot list to the History page (2.5.1)', function () {
    $assessment = Assessment::factory()->create();
    makeSnapshot($assessment, $assessment->owner_user_id);

    $this->actingAs($assessment->owner)
        ->get(route('assessments.snapshots.index', $assessment))
        ->assertRedirect(route('assessments.history.index', $assessment));
});

/*
 * The list moved onto the History page, which needs an account. A guest who
 * can read a public assessment — or holds a share link — still reads each
 * version itself (show/view/diff, below), which is where the content is; the
 * redirect is still gated on `view`, so it confirms nothing to a stranger.
 */
test('a public assessment snapshot list redirects an anonymous guest to the history page', function () {
    $assessment = Assessment::factory()->public()->create();

    $this->get(route('assessments.snapshots.index', $assessment))
        ->assertRedirect(route('assessments.history.index', $assessment));
});

// --- store ----------------------------------------------------------------

test('an anonymous user is rejected from creating a snapshot', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);

    $this->post(route('assessments.snapshots.store', $assessment), ['name' => 'X'])
        ->assertRedirect(route('login'));

    expect(Snapshot::query()->count())->toBe(0);
});

test('a logged-in stranger with no share is rejected from creating a snapshot', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post(route('assessments.snapshots.store', $assessment), ['name' => 'X'])
        ->assertForbidden();

    expect(Snapshot::query()->count())->toBe(0);
});

test('a viewer collaborator cannot create a snapshot', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    snapshotMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->post(route('assessments.snapshots.store', $assessment), ['name' => 'X'])
        ->assertForbidden();
});

test('an editor collaborator can create a snapshot', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    snapshotMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->post(route('assessments.snapshots.store', $assessment), ['name' => 'Q3 review'])
        ->assertRedirect();

    expect(Snapshot::query()->where('assessment_id', $assessment->id)->count())->toBe(1);
});

test('the owner can create a snapshot', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->post(route('assessments.snapshots.store', $assessment), ['name' => 'Sent to client'])
        ->assertRedirect();

    expect(Snapshot::query()->where('assessment_id', $assessment->id)->count())->toBe(1);
});

test('an admin can create a snapshot on any assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post(route('assessments.snapshots.store', $assessment), ['name' => 'Admin snapshot'])
        ->assertRedirect();
});

// --- show -------------------------------------------------------------

test('an anonymous user is rejected from viewing a snapshot of a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $snapshot = makeSnapshot($assessment, $assessment->owner_user_id);

    $this->get(route('assessments.snapshots.show', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertForbidden();
});

test('a logged-in stranger with no share is rejected from viewing a snapshot', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $snapshot = makeSnapshot($assessment, $assessment->owner_user_id);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('assessments.snapshots.show', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertForbidden();
});

test('the owner can view a snapshot', function () {
    $assessment = Assessment::factory()->create();
    $snapshot = makeSnapshot($assessment, $assessment->owner_user_id);

    $this->actingAs($assessment->owner)
        ->get(route('assessments.snapshots.show', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Snapshots/Show'));
});

test('a snapshot belonging to a different assessment 404s rather than leaking through the route', function () {
    $assessmentA = Assessment::factory()->create();
    $assessmentB = Assessment::factory()->create();
    $snapshotOfA = makeSnapshot($assessmentA, $assessmentA->owner_user_id);

    $this->actingAs($assessmentB->owner)
        ->get(route('assessments.snapshots.show', ['assessment' => $assessmentB, 'snapshot' => $snapshotOfA]))
        ->assertNotFound();
});

// --- diff -------------------------------------------------------------

test('a logged-in stranger with no share is rejected from diffing snapshots', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $s1 = makeSnapshot($assessment, $assessment->owner_user_id);
    $s2 = makeSnapshot($assessment, $assessment->owner_user_id);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('assessments.snapshots.diff', $assessment).'?from='.$s1->id.'&to='.$s2->id)
        ->assertForbidden();
});

test('the owner can diff two snapshots', function () {
    $assessment = Assessment::factory()->create();
    $s1 = makeSnapshot($assessment, $assessment->owner_user_id);
    $s2 = makeSnapshot($assessment, $assessment->owner_user_id);

    $this->actingAs($assessment->owner)
        ->get(route('assessments.snapshots.diff', $assessment).'?from='.$s1->id.'&to='.$s2->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Snapshots/Diff'));
});

// --- destroy ------------------------------------------------------------

test('an anonymous user is rejected from deleting a snapshot', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $snapshot = makeSnapshot($assessment, $assessment->owner_user_id);

    $this->delete(route('assessments.snapshots.destroy', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertRedirect(route('login'));

    expect(Snapshot::query()->find($snapshot->id))->not->toBeNull();
});

test('a logged-in stranger with no share is rejected from deleting a snapshot', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $snapshot = makeSnapshot($assessment, $assessment->owner_user_id);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->delete(route('assessments.snapshots.destroy', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertForbidden();

    expect(Snapshot::query()->find($snapshot->id))->not->toBeNull();
});

test('a viewer collaborator cannot delete a snapshot', function () {
    $assessment = Assessment::factory()->create();
    $snapshot = makeSnapshot($assessment, $assessment->owner_user_id);
    $viewer = User::factory()->create();
    snapshotMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->delete(route('assessments.snapshots.destroy', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertForbidden();

    expect(Snapshot::query()->find($snapshot->id))->not->toBeNull();
});

test('the owner can delete a snapshot', function () {
    $assessment = Assessment::factory()->create();
    $snapshot = makeSnapshot($assessment, $assessment->owner_user_id);

    $this->actingAs($assessment->owner)
        ->delete(route('assessments.snapshots.destroy', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertRedirect();

    expect(Snapshot::query()->find($snapshot->id))->toBeNull();
});

test('deleting a snapshot belonging to a different assessment 404s', function () {
    $assessmentA = Assessment::factory()->create();
    $assessmentB = Assessment::factory()->create();
    $snapshotOfA = makeSnapshot($assessmentA, $assessmentA->owner_user_id);

    $this->actingAs($assessmentB->owner)
        ->delete(route('assessments.snapshots.destroy', ['assessment' => $assessmentB, 'snapshot' => $snapshotOfA]))
        ->assertNotFound();

    expect(Snapshot::query()->find($snapshotOfA->id))->not->toBeNull();
});

// --- payload hygiene ------------------------------------------------------

test('no snapshot surface exposes the creator email to a public reader', function () {
    $assessment = Assessment::factory()->public()->create();
    $snapshot = makeSnapshot($assessment, $assessment->owner_user_id);
    $email = $assessment->owner->email;

    $this->get(route('assessments.snapshots.show', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertOk()
        ->assertDontSee($email, escape: false);
});

test('no snapshot surface exposes the assessment share token to a public reader', function () {
    $assessment = Assessment::factory()->public()->create();
    $snapshot = makeSnapshot($assessment, $assessment->owner_user_id);
    $token = ShareTokenService::generate($assessment);

    $this->get(route('assessments.snapshots.show', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertOk()
        ->assertDontSee($token, escape: false);
});
