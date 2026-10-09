<?php

/**
 * Labels and assignees inside a snapshot (W62, `snapshots.graph` v2).
 *
 * W61 shipped a restore with a known hole in it: a snapshot recorded no labels
 * and no assignees, so a node a restore *brought back* came back bare. That was
 * counted and reported rather than hidden, which made it a stated limitation
 * instead of a defect — but it was still the only reason a restore was ever
 * incomplete, and it was the one thing between "restore" and "restore".
 *
 * This file holds the three things that have to stay true now that both are
 * frozen:
 *
 * 1. **Both formats still work.** A v1 snapshot restores and says what it
 *    cannot bring back; a v2 one brings it back. Nothing rewrites an existing
 *    snapshot — a frozen version that changed shape after the fact would not be
 *    frozen — so v1 is not a migration step, it is a shape this code reads
 *    forever.
 * 2. **A restore replaces, it does not merge.** A label added since the
 *    snapshot comes off. This is the half that surprises people, so it is
 *    asserted directly rather than implied by a round-trip.
 * 3. **An assignment is re-checked, never just re-attached.** AssessmentUser's
 *    `deleted` hook removes assignments when someone loses access. Restoring a
 *    frozen assignment without re-checking would put every one of them back in
 *    a single click, which is not a smaller version of that guard — it is a
 *    way around it.
 */

use App\Domain\Snapshots\SnapshotGraphCodec;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\Risk;
use App\Models\Snapshot;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

// --- what a snapshot now records -----------------------------------------

test('a snapshot records a node’s labels by value, not by id', function () {
    [$assessment, $risk] = restorable();
    $tag = labelled($assessment, $risk, 'PCI scope');

    $graph = frozenGraph(snapshotOf($assessment));

    expect($graph['schemaVersion'])->toBe(2)
        ->and($graph['nodes'][$risk->uuid]['tags'])->toBe([
            ['name' => 'PCI scope', 'slug' => 'pci-scope', 'color' => '#3B82F6'],
        ]);

    // By value is the whole point: renaming the live tag must not reach into
    // the frozen version. There is no id left in there to follow.
    $tag->name = 'Out of scope';
    $tag->save();

    expect(frozenGraph(Snapshot::query()->latest('id')->sole())['nodes'][$risk->uuid]['tags'][0]['name'])
        ->toBe('PCI scope');
})->group('snapshots');

test('a snapshot records who was assigned, and never their email address', function () {
    [$assessment, $risk] = restorable();
    $user = assignedTo($assessment, $risk);

    $graph = frozenGraph(snapshotOf($assessment));

    expect($graph['nodes'][$risk->uuid]['assignees'])
        ->toBe([['id' => $user->id, 'name' => $user->name]]);

    // A snapshot is readable by anyone who can `view` the assessment — which a
    // share token grants with no account at all — so an email in here is
    // published to that audience and stays published, frozen, for as long as
    // the snapshot exists.
    //
    // What this pins is SnapshotService::assigneesOf(), the serializer, which
    // is where the two keys are chosen. The `assignees:id,name` narrowing on
    // the query beside it looks like the guard and is not: removing it leaves
    // this green. Asserted over the whole encoded graph rather than the one
    // node, so a future key carrying an email anywhere in the shape trips it.
    expect(json_encode($graph))->not->toContain($user->email);
})->group('snapshots');

test('freezing labels does not cost a query per node', function () {
    [$assessment, $risk, $attack] = restorable();
    labelled($assessment, $risk, 'PCI scope');
    assignedTo($assessment, $attack);

    DB::enableQueryLog();
    snapshotOf($assessment);
    $withFew = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(1, 12) as $i) {
        $extra = Risk::factory()->for($assessment)->create(['title' => "Risk {$i}"]);
        labelled($assessment, $extra, "Label {$i}");
    }

    DB::flushQueryLog();
    snapshotOf($assessment, 'Bigger');
    $withMany = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Eager-loaded, so the count is flat rather than proportional. Asserted as
    // "did not grow" rather than as a fixed budget: a fixed number would go red
    // on any unrelated query added to the snapshot path and would then be
    // adjusted rather than read, which is how a budget test stops meaning
    // anything. CLAUDE.md names this exact N+1 as the legacy tree's defect.
    expect($withMany)->toBeLessThanOrEqual($withFew);
})->group('snapshots');

// --- restoring them ------------------------------------------------------

