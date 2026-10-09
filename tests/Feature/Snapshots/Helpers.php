<?php

/**
 * Fixtures shared by the snapshot feature tests.
 *
 * Not named `*Test.php`, so Pest does not collect it as a suite, and pulled in
 * with `require_once` from each file that needs it.
 *
 * It exists because the alternative bites. Pest declares helpers as plain
 * global functions, and a helper defined in one test file is visible to
 * another **only when both files happen to be loaded** — so
 * `SnapshotLabelsTest` borrowing `restorable()` from `SnapshotRestoreTest`
 * passes for the whole directory and dies with "call to undefined function"
 * the moment someone runs the one file they are working on. That is a trap
 * laid for whoever is debugging in a hurry, and moving the shared ones here
 * is the only fix that does not rely on load order.
 */

use App\Domain\Snapshots\SnapshotGraphCodec;
use App\Domain\Snapshots\SnapshotService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\Snapshot;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A small assessment with one of everything and both edges wired up.
 *
 * @return array{Assessment, Risk, Attack, Defence}
 */
function restorable(): array
{
    $assessment = Assessment::factory()->create(['amortization_years' => 3]);
    $risk = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $attack = Attack::factory()->for($assessment)->create(['title' => 'Phishing']);
    $defence = Defence::factory()->for($assessment)->create(['title' => 'MFA']);

    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $attack->defences()->attach($defence->id, [
        'position' => 0,
        'strength_mode' => 'quantitative',
        'strength_fraction' => 0.5,
    ]);

    return [$assessment, $risk, $attack, $defence];
}

function snapshotOf(Assessment $assessment, string $name = 'Sent to client'): Snapshot
{
    return app(SnapshotService::class)->create($assessment, $name, null, $assessment->owner_user_id);
}

function restoreAs(User $actor, Assessment $assessment, Snapshot $snapshot): TestResponse
{
    return test()->actingAs($actor)
        ->post(route('assessments.snapshots.restore', [$assessment, $snapshot->id]));
}

/** The decoded graph of a snapshot, for reading what was actually frozen. */
function frozenGraph(Snapshot $snapshot): array
{
    return app(SnapshotGraphCodec::class)->decode($snapshot->graph);
}

/**
 * Rewrites a snapshot into the shape W19 wrote and W62 replaced.
 *
 * Built by taking a real one apart rather than by pasting a fixture: a
 * hand-written blob would drift from what the encoder actually produces, and
 * then the v1 tests would be asserting against a shape nothing has ever
 * written. Old snapshots are the thing being protected here, so the fake ones
 * have to be made the way the real ones were.
 */
function asVersion1(Snapshot $snapshot): Snapshot
{
    $codec = app(SnapshotGraphCodec::class);
    $graph = $codec->decode($snapshot->graph);

    $graph['schemaVersion'] = 1;

    foreach (array_keys($graph['nodes']) as $uuid) {
        unset($graph['nodes'][$uuid]['tags'], $graph['nodes'][$uuid]['assignees']);
    }

    $snapshot->graph = $codec->encode($graph);
    $snapshot->schema_version = 1;
    $snapshot->save();

    return $snapshot;
}

/**
 * A label attached to one node.
 *
 * `assessment_id` as a property, never through create(): it is an identity
 * column and is deliberately absent from Tag::$fillable (CLAUDE.md), and a
 * fixture that mass-assigned it would fail the same silent way the production
 * code is written to avoid.
 */
function labelled(Assessment $assessment, Node $node, string $name): Tag
{
    $tag = new Tag(['name' => $name, 'slug' => Str::slug($name), 'color' => '#3B82F6']);
    $tag->assessment_id = $assessment->id;
    $tag->save();

    $node->tags()->syncWithoutDetaching([$tag->id]);

    return $tag;
}

/** A collaborator on the assessment, assigned to one node. */
function assignedTo(Assessment $assessment, Node $node, string $role = 'editor'): User
{
    $user = User::factory()->create();
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $node->assignees()->syncWithoutDetaching([$user->id]);

    return $user;
}
