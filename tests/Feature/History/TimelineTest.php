<?php

use App\Domain\History\TimelineReader;
use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\NodeRevision;
use App\Models\Risk;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The timeline (W48, item 21; PLAN-2.2.md D15).
 *
 * Two logs, one feed. `assessment_operations` holds structural changes and
 * `node_revisions` holds field edits (schema.md §3.9/§3.10); neither is a
 * subset of the other and a reader wants both in one column. The merge happens
 * server-side so the page, the tests and any later consumer cannot disagree
 * about the order.
 */
function timelineMember(Assessment $assessment, string $role): User
{
    $user = User::factory()->create();

    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $user;
}

function writeRevision(Assessment $assessment, Risk $node, ?User $user, array $changes, ?int $operationId = null, ?string $at = null): NodeRevision
{
    // Direct property assignment for the identity columns, per CLAUDE.md —
    // and this model is deliberately never mass-assigned from a request.
    return NodeRevision::create([
        'assessment_id' => $assessment->id,
        'node_id' => $node->id,
        'node_uuid' => $node->uuid,
        'user_id' => $user?->id,
        'action' => 'update',
        'changes' => $changes,
        'operation_id' => $operationId,
        'created_at' => $at ?? now(),
    ]);
}

function writeOperation(Assessment $assessment, ?User $user, string $kind, string $summary, ?string $at = null): AssessmentOperation
{
    return AssessmentOperation::create([
        'assessment_id' => $assessment->id,
        'user_id' => $user?->id,
        'kind' => $kind,
        'summary' => $summary,
        'inverse' => ['action' => 'noop'],
        'created_at' => $at ?? now(),
    ]);
}

// --- the merge -----------------------------------------------------------

test('the timeline carries both logs, newest first', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $dana = timelineMember($assessment, 'editor');

    writeOperation($assessment, $dana, 'node.create', 'Dana added a risk', '2026-08-20 10:00:00');
    writeRevision($assessment, $risk, $dana, ['title' => ['from' => 'Old', 'to' => 'New']], null, '2026-08-21 10:00:00');

    $timeline = app(TimelineReader::class)->read($assessment);

    expect($timeline['entries'])->toHaveCount(2)
        ->and($timeline['entries'][0]['source'])->toBe('revision')
        ->and($timeline['entries'][1]['source'])->toBe('operation')
        ->and($timeline['entries'][1]['summary'])->toBe('Dana added a risk');
});

/*
 * The grouping D15 asks for. Several revisions sharing an `operation_id` were
 * one user action and must read as one entry — "Dana added 3 defences under
 * Phishing", not three lines.
 */
test('revisions sharing an operation group into one entry', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $dana = timelineMember($assessment, 'editor');

    $operation = writeOperation($assessment, $dana, 'subtree.clone', 'Dana copied a branch');

    writeRevision($assessment, $risk, $dana, ['title' => ['from' => 'a', 'to' => 'b']], $operation->id);
    writeRevision($assessment, $risk, $dana, ['description' => ['from' => 'c', 'to' => 'd']], $operation->id);

    $entries = app(TimelineReader::class)->read($assessment)['entries'];
    $revisions = array_values(array_filter($entries, fn (array $e): bool => $e['source'] === 'revision'));

    expect($revisions)->toHaveCount(1)
        ->and($revisions[0]['changes'])->toHaveCount(2)
        ->and($revisions[0]['summary'])->toBe('Changed 2 fields on '.$risk->title);
});

test('an ungrouped revision is one entry carrying every field that save changed', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $dana = timelineMember($assessment, 'editor');

    writeRevision($assessment, $risk, $dana, [
        'title' => ['from' => 'Old', 'to' => 'Cardholder data breach'],
        'description' => ['from' => null, 'to' => 'Written'],
    ]);

    $entries = app(TimelineReader::class)->read($assessment)['entries'];

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['changes'])->toHaveCount(2)
        ->and($entries[0]['changes'][0]['node_title'])->toBe('Cardholder data breach');
});

// --- paging --------------------------------------------------------------

test('the timeline pages, and the last page says there is no more', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $dana = timelineMember($assessment, 'editor');

    foreach (range(1, TimelineReader::PER_PAGE + 3) as $i) {
        writeOperation($assessment, $dana, 'node.create', "Change {$i}", now()->subMinutes($i)->format('Y-m-d H:i:s'));
    }

    $reader = app(TimelineReader::class);

    $first = $reader->read($assessment, [], 1);
    $second = $reader->read($assessment, [], 2);

    expect($first['entries'])->toHaveCount(TimelineReader::PER_PAGE)
        ->and($first['hasMore'])->toBeTrue()
        ->and($second['entries'])->toHaveCount(3)
        ->and($second['hasMore'])->toBeFalse()
        ->and($second['total'])->toBe(TimelineReader::PER_PAGE + 3)
        // No entry appears on both pages.
        ->and(array_intersect(
            array_column($first['entries'], 'id'),
            array_column($second['entries'], 'id'),
        ))->toBe([]);
});

// --- filters -------------------------------------------------------------

