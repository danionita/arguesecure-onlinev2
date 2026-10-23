<?php

/**
 * Restoring a frozen version (W61).
 *
 * The route W48 pinned as missing. Its own pin —
 * tests/Feature/History/RevisionCoverageTest.php's "there is no snapshot
 * restore route for a revert to go through" — is flipped in that file rather
 * than deleted, so the reasoning survives.
 *
 * What is asserted here is deliberately not "the restore ran": it is the four
 * things that make a wholesale replacement safe to offer at all — that
 * everything is undoable, that nothing is destroyed outright, that the
 * timeline reads as one entry, and that the two ways a restore is *incomplete*
 * are counted and reported rather than absorbed.
 */

use App\Domain\History\TimelineReader;
use App\Domain\History\UndoService;
use App\Domain\Snapshots\SnapshotGraphCodec;
use App\Models\Assessment;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Node;
use App\Models\NodeRevision;
use App\Models\Risk;
use App\Models\Snapshot;
use App\Models\User;

require_once __DIR__.'/Helpers.php';

// --- what a restore does -------------------------------------------------

test('a node added after the snapshot is removed, and softly', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    $later = Risk::factory()->for($assessment)->create(['title' => 'Added later']);

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    expect(Node::query()->whereKey($later->id)->exists())->toBeFalse()
        // Soft, so its comments and revisions go with it rather than being
        // orphaned, and so an operator can still find it.
        ->and(Node::withTrashed()->whereKey($later->id)->first()?->trashed())->toBeTrue();
});

test('a node deleted after the snapshot comes back as the same row', function () {
    [$assessment, $risk] = restorable();
    $snapshot = snapshotOf($assessment);

    $risk->delete();

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    $restored = Node::query()->where('uuid', $risk->uuid)->sole();

    // The same row, not a second one: `uuid` is the stable identity across
    // snapshots (schema.md §3.4), and inserting a duplicate would collide on
    // the unique index and orphan every comment on it.
    expect($restored->id)->toBe($risk->id)
        ->and(Node::withTrashed()->where('uuid', $risk->uuid)->count())->toBe(1);
});

test('an edited field goes back to what the snapshot froze', function () {
    [$assessment, $risk] = restorable();
    $risk->impact_mode = 'quantitative';
    $risk->impact_amount = 100000;
    $risk->save();

    $snapshot = snapshotOf($assessment);

    $risk->impact_amount = 999999;
    $risk->title = 'Renamed since';
    $risk->save();

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    $fresh = $risk->fresh();

    expect((float) $fresh->impact_amount)->toBe(100000.0)
        ->and($fresh->title)->toBe('Cardholder data breach');
});

test('a link strength goes back, and a link added since is gone', function () {
    [$assessment, , $attack, $defence] = restorable();
    $snapshot = snapshotOf($assessment);

    $second = Defence::factory()->for($assessment)->create(['title' => 'Added later']);
    $attack->defences()->attach($second->id, ['position' => 1]);

    AttackDefence::query()
        ->where('attack_node_id', $attack->id)
        ->where('defence_node_id', $defence->id)
        ->update(['strength_fraction' => 0.95]);

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    $links = AttackDefence::query()->where('attack_node_id', $attack->id)->get();

    // Edges are rebuilt wholesale rather than diffed: an edge carries no
    // identity beyond the pair it joins, so delete-and-reinsert is the only
    // reading that cannot leave one behind that the snapshot does not contain.
    expect($links)->toHaveCount(1)
        ->and((float) $links[0]->strength_fraction)->toBe(0.5);
});

test('the amortisation period is restored with the graph', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    $assessment->amortization_years = 9;
    $assessment->save();

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    // The divisor of every capital cost in the report. A graph restored beside
    // today's divisor would produce figures matching neither version.
    expect((int) $assessment->fresh()->amortization_years)->toBe(3);
});

// --- what makes it safe --------------------------------------------------

test('the state before a restore is saved first, automatically', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    Risk::factory()->for($assessment)->create(['title' => 'About to be lost']);

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    $safety = $assessment->snapshots()->latest('id')->first();

    expect($safety->name)->toBe('Before restoring Sent to client');

    // And it really holds the state that was about to go, not a copy of what
    // was restored — which is the only property that makes it worth anything.
    $graph = app(SnapshotGraphCodec::class)->decode($safety->graph);
    $titles = array_column($graph['nodes'], 'title');

    expect($titles)->toContain('About to be lost');
});

test('a restore can be undone', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    $later = Risk::factory()->for($assessment)->create(['title' => 'Added later']);

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();
    expect(Node::query()->whereKey($later->id)->exists())->toBeFalse();

    app(UndoService::class)->undo($assessment->fresh(), $assessment->owner);

    // Back to where the user was before they pressed restore, by restoring the
    // safety snapshot — the inverse of a restore is another restore.
    expect(Node::query()->whereKey($later->id)->exists())->toBeTrue();
});

