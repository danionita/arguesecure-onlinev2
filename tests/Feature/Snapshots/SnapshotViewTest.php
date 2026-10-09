<?php

/**
 * Browsing a frozen version in the editor's own views (W63).
 *
 * The route serves `Assessment/Show`'s prop shape, built from `snapshots.graph`
 * instead of from the database, so the live `ChartView` and `TreeNode` can be
 * mounted against it. Three things have to hold for that to be safe rather than
 * merely working:
 *
 * 1. **The shape does not drift.** If `AssessmentController::nodeToArray()`
 *    gains a field and the presenter does not, the frozen view renders a node
 *    missing it — subtly, and only on the page nobody looks at until something
 *    has gone wrong. The two key sets are compared directly here.
 * 2. **It reads the snapshot, not the assessment.** Every value on the page has
 *    to come from the frozen graph. A page that silently mixed today's title
 *    into an old version would be worse than no page.
 * 3. **It is exactly as visible as the snapshot it renders**, and no more. It
 *    decodes the same graph `show` already serves, so gating it differently
 *    would be theatre in one direction and a leak in the other.
 */

use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;

require_once __DIR__.'/Helpers.php';

/** @return array<string, mixed> the Inertia page props for the view route */
function viewProps(User $actor, Assessment $assessment, int $snapshotId): array
{
    $response = test()->actingAs($actor)
        ->get(route('assessments.snapshots.view', [$assessment, $snapshotId]));

    $response->assertOk();

    return $response->viewData('page')['props'];
}

// --- the shape ------------------------------------------------------------

test('the frozen page serves the same node shape the live editor does', function () {
    [$assessment, $risk] = restorable();
    labelled($assessment, $risk, 'PCI scope');
    $snapshot = snapshotOf($assessment);

    $frozen = viewProps($assessment->owner, $assessment, $snapshot->id);
    $live = test()->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->viewData('page')['props'];

    $frozenNode = collect($frozen['nodes'])->firstWhere('uuid', $risk->uuid);
    $liveNode = collect($live['nodes'])->firstWhere('uuid', $risk->uuid);

    // The drift guard, and the reason this test is first. The frozen page
    // mounts the live editor's own components, so a field the presenter forgets
    // is a field those components silently render as absent — on the one page
    // someone opens when they already suspect something is wrong.
    expect(array_keys($frozenNode))->toEqualCanonicalizing(array_keys($liveNode));
})->group('snapshots');

test('the frozen page carries everything the store needs to render', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    $props = viewProps($assessment->owner, $assessment, $snapshot->id);

    // `scales` and `scaleLevels` are what makes a qualitative value resolve to
    // a number; without them the store cannot lay out a card at all.
    expect($props)->toHaveKeys([
        'assessment', 'nodes', 'riskAttackLinks', 'attackDefenceLinks',
        'scales', 'scaleLevels', 'tags', 'results', 'snapshot', 'canRestore',
    ])->and($props['scaleLevels'])->not->toBeEmpty();
})->group('snapshots');

// --- it reads the snapshot, not the assessment ---------------------------

test('the page shows the frozen title, not today’s', function () {
    [$assessment, $risk] = restorable();
    $snapshot = snapshotOf($assessment);

    $risk->title = 'Renamed since';
    $risk->save();

    $props = viewProps($assessment->owner, $assessment, $snapshot->id);

    expect(collect($props['nodes'])->firstWhere('uuid', $risk->uuid)['title'])
        ->toBe('Cardholder data breach');
})->group('snapshots');

test('a node added since the snapshot is not on the page', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    $later = Risk::factory()->for($assessment)->create(['title' => 'Added later']);

    $props = viewProps($assessment->owner, $assessment, $snapshot->id);

    expect(collect($props['nodes'])->pluck('uuid'))->not->toContain($later->uuid);
})->group('snapshots');

test('the page shows the frozen currency and amortisation period', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    $assessment->currency = 'USD';
    $assessment->amortization_years = 9;
    $assessment->save();

    $props = viewProps($assessment->owner, $assessment, $snapshot->id);

    // `amortization_years` divides every capital cost. A frozen graph laid out
    // beside today's divisor produces figures matching neither version — the
    // same reason a restore puts these back.
    expect($props['assessment']['amortization_years'])->toBe(3)
        ->and($props['assessment']['currency'])->not->toBe('USD');
})->group('snapshots');