test('a node brought back by a v2 snapshot comes back with its labels', function () {
    [$assessment, $risk] = restorable();
    labelled($assessment, $risk, 'PCI scope');
    $snapshot = snapshotOf($assessment);

    $risk->delete();

    $response = restoreAs($assessment->owner, $assessment, $snapshot);

    $restored = Node::query()->where('uuid', $risk->uuid)->sole();

    expect($restored->tags()->pluck('slug')->all())->toBe(['pci-scope']);

    // And the message does not warn about something that did not happen. A
    // sentence about lost labels on a restore that lost none teaches people to
    // skip the whole message, which is how the real warnings get missed.
    $response->assertSessionHas('success', fn (string $m): bool => ! str_contains($m, 'without their labels'));
})->group('snapshots');

test('a node brought back by a v2 snapshot comes back with its assignees', function () {
    [$assessment, $risk] = restorable();
    $user = assignedTo($assessment, $risk);
    $snapshot = snapshotOf($assessment);

    $risk->delete();

    restoreAs($assessment->owner, $assessment, $snapshot);

    $restored = Node::query()->where('uuid', $risk->uuid)->sole();

    expect($restored->assignees()->pluck('users.id')->all())->toBe([$user->id]);
})->group('snapshots');

test('a label added after the snapshot is removed by restoring it', function () {
    [$assessment, $risk] = restorable();
    labelled($assessment, $risk, 'PCI scope');
    $snapshot = snapshotOf($assessment);

    labelled($assessment, $risk, 'Added later');

    restoreAs($assessment->owner, $assessment, $snapshot);

    // sync(), not attach. A restore is not a merge: a node still carrying a
    // label nobody in the frozen version had chosen is a node the restore only
    // partly reached, and the graph beside it got the replacing treatment.
    expect($risk->fresh()->tags()->pluck('slug')->all())->toBe(['pci-scope']);
})->group('snapshots');

test('an assignment made after the snapshot is removed by restoring it', function () {
    [$assessment, $risk] = restorable();
    $snapshot = snapshotOf($assessment);

    assignedTo($assessment, $risk);

    restoreAs($assessment->owner, $assessment, $snapshot);

    expect($risk->fresh()->assignees()->count())->toBe(0);
})->group('snapshots');

test('a label deleted from the vocabulary is recreated as it looked', function () {
    [$assessment, $risk] = restorable();
    $tag = labelled($assessment, $risk, 'PCI scope');
    $snapshot = snapshotOf($assessment);

    $tag->delete();

    restoreAs($assessment->owner, $assessment, $snapshot);

    $recreated = Tag::query()->where('assessment_id', $assessment->id)->where('slug', 'pci-scope')->sole();

    // Recreated rather than skipped: the slug left the vocabulary because
    // someone deleted it, and a restore that puts the nodes back without the
    // label they were filed under has restored the graph and lost the filing.
    // Name and colour come from the snapshot, so it comes back looking as it
    // did rather than as a bare slug.
    expect($recreated->name)->toBe('PCI scope')
        ->and($recreated->color)->toBe('#3B82F6')
        ->and($risk->fresh()->tags()->pluck('id')->all())->toBe([$recreated->id]);
})->group('snapshots');

test('a label still in the vocabulary is reused, not duplicated', function () {
    [$assessment, $risk] = restorable();
    $tag = labelled($assessment, $risk, 'PCI scope');
    $snapshot = snapshotOf($assessment);

    $risk->tags()->detach();

    restoreAs($assessment->owner, $assessment, $snapshot);

    // Matched on slug, the assessment-scoped stable name (schema.md §3.11). A
    // second row with the same slug would split every existing filter in two.
    expect(Tag::query()->where('assessment_id', $assessment->id)->count())->toBe(1)
        ->and($risk->fresh()->tags()->pluck('id')->all())->toBe([$tag->id]);
})->group('snapshots');

// --- the guard -----------------------------------------------------------

test('a restore does not re-assign someone who has lost access', function () {
    [$assessment, $risk] = restorable();
    $user = assignedTo($assessment, $risk);
    $snapshot = snapshotOf($assessment);

    // Removing a collaborator removes their assignments — AssessmentUser's
    // `deleted` hook, W46, and a decision that file argues at length.
    $assessment->members()->detach($user->id);
    expect($risk->fresh()->assignees()->count())->toBe(0);

    $response = restoreAs($assessment->owner, $assessment, $snapshot);

    // The guard itself. Re-attaching a frozen assignment without re-checking
    // would put back, in one click, every assignment that hook exists to take
    // away: the assessment would go on naming someone on a node they cannot
    // open, and the dashboard would offer them work they cannot reach.
    expect($risk->fresh()->assignees()->count())->toBe(0);

    // Dropped *and said*, because the person restoring is very often the
    // person who removed them, and a silent drop looks like the restore worked
    // completely.
    $response->assertSessionHas('success', fn (string $m): bool => str_contains($m, 'no longer have access'));
})->group('snapshots');

