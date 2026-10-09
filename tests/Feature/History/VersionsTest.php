<?php

use App\Domain\History\VersionTimeline;
use App\Domain\Snapshots\AutoVersioner;
use App\Domain\Snapshots\SnapshotService;
use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 2.5.1: the History page is versions, with the changes that went into each.
 *
 * Through 2.5 versions ("snapshots") were a separate list reachable only from
 * a footnote on this page, and existed only if somebody saved one by hand. Now
 * they are saved automatically and from here, and every change sits under the
 * version it went into — or under "Current version" if it is not in one yet.
 */
function versionsEditor(Assessment $assessment): User
{
    $user = User::factory()->create();
    $assessment->members()->attach($user->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $user;
}

function versionsOperation(Assessment $assessment, string $summary, string $at): AssessmentOperation
{
    return AssessmentOperation::create([
        'assessment_id' => $assessment->id,
        'user_id' => $assessment->owner_user_id,
        'kind' => 'node.create',
        'summary' => $summary,
        'inverse' => ['action' => 'noop'],
        'created_at' => $at,
    ]);
}

function versionsSnapshot(Assessment $assessment, string $name, string $at, string $kind = 'manual'): Snapshot
{
    Carbon::setTestNow($at);
    $snapshot = app(SnapshotService::class)->create($assessment, $name, null, $assessment->owner_user_id, $kind);
    Carbon::setTestNow();

    return $snapshot;
}

afterEach(fn () => Carbon::setTestNow());

test('each change sits under the version it went into, and unsaved ones under Current version', function () {
    $assessment = Assessment::factory()->create();

    versionsOperation($assessment, 'first', '2026-09-01 10:00:00');
    versionsSnapshot($assessment, 'Workshop one', '2026-09-01 11:00:00');
    versionsOperation($assessment, 'second', '2026-09-02 10:00:00');
    versionsOperation($assessment, 'third', '2026-09-02 10:30:00');
    versionsSnapshot($assessment, 'Workshop two', '2026-09-02 11:00:00');
    versionsOperation($assessment, 'fourth', '2026-09-03 10:00:00');

    $groups = app(VersionTimeline::class)->build($assessment);

    expect(array_column($groups, 'key'))->toHaveCount(3)
        ->and($groups[0]['key'])->toBe('current')
        ->and(array_column($groups[0]['entries'], 'summary'))->toBe(['fourth'])
        // Newest version first, numbered oldest-first.
        ->and($groups[1]['number'])->toBe(2)
        ->and($groups[1]['snapshot']['name'])->toBe('Workshop two')
        ->and(array_column($groups[1]['entries'], 'summary'))->toBe(['third', 'second'])
        ->and($groups[2]['number'])->toBe(1)
        ->and(array_column($groups[2]['entries'], 'summary'))->toBe(['first']);
});

test('a group sends its newest few unless it is the one expanded', function () {
    $assessment = Assessment::factory()->create();

    foreach (range(1, VersionTimeline::PER_GROUP + 4) as $i) {
        versionsOperation($assessment, "change {$i}", now()->subMinutes(100 - $i)->format('Y-m-d H:i:s'));
    }

    $folded = app(VersionTimeline::class)->build($assessment)[0];
    $expanded = app(VersionTimeline::class)->build($assessment, [], 'current')[0];

    expect($folded['entries'])->toHaveCount(VersionTimeline::PER_GROUP)
        ->and($folded['total'])->toBe(VersionTimeline::PER_GROUP + 4)
        ->and($expanded['entries'])->toHaveCount(VersionTimeline::PER_GROUP + 4);
});

test('the page renders the versions to anyone who can view the assessment', function () {
    $assessment = Assessment::factory()->create();
    versionsSnapshot($assessment, 'Baseline', '2026-09-01 11:00:00');

    $this->actingAs($assessment->owner)
        ->get(route('assessments.history.index', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Assessment/History')
            ->where('versions.0.key', 'current')
            ->where('versions.1.snapshot.name', 'Baseline')
            ->where('versions.1.snapshot.kind', 'manual')
            ->where('abilities.canSave', true)
        );
});

test('an editor saves the current version by hand, from the History page', function () {
    $assessment = Assessment::factory()->create();
    $editor = versionsEditor($assessment);
    versionsOperation($assessment, 'added a risk', now()->subMinute()->format('Y-m-d H:i:s'));

    $this->actingAs($editor)
        ->post(route('assessments.history.versions.store', $assessment), ['name' => 'Sent to client'])
        ->assertRedirect(route('assessments.history.index', $assessment));

    $snapshot = $assessment->snapshots()->first();
    expect($snapshot->name)->toBe('Sent to client')
        ->and($snapshot->kind)->toBe('manual')
        ->and($snapshot->created_by)->toBe($editor->id);

    // What was under Current version is now in the saved one.
    $groups = app(VersionTimeline::class)->build($assessment);
    expect($groups[0]['total'])->toBe(0)
        ->and(array_column($groups[1]['entries'], 'summary'))->toBe(['added a risk']);
});

test('the name is optional and defaults to who saved it', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->post(route('assessments.history.versions.store', $assessment), [])
        ->assertRedirect();

    expect($assessment->snapshots()->first()->name)->toBe('Saved by '.$assessment->owner->name);
});

test('a viewer cannot save a version, and an anonymous caller is sent to log in', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    $assessment->members()->attach($viewer->id, [
        'role' => 'viewer',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->post(route('assessments.history.versions.store', $assessment))
        ->assertForbidden();

    auth()->logout();
    $this->post(route('assessments.history.versions.store', $assessment))
        ->assertRedirect(route('login'));

    expect($assessment->snapshots()->count())->toBe(0);
});

test('the old snapshot list sends people to the History page', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.snapshots.index', $assessment))
        ->assertRedirect(route('assessments.history.index', $assessment));
});

// --- saved automatically ---------------------------------------------------

test('a version is saved automatically once editing has paused', function () {
    $assessment = Assessment::factory()->create();
    versionsOperation($assessment, 'edit', '2026-09-01 10:00:00');

    $versioner = app(AutoVersioner::class);

    // Still inside the pause: nothing yet.
    expect($versioner->run(Carbon::parse('2026-09-01 10:10:00')))->toBe(0);

    $saved = $versioner->run(Carbon::parse('2026-09-01 10:00:00')->addMinutes(AutoVersioner::IDLE_MINUTES));

    $snapshot = $assessment->snapshots()->first();
    expect($saved)->toBe(1)
        ->and($snapshot->kind)->toBe('auto')
        ->and($snapshot->created_by)->toBeNull()
        ->and($snapshot->name)->toContain('pause in editing');
});

test('a version is saved automatically after a run of changes, even mid-session', function () {
    $assessment = Assessment::factory()->create();
    $now = Carbon::parse('2026-09-01 12:00:00');

    foreach (range(1, AutoVersioner::MAX_CHANGES) as $i) {
        versionsOperation($assessment, "edit {$i}", $now->copy()->subSeconds($i)->format('Y-m-d H:i:s'));
    }

    expect(app(AutoVersioner::class)->run($now))->toBe(1)
        ->and($assessment->snapshots()->first()->name)->toContain(AutoVersioner::MAX_CHANGES.' changes');
});

test('nothing is saved for an assessment with no changes since its last version', function () {
    $assessment = Assessment::factory()->create();
    versionsOperation($assessment, 'edit', '2026-09-01 10:00:00');
    versionsSnapshot($assessment, 'Saved', '2026-09-01 10:05:00');

    expect(app(AutoVersioner::class)->run(Carbon::parse('2026-09-02 10:00:00')))->toBe(0)
        ->and($assessment->snapshots()->count())->toBe(1);
});

test('nothing is saved for an assessment nobody has ever changed', function () {
    Assessment::factory()->create();

    expect(app(AutoVersioner::class)->run(Carbon::parse('2030-01-01 00:00:00')))->toBe(0)
        ->and(Snapshot::query()->count())->toBe(0);
});

test('the scheduler runs it every five minutes', function () {
    $this->artisan('arguesecure:save-versions')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'arguesecure:save-versions'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});