test('the figures are the frozen ones and are never recomputed', function () {
    [$assessment, $risk, $attack] = restorable();

    $risk->impact_mode = 'quantitative';
    $risk->impact_amount = 100000;
    $risk->impact_level_id = null;
    $risk->save();
    $attack->likelihood_mode = 'quantitative';
    $attack->likelihood_aro = 2;
    $attack->likelihood_level_id = null;
    $attack->save();

    $snapshot = snapshotOf($assessment);
    $frozenTotal = $snapshot->results['inherent']['total'];

    $risk->impact_amount = 999999;
    $risk->save();

    $props = viewProps($assessment->owner, $assessment, $snapshot->id);

    // A snapshot is frozen output — that is the entire reason the feature
    // exists. Recomputing it against today's engine would produce a page
    // claiming to be a version of the past and showing a number nobody saw.
    expect($props['results']['inherent']['total'])->toBe($frozenTotal);
})->group('snapshots');

test('labels come back on the frozen graph with ids that are only display keys', function () {
    [$assessment, $risk] = restorable();
    $live = labelled($assessment, $risk, 'PCI scope');
    $snapshot = snapshotOf($assessment);

    $props = viewProps($assessment->owner, $assessment, $snapshot->id);
    $tags = collect($props['nodes'])->firstWhere('uuid', $risk->uuid)['tags'];

    expect($tags)->toHaveCount(1)
        ->and($tags[0]['name'])->toBe('PCI scope')
        ->and($tags[0]['slug'])->toBe('pci-scope');

    // Minted for the render, and deliberately not the live row's id: a snapshot
    // stores labels by value and keeps no `tags.id` (schema.md §3.13). Pinned
    // because the number *looks* like a real id — the page is safe only because
    // nothing on it writes, and a future reader should find that stated rather
    // than infer it from a value that happens to match.
    expect($tags[0]['id'])->toBe(1)
        ->and($live->id)->not->toBe(1);
})->group('snapshots');

test('a v1 snapshot renders, with no labels rather than an error', function () {
    [$assessment, $risk] = restorable();
    labelled($assessment, $risk, 'PCI scope');
    $snapshot = asVersion1(snapshotOf($assessment));

    $props = viewProps($assessment->owner, $assessment, $snapshot->id);

    // The snapshots most likely to be worth reading are the oldest ones. A
    // viewer that only handled the newest shape would be useless exactly when
    // it is needed.
    expect(collect($props['nodes'])->firstWhere('uuid', $risk->uuid)['tags'])->toBe([])
        ->and($props['tags'])->toBe([]);
})->group('snapshots');

// --- who can reach it -----------------------------------------------------

test('a viewer can browse a frozen version', function () {
    [$assessment] = restorable();
    $viewer = User::factory()->create();
    $assessment->members()->attach($viewer->id, [
        'role' => 'viewer', 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    $snapshot = snapshotOf($assessment);

    // `view`, matching `show` and `index`. This route decodes the same graph
    // `show` already serves, so gating it higher would lock the door of an open
    // room while telling the reader they cannot see something they can.
    test()->actingAs($viewer)
        ->get(route('assessments.snapshots.view', [$assessment, $snapshot->id]))
        ->assertOk();
})->group('snapshots');

test('a viewer is not offered the restore button', function () {
    [$assessment] = restorable();
    $viewer = User::factory()->create();
    $assessment->members()->attach($viewer->id, [
        'role' => 'viewer', 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    $props = viewProps($viewer, $assessment, snapshotOf($assessment)->id);

    expect($props['canRestore'])->toBeFalse();
})->group('snapshots');

test('a stranger cannot browse a frozen version', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    test()->actingAs(User::factory()->create())
        ->get(route('assessments.snapshots.view', [$assessment, $snapshot->id]))
        ->assertForbidden();
})->group('snapshots');

test('a snapshot from another assessment is not found', function () {
    [$assessment] = restorable();
    [$other] = restorable();
    $foreign = snapshotOf($other, 'Someone else’s');

    // The cross-assessment IDOR check every method on this controller repeats,
    // because `{snapshot}` binds by id and is not scoped to `{assessment}`.
    test()->actingAs($assessment->owner)
        ->get(route('assessments.snapshots.view', [$assessment, $foreign->id]))
        ->assertNotFound();
})->group('snapshots');

test('the frozen view never serves an assignee’s email address', function () {
    [$assessment, $risk] = restorable();
    $user = assignedTo($assessment, $risk);

    $props = viewProps($assessment->owner, $assessment, snapshotOf($assessment)->id);

    // `view` is reachable by a share-token reader with no account, so anything
    // in these props is published to that audience. Asserted over the whole
    // prop bag rather than the node, so a future key carrying one trips it.
    expect(json_encode($props))->not->toContain($user->email);
})->group('snapshots');
