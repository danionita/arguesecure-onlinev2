<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\User;

/*
 * W40's suggestion endpoint: `GET /assessments/{uuid}/nodes/search`.
 *
 * It exists so that adding a child can offer a node that already exists
 * instead of creating a same-titled twin. Two properties matter more than the
 * matching itself, and both are asserted first:
 *
 *  - it is gated on `update`, not `view`, because it feeds an edit and
 *    because "read" here means an inventory of every node title in the
 *    assessment, handed out one keystroke at a time to anyone with a share
 *    link;
 *  - it never leaves the assessment in the URL, which is the IDOR shape
 *    security.md §3.3 keeps finding.
 */

function searchMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('an anonymous user is rejected', function () {
    $assessment = Assessment::factory()->create();

    $this->getJson(route('assessments.nodes.search', $assessment).'?type=attack')
        ->assertUnauthorized();
});

test('a stranger is rejected', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack')
        ->assertForbidden();
});

test('a viewer is rejected, because suggestions feed an edit they cannot make', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    searchMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack')
        ->assertForbidden();
});

test('a public assessment does not open the endpoint to the world', function () {
    // CLAUDE.md: `is_public` grants read only. The v1 defect was treating it
    // as world-writable; enumerating titles to feed a link is a write path.
    $assessment = Assessment::factory()->create(['is_public' => true]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack')
        ->assertForbidden();
});

test('an editor gets the attacks in this assessment and none from another', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);

    $other = Assessment::factory()->create();
    Attack::factory()->create(['assessment_id' => $other->id, 'title' => 'Phishing elsewhere']);

    $response = $this->actingAs($editor)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack&q=Phish')
        ->assertOk();

    expect($response->json('nodes.*.title'))->toBe(['Phishing']);
});

test('it never crosses the assessment boundary even when the term matches everywhere', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    $other = Assessment::factory()->create();
    Attack::factory()->count(3)->create(['assessment_id' => $other->id, 'title' => 'Phishing']);

    $response = $this->actingAs($editor)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack&q=')
        ->assertOk();

    expect($response->json('nodes'))->toBe([]);
});

test('it offers only the type asked for', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Shared name']);
    Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Shared name']);

    $attacks = $this->actingAs($editor)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack&q=Shared')
        ->json('nodes');
    $defences = $this->actingAs($editor)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=defence&q=Shared')
        ->json('nodes');

    expect($attacks)->toHaveCount(1)
        ->and($defences)->toHaveCount(1)
        ->and($attacks[0]['uuid'])->not->toBe($defences[0]['uuid']);
});

test('risk is not an offerable type: a risk has one parent and cannot be shared', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=risk')
        ->assertStatus(422);
});

test('it excludes what is already under the parent, so no choice can 500 on the unique index', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $linked = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing here']);
    $free = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing elsewhere']);
    $risk->attacks()->attach($linked->id, ['position' => 0]);

    $response = $this->actingAs($editor)->getJson(
        route('assessments.nodes.search', $assessment)."?type=attack&q=Phishing&parent_uuid={$risk->uuid}"
    )->assertOk();

    expect($response->json('nodes.*.uuid'))->toBe([$free->uuid]);
});

test('a parent uuid from another assessment is rejected rather than silently ignored', function () {
    // Silently ignoring it would turn a probe into a working query and leak
    // "that uuid exists" through the shape of the response (security.md §3.3).
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    $foreign = Risk::factory()->create(['assessment_id' => Assessment::factory()->create()->id]);

    $this->actingAs($editor)->getJson(
        route('assessments.nodes.search', $assessment)."?type=attack&parent_uuid={$foreign->uuid}"
    )->assertStatus(422);
});

test('it reports how many parents a suggestion already has', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);
    foreach (range(1, 2) as $i) {
        Risk::factory()->create(['assessment_id' => $assessment->id])
            ->attacks()->attach($attack->id, ['position' => 0]);
    }

    $response = $this->actingAs($editor)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack&q=Phish')
        ->assertOk();

    // The count is the whole reason the list is legible: linking to a node
    // you cannot see is the part that needs explaining.
    expect($response->json('nodes.0.occurrences'))->toBe(2);
});

test('a wildcard in the search term is matched literally, not as a wildcard', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);
    Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => '100% coverage']);

    $response = $this->actingAs($editor)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack&q=%')
        ->assertOk();

    // Unescaped, `%` matches everything and a suggestion box becomes a full
    // listing on one keystroke.
    expect($response->json('nodes.*.title'))->toBe(['100% coverage']);
});

test('it caps the list at ten', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    Attack::factory()->count(15)->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);

    $response = $this->actingAs($editor)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack&q=Phish')
        ->assertOk();

    expect($response->json('nodes'))->toHaveCount(10);
});

test('it shortens a long description rather than shipping the whole field', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    searchMember($assessment, $editor, 'editor');

    Attack::factory()->create([
        'assessment_id' => $assessment->id,
        'title' => 'Phishing',
        'description' => str_repeat('a', 300),
    ]);

    $response = $this->actingAs($editor)
        ->getJson(route('assessments.nodes.search', $assessment).'?type=attack&q=Phish')
        ->assertOk();

    expect(mb_strlen($response->json('nodes.0.description')))->toBe(100);
});