test('filtering by user covers both logs', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $dana = timelineMember($assessment, 'editor');
    $kim = timelineMember($assessment, 'editor');

    writeOperation($assessment, $dana, 'node.create', 'Dana added a risk');
    writeOperation($assessment, $kim, 'node.create', 'Kim added a risk');
    writeRevision($assessment, $risk, $dana, ['title' => ['from' => 'a', 'to' => 'b']]);
    writeRevision($assessment, $risk, $kim, ['title' => ['from' => 'b', 'to' => 'c']]);

    $entries = app(TimelineReader::class)->read($assessment, ['user_id' => $dana->id])['entries'];

    expect($entries)->toHaveCount(2)
        ->and(collect($entries)->pluck('user')->unique()->all())->toBe([$dana->name]);
});

test('filtering by action selects one kind', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $dana = timelineMember($assessment, 'editor');

    writeOperation($assessment, $dana, 'node.create', 'added');
    writeOperation($assessment, $dana, 'node.delete', 'deleted');
    writeRevision($assessment, $risk, $dana, ['title' => ['from' => 'a', 'to' => 'b']]);

    $reader = app(TimelineReader::class);

    expect($reader->read($assessment, ['action' => 'node.delete'])['entries'])->toHaveCount(1)
        ->and($reader->read($assessment, ['action' => 'field.update'])['entries'])->toHaveCount(1);
});

/*
 * A structural entry names no single node — a clone or a catalogue insert
 * touches several — so filtering by node lists field edits only rather than
 * guessing which operations belong to it. The page says so above the list.
 */
test('filtering by node lists that node’s field edits and no structural entries', function () {
    $assessment = Assessment::factory()->create();
    $one = Risk::factory()->for($assessment)->create();
    $two = Risk::factory()->for($assessment)->create();
    $dana = timelineMember($assessment, 'editor');

    writeOperation($assessment, $dana, 'node.create', 'added something');
    writeRevision($assessment, $one, $dana, ['title' => ['from' => 'a', 'to' => 'b']]);
    writeRevision($assessment, $two, $dana, ['title' => ['from' => 'c', 'to' => 'd']]);

    $entries = app(TimelineReader::class)->read($assessment, ['node_uuid' => $one->uuid])['entries'];

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['node_uuid'])->toBe($one->uuid);
});

/*
 * `node_revisions.node_uuid` is denormalised so history outlives a purge
 * (schema.md §3.9). A soft-deleted node's entries must still say what they
 * were about, or the record of why a number changed disappears with the node.
 */
test('a deleted node’s entries still carry its title', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $dana = timelineMember($assessment, 'editor');

    writeRevision($assessment, $risk, $dana, ['title' => ['from' => 'Old', 'to' => 'Cardholder data breach']]);
    $risk->delete();

    $entries = app(TimelineReader::class)->read($assessment->fresh())['entries'];

    expect($entries[0]['changes'][0]['node_title'])->toBe('Cardholder data breach');
});

test('the actor list includes someone who has since been removed', function () {
    $assessment = Assessment::factory()->create();
    $dana = timelineMember($assessment, 'editor');
    writeOperation($assessment, $dana, 'node.create', 'Dana added a risk');

    $assessment->members()->detach($dana->id);

    $names = collect(app(TimelineReader::class)->actors($assessment->fresh()))->pluck('name')->all();

    // Their changes are still in the log; a filter that cannot name them makes
    // those entries unreachable.
    expect($names)->toContain($dana->name);
});

// --- the page ------------------------------------------------------------

test('a viewer can read the history of an assessment they can view', function () {
    $assessment = Assessment::factory()->create();
    $viewer = timelineMember($assessment, 'viewer');

    $this->actingAs($viewer)
        ->get(route('assessments.history.index', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Assessment/History')
            // Courtesy, not authorization: saving and restoring a version
            // are offered only to someone who could do them.
            ->where('abilities.canSave', false)
            ->where('abilities.canRestore', false));
});

test('a stranger cannot read the history of a private assessment', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('assessments.history.index', $assessment))
        ->assertForbidden();
});

test('an anonymous user is redirected to login', function () {
    $assessment = Assessment::factory()->create();

    $this->get(route('assessments.history.index', $assessment))
        ->assertRedirect(route('login'));
});

/*
 * `action` reaches a `where` clause, so it is checked against a fixed list
 * rather than accepted as free text — security.md §7, "no dynamic column names
 * from request input", and the same reasoning applies to a value that selects
 * rows.
 */
test('an unknown action filter is rejected rather than queried', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.history.index', [$assessment, 'action' => 'drop.everything']))
        ->assertSessionHasErrors('action');
});

/*
 * The absence asserted directly. D15 specifies a revert; this package audited
 * the revision log, found gaps that would make a reconstruction silently
 * wrong, and did not build one. If someone adds the route later, this goes red
 * — which is the signal to read RevisionCoverageTest and confirm the gaps were
 * closed first.
 */
test('there is no revert route, and that is deliberate', function () {
    expect(app('router')->getRoutes()->getByName('assessments.history.revert'))->toBeNull();
});
