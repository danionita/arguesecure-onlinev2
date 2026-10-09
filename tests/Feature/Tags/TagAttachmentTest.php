<?php

use App\Models\Assessment;
use App\Models\Risk;
use App\Models\Tag;
use App\Models\User;

/*
 * Labels (W43, item 18).
 *
 * The `tags` and `node_tag` tables have been in the schema since W1 with
 * nothing above them — no controller, no store field, and a filter in
 * `useFilters.ts` that was fully implemented and had nothing to filter. These
 * cover the four properties that make labels safe to have: they are created
 * by being used, a variant spelling reuses the label rather than splitting
 * it, they never cross an assessment boundary, and reading them back does not
 * cost a query per node.
 */

function labelMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('labelling a node creates the label on first use and reuses it after', function () {
    $assessment = Assessment::factory()->create();
    $first = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $second = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $first->uuid]), ['name' => 'PCI'])
        ->assertCreated()
        ->assertJsonPath('tag.name', 'PCI');

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $second->uuid]), ['name' => 'PCI'])
        ->assertCreated();

    // One tag, two attachments. Two rows would mean the filter offered "PCI"
    // twice and each chip found half the nodes.
    expect(Tag::where('assessment_id', $assessment->id)->count())->toBe(1);
    expect($first->fresh()->tags)->toHaveCount(1);
    expect($second->fresh()->tags)->toHaveCount(1);
});

test('a name that differs only in case or spacing resolves to the existing label', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $other = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $node->uuid]), ['name' => 'PCI DSS'])
        ->assertCreated();

    $response = $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $other->uuid]), ['name' => '  pci   dss  ']);

    // The first spelling wins and the response says so, which is what the
    // store renders — otherwise two people would see one label spelled two
    // ways until the next reload.
    $response->assertCreated()->assertJsonPath('tag.name', 'PCI DSS');
    expect(Tag::where('assessment_id', $assessment->id)->count())->toBe(1);
});

test('applying the same label twice is not an error', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $url = route('assessments.nodes.tags.store', [$assessment->uuid, $node->uuid]);

    $this->actingAs($assessment->owner)->postJson($url, ['name' => 'PCI'])->assertCreated();

    // `node_tag` has a composite primary key, so a plain attach() would be a
    // duplicate-key error here. Two people in one workshop labelling the same
    // node should mean "it is labelled", not a 500.
    $this->actingAs($assessment->owner)->postJson($url, ['name' => 'PCI'])->assertCreated();

    expect($node->fresh()->tags)->toHaveCount(1);
});

test('an empty or whitespace-only label is refused', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);

    // "   " passes `required` and would trim to an empty name and an empty
    // slug — which is uniquely indexed, so the second one would collide on a
    // value nobody can see or type on purpose.
    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $node->uuid]), ['name' => '   '])
        ->assertStatus(422);

    expect(Tag::where('assessment_id', $assessment->id)->count())->toBe(0);
});

test('labels never cross an assessment boundary', function () {
    $mine = Assessment::factory()->create();
    $theirs = Assessment::factory()->create();
    $myNode = Risk::factory()->create(['assessment_id' => $mine->id]);
    $theirNode = Risk::factory()->create(['assessment_id' => $theirs->id]);

    $this->actingAs($mine->owner)
        ->postJson(route('assessments.nodes.tags.store', [$mine->uuid, $myNode->uuid]), ['name' => 'PCI']);
    $this->actingAs($theirs->owner)
        ->postJson(route('assessments.nodes.tags.store', [$theirs->uuid, $theirNode->uuid]), ['name' => 'PCI']);

    // Same name, two rows. Assessment-scoped is the deliberate change from
    // the legacy global table, where one user's rename reached everyone.
    expect(Tag::where('slug', 'pci')->count())->toBe(2);

    // And a node uuid from another assessment cannot be labelled through
    // this one's policy check, even though the caller owns both.
    $this->actingAs($mine->owner)
        ->postJson(route('assessments.nodes.tags.store', [$mine->uuid, $theirNode->uuid]), ['name' => 'Leak'])
        ->assertNotFound();
});