test('nothing is written when the restore fails partway', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    // A schema version this code cannot read: the one failure the restorer
    // raises after the safety snapshot has already been taken.
    $graph = app(SnapshotGraphCodec::class)->decode($snapshot->graph);
    $graph['schemaVersion'] = 99;
    $snapshot->graph = app(SnapshotGraphCodec::class)->encode($graph);
    $snapshot->save();

    $before = $assessment->snapshots()->count();

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    // The safety snapshot and the operation row are both rolled back. A safety
    // snapshot left behind would claim a restore had happened.
    expect($assessment->snapshots()->count())->toBe($before)
        ->and($assessment->operations()->where('kind', 'snapshot.restore')->count())->toBe(0);
});

// --- what it tells the reader -------------------------------------------

test('a restore is one timeline entry, not one per node', function () {
    [$assessment, $risk, $attack] = restorable();
    $snapshot = snapshotOf($assessment);

    $risk->title = 'Changed';
    $risk->save();
    $attack->title = 'Also changed';
    $attack->save();

    $reader = app(TimelineReader::class);
    $before = count(array_filter($reader->read($assessment)['entries'], fn (array $e): bool => $e['source'] === 'revision'));

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    $operation = $assessment->operations()->where('kind', 'snapshot.restore')->sole();

    $grouped = NodeRevision::query()
        ->where('assessment_id', $assessment->id)
        ->where('operation_id', $operation->id)
        ->count();

    // Every revision the restore wrote is stamped with the operation, which is
    // what TimelineReader folds into a single entry. Without it, restoring
    // fifty nodes would be fifty entries for one click.
    expect($grouped)->toBeGreaterThan(1);

    $after = count(array_filter($reader->read($assessment)['entries'], fn (array $e): bool => $e['source'] === 'revision'));

    // The two edits made before the restore stay two entries — they were two
    // separate acts. The restore itself adds exactly one, however many nodes
    // it touched.
    expect($after - $before)->toBe(1);
});

test('a node brought back from a v1 snapshot is reported as unlabelled', function () {
    [$assessment, $risk] = restorable();
    $snapshot = asVersion1(snapshotOf($assessment));

    $risk->delete();

    $response = restoreAs($assessment->owner, $assessment, $snapshot);

    // v1's shape holds no labels, assignees or comments (schema.md §3.13), so
    // a node it brings back comes back bare. Counted and said out loud rather
    // than silently absorbed — that is the difference between a known
    // limitation and a defect. W62 closed this for snapshots taken since, and
    // the v1 ones already written do not stop existing, so this stays.
    $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'without their labels'));
});

test('a value whose scale level no longer exists is left unset, not guessed', function () {
    [$assessment, $risk] = restorable();

    $level = $assessment->impactScale->levels()->orderBy('ordinal')->first();
    $risk->impact_mode = 'qualitative';
    // `chk_nodes_impact_mode` permits a level or an amount, never both.
    $risk->impact_amount = null;
    $risk->impact_level_id = $level->id;
    $risk->save();

    $snapshot = snapshotOf($assessment);

    // The row is gone by the time the restore runs. Clearing the reference
    // first is what `restrictOnDelete` requires and is what a real
    // recalibration would do.
    $risk->impact_level_id = null;
    $risk->save();
    $level->delete();

    $response = restoreAs($assessment->owner, $assessment, $snapshot);

    // The mode survives and the id does not: "qualitative, nothing chosen yet"
    // is a state the schema allows and the engine treats as missing. Coercing
    // it to whichever level holds that id today would invent a number.
    expect($risk->fresh()->impact_level_id)->toBeNull()
        ->and($risk->fresh()->impact_mode)->toBe('qualitative');

    $response->assertSessionHas('success', fn (string $m): bool => str_contains($m, 'no longer exists'));
});

// --- who may do it -------------------------------------------------------

test('a viewer cannot restore', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    $viewer = User::factory()->create();
    $assessment->members()->attach($viewer->id, [
        'role' => 'viewer',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    restoreAs($viewer, $assessment, $snapshot)->assertForbidden();
});

test('an editor can restore', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    // `update`, deliberately, and not `manage`: undo is `update`-gated and the
    // inverse of a restore is another restore, so gating this higher would be
    // a longer route to the same place rather than a guard
    // (SnapshotController::restore).
    restoreAs($editor, $assessment, $snapshot)->assertRedirect();
});

test('an anonymous caller is refused', function () {
    [$assessment] = restorable();
    $snapshot = snapshotOf($assessment);

    $this->post(route('assessments.snapshots.restore', [$assessment, $snapshot->id]))
        ->assertRedirect(route('login'));
});

test('a snapshot from another assessment is not found', function () {
    [$assessment] = restorable();
    [$other] = restorable();
    $foreign = snapshotOf($other);

    // The cross-assessment IDOR check every method in this controller
    // re-asserts, because `{snapshot}` is not route-scoped.
    restoreAs($assessment->owner, $assessment, $foreign)->assertNotFound();
});

test('the restore route carries the authenticated rate limit', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->getName() === 'assessments.snapshots.restore');

    // The specified value, not "it has a throttle": an enclosing group's
    // looser limit would satisfy a presence check and leave the hole open
    // (CLAUDE.md's rule about presence assertions).
    expect($route->gatherMiddleware())->toContain('throttle:authenticated');
});