test('a restore does not re-assign a deleted account', function () {
    [$assessment, $risk] = restorable();
    $user = assignedTo($assessment, $risk);
    $snapshot = snapshotOf($assessment);

    $user->delete();

    // The same guard reached by the other route, and the redirect is the half
    // that pins it. `node_assignees.user_id` cascades, so the row is already
    // gone either way — but without the re-check the restore tries to write the
    // frozen id back, dies on the foreign key, and rolls the whole thing back.
    // Asserting only "no assignees" would pass in both worlds and pin nothing.
    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    expect($risk->fresh()->assignees()->count())->toBe(0);
})->group('snapshots');

test('the owner can hold an assignment even with no membership row', function () {
    [$assessment, $risk] = restorable();

    // The owner is `assessments.owner_user_id` and deliberately not a row in
    // `assessment_user` (schema.md §3.1), so a membership-only allowlist would
    // silently refuse to restore the most common assignment there is.
    $risk->assignees()->syncWithoutDetaching([$assessment->owner_user_id]);
    $snapshot = snapshotOf($assessment);

    $risk->assignees()->detach();

    restoreAs($assessment->owner, $assessment, $snapshot);

    expect($risk->fresh()->assignees()->pluck('users.id')->all())
        ->toBe([$assessment->owner_user_id]);
})->group('snapshots');

// --- both formats, forever -----------------------------------------------

test('a v1 snapshot still restores the graph it does hold', function () {
    [$assessment, $risk] = restorable();
    $snapshot = asVersion1(snapshotOf($assessment));

    $risk->title = 'Renamed since';
    $risk->save();

    restoreAs($assessment->owner, $assessment, $snapshot)->assertRedirect();

    // The point of keeping v1 readable: the snapshots taken before W62 are
    // exactly the ones somebody is most likely to need.
    expect($risk->fresh()->title)->toBe('Cardholder data breach');
})->group('snapshots');

test('a v1 restore leaves today’s labels alone rather than clearing them', function () {
    [$assessment, $risk] = restorable();
    $snapshot = asVersion1(snapshotOf($assessment));

    labelled($assessment, $risk, 'Added since');

    restoreAs($assessment->owner, $assessment, $snapshot);

    // The asymmetry is deliberate and is the only defensible reading. A v1
    // snapshot does not say "this node had no labels" — it says nothing about
    // labels at all, and syncing to an empty set would turn that silence into
    // a deletion. v2 says it, so v2 acts on it.
    expect($risk->fresh()->tags()->pluck('slug')->all())->toBe(['added-since']);
})->group('snapshots');

test('a database error is never dressed up as a friendly restore failure', function () {
    [$assessment, $risk] = restorable();
    labelled($assessment, $risk, 'PCI scope');
    $snapshot = snapshotOf($assessment);

    // A slug far longer than the column takes. Under STRICT_TRANS_TABLES this
    // is a QueryException from inside the restore — a defect in this code, not
    // a condition a user can act on.
    $codec = app(SnapshotGraphCodec::class);
    $graph = $codec->decode($snapshot->graph);
    $graph['nodes'][$risk->uuid]['tags'][0]['slug'] = str_repeat('x', 400);
    $snapshot->graph = $codec->encode($graph);
    $snapshot->save();

    $response = restoreAs($assessment->owner, $assessment, $snapshot);

    // The regression guard for W62's narrowed catch. `QueryException` extends
    // `PDOException` extends `RuntimeException`, so W61's `catch
    // (RuntimeException)` caught this and rendered its message — the failing
    // SQL and its bound values — into the page's error banner. It has to reach
    // the error handler instead, where security.md's rule about never showing
    // internals applies to it.
    expect($response->status())->not->toBe(302);
    $response->assertSessionMissing('error');
})->group('snapshots');

test('a snapshot from a version this code does not know is refused whole', function () {
    [$assessment, $risk] = restorable();
    $snapshot = snapshotOf($assessment);

    $codec = app(SnapshotGraphCodec::class);
    $graph = $codec->decode($snapshot->graph);
    $graph['schemaVersion'] = 3;
    $snapshot->graph = $codec->encode($graph);
    $snapshot->save();

    $risk->title = 'Renamed since';
    $risk->save();

    restoreAs($assessment->owner, $assessment, $snapshot)
        ->assertSessionHas('error');

    // Refused, and nothing written. A partial restore of a shape this code had
    // guessed at is the "looks restored and is quietly wrong" failure the whole
    // W48 audit exists to prevent — READABLE_VERSIONS is a list and not a
    // floor for exactly this reason.
    expect($risk->fresh()->title)->toBe('Renamed since')
        ->and(Assessment::query()->whereKey($assessment->id)->sole()->snapshots()->count())->toBe(1);
})->group('snapshots');