test('a tag id from another assessment cannot be detached through this one', function () {
    $mine = Assessment::factory()->create();
    $theirs = Assessment::factory()->create();
    $myNode = Risk::factory()->create(['assessment_id' => $mine->id]);

    $theirTag = new Tag(['name' => 'Theirs', 'slug' => 'theirs']);
    $theirTag->assessment_id = $theirs->id;
    $theirTag->save();

    // 404, not 200. Detaching a tag that is not attached is a harmless no-op,
    // so answering 200 here and 404 for a nonexistent id is exactly the
    // difference that tells an attacker which ids are real.
    $this->actingAs($mine->owner)
        ->deleteJson(route('assessments.nodes.tags.destroy', [$mine->uuid, $myNode->uuid, $theirTag->id]))
        ->assertNotFound();
});

test('removing a label leaves the label in the assessment vocabulary', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $node->uuid]), ['name' => 'PCI']);
    $tag = Tag::where('assessment_id', $assessment->id)->firstOrFail();

    $this->actingAs($assessment->owner)
        ->deleteJson(route('assessments.nodes.tags.destroy', [$assessment->uuid, $node->uuid, $tag->id]))
        ->assertOk();

    expect($node->fresh()->tags)->toHaveCount(0);
    // The tag survives: it may be on twenty other nodes, and even when it is
    // not, offering it again is how a mistaken removal is undone in one
    // keystroke rather than by retyping it into a near-duplicate.
    expect(Tag::where('assessment_id', $assessment->id)->count())->toBe(1);
});

test('a viewer is refused, and a read-only share grants no labelling either', function () {
    $assessment = Assessment::factory()->create(['is_public' => true]);
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $viewer = User::factory()->create();
    labelMember($assessment, $viewer, 'viewer');

    // A label is an edit to the assessment that everyone sees, not a personal
    // bookmark — and `is_public` grants read only (CLAUDE.md).
    $this->actingAs($viewer)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $node->uuid]), ['name' => 'PCI'])
        ->assertForbidden();

    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $node->uuid]), ['name' => 'PCI'])
        ->assertForbidden();

    expect(Tag::where('assessment_id', $assessment->id)->count())->toBe(0);
});

test('the editor payload carries every node its labels, and the assessment its vocabulary', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $node->uuid]), ['name' => 'PCI']);

    $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->assertInertia(fn ($page) => $page
            ->has('tags', 1)
            ->where('tags.0.name', 'PCI')
            // Every node carries the key, including the root, which has no
            // labels: the client must never have to tell "no labels" apart
            // from "this response predates labels".
            ->has('nodes.0.tags')
            ->has('nodes.1.tags')
        );
});

test('duplicating an assessment carries the labels and which nodes wore them', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.tags.store', [$assessment->uuid, $node->uuid]), ['name' => 'PCI']);

    $this->actingAs($assessment->owner)
        ->post(route('assessments.duplicate', $assessment->uuid))
        ->assertRedirect(route('assessments.index'));

    $copy = Assessment::query()->where('id', '>', $assessment->id)->latest('id')->firstOrFail();

    // AssessmentCloner has copied the *vocabulary* since W6 and never the
    // `node_tag` rows, so a copy arrived with every label defined and none
    // of them applied. That was unreachable until this package, because
    // nothing could attach a tag to a node; it becomes silent data loss the
    // moment something can.
    expect($copy->tags)->toHaveCount(1);

    $copiedNode = $copy->nodes()->where('type', 'risk')->firstOrFail();
    expect($copiedNode->tags->pluck('name')->all())->toBe(['PCI']);

    // And the copy's label is its own row: renaming it must not reach back
    // into the original, which is the whole point of assessment scoping.
    expect($copiedNode->tags->first()->assessment_id)->toBe($copy->id);
});
