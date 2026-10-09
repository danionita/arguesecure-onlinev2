<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\User;

/*
 * Per-row actions on the assessment lists (W42 item 20).
 *
 * 2.5.1: "My assessments" lists owned rows only, so rows someone else owns —
 * the only ones where a role, rather than ownership, decides the actions —
 * are asserted on "Shared with me", which renders the same list component
 * with the same row shape (AssessmentListRows).
 *
 * Every one of these is a pair. The page renders a button from a permission
 * the server computed; the route enforces the same permission independently.
 * Asserting only the first would let someone delete an assessment by typing
 * the URL; asserting only the second would ship a button that 403s when
 * clicked. Both, every time.
 */

function listMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('a viewer is offered no Delete, and DELETE 403s for them anyway', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    listMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->where('assessments.0.can_delete', false));

    $this->actingAs($viewer)
        ->delete(route('assessments.destroy', $assessment->uuid))
        ->assertForbidden();

    expect(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

test('an editor is offered no Share, and cannot reach the sharing page', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    listMember($assessment, $editor, 'editor');

    $this->actingAs($editor)->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->where('assessments.0.can_manage', false));

    $this->actingAs($editor)
        ->get(route('share.index', $assessment->uuid))
        ->assertForbidden();
});

test('a manager is offered Share', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    listMember($assessment, $manager, 'manager');

    $this->actingAs($manager)->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->where('assessments.0.can_manage', true));
});

test('the abilities come from the policy, not from ownership', function () {
    // A manager who is not the owner can share. Inferring `can_manage` from
    // `is_owner` — the shape this page had before W42 — gets that wrong, and
    // gets an admin's delete wrong in the other direction.
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    listMember($assessment, $manager, 'manager');

    $this->actingAs($manager)->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page
            ->where('assessments.0.is_owner', false)
            ->where('assessments.0.can_manage', true)
        );
});

test('the owner sees the whole set', function () {
    $owner = User::factory()->create();
    Assessment::factory()->create(['owner_user_id' => $owner->id]);

    $this->actingAs($owner)->get(route('assessments.index'))
        ->assertInertia(fn ($page) => $page
            ->where('assessments.0.can_manage', true)
            ->where('assessments.0.can_delete', true)
        );
});

test('the shared row names the owner', function () {
    $owner = User::factory()->create(['name' => 'Dana']);
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    $viewer = User::factory()->create();
    listMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->where('assessments.0.owner_name', 'Dana'));
});

test('neither list issues a permission query per row', function () {
    $user = User::factory()->create();
    Assessment::factory()->count(6)->create(['owner_user_id' => $user->id]);
    foreach (Assessment::factory()->count(6)->create() as $shared) {
        listMember($shared, $user, 'editor');
    }

    DB::enableQueryLog();
    $this->actingAs($user)->get(route('assessments.index'));
    $ownCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->actingAs($user)->get(route('assessments.shared'));
    $count = max($ownCount, count(DB::getQueryLog()));
    DB::disableQueryLog();

    // AssessmentPolicy resolves a role through the members relation, so an
    // un-eager-loaded list costs a query per row for `can_manage` and
    // another for `can_delete` — the N+1 CLAUDE.md names as the legacy tree
    // render's defining defect, rebuilt on a different page.
    expect($count)->toBeLessThan(20);
});

// --- duplicate --------------------------------------------------------------

test('anyone who can view may duplicate, because the copy is theirs alone', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    listMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->post(route('assessments.duplicate', $assessment->uuid))
        ->assertRedirect(route('assessments.index'));

    $clone = Assessment::query()->where('owner_user_id', $viewer->id)->first();
    expect($clone)->not->toBeNull()
        ->and($clone->uuid)->not->toBe($assessment->uuid);
});

test('a stranger cannot duplicate', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('assessments.duplicate', $assessment->uuid))
        ->assertForbidden();

    expect(Assessment::query()->count())->toBe(1);
});

test('a guest cannot duplicate', function () {
    $assessment = Assessment::factory()->create();

    $this->post(route('assessments.duplicate', $assessment->uuid))
        ->assertRedirect(route('login'));
});

test('the copy is independent: whole graph, new uuids, new owner', function () {
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);

    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Data breach']);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => 'MFA']);
    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $attack->defences()->attach($defence->id, ['position' => 0, 'strength_fraction' => 0.8]);

    $actor = User::factory()->create();
    listMember($assessment, $actor, 'viewer');

    $this->actingAs($actor)->post(route('assessments.duplicate', $assessment->uuid));

    $clone = Assessment::query()->where('owner_user_id', $actor->id)->firstOrFail();

    // Every node came across...
    $titles = $clone->nodes()->pluck('title')->all();
    expect($titles)->toContain('Data breach', 'Phishing', 'MFA');

    // ...with new identities, never a reference back to the source. A clone
    // that reused uuids would make the two assessments the same rows under
    // two names, and editing one would show up in the other.
    $sourceUuids = $assessment->nodes()->pluck('uuid')->all();
    $cloneUuids = $clone->nodes()->pluck('uuid')->all();
    expect(array_intersect($sourceUuids, $cloneUuids))->toBe([]);

    // And the value that lives on the pivot rather than on either node —
    // exactly the kind of column a mass-assigned identity mistake drops
    // silently, leaving a copy that looks complete and computes wrongly
    // (CLAUDE.md: AssessmentCloner has broken this way twice).
    $clonedAttack = $clone->nodes()->where('title', 'Phishing')->firstOrFail();
    expect(
        DB::table('attack_defence')->where('attack_node_id', $clonedAttack->id)->value('strength_fraction')
    )->toEqual(0.8);
});

test('the copy is renamed, so the list does not show two identical rows', function () {
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    $assessment->rootNode->forceFill(['title' => 'Payment platform'])->save();

    $this->actingAs($owner)->post(route('assessments.duplicate', $assessment->uuid));

    $clone = Assessment::query()->where('id', '!=', $assessment->id)->firstOrFail();

    // Without this the list and the sidebar both show two rows reading
    // "Payment platform" with nothing to tell them apart — the exact problem
    // the e2e template scenario had to work around by renaming by hand.
    expect($clone->title)->toBe('Payment platform (copy)');
    expect($assessment->fresh()->title)->toBe('Payment platform');
});

test('a very long title still fits after the suffix', function () {
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    $assessment->rootNode->forceFill(['title' => str_repeat('a', 255)])->save();

    $this->actingAs($owner)->post(route('assessments.duplicate', $assessment->uuid));

    $clone = Assessment::query()->where('id', '!=', $assessment->id)->firstOrFail();

    // `nodes.title` is varchar(255); appending " (copy)" to a full-length
    // title without trimming first is a truncation error under strict mode.
    expect(mb_strlen((string) $clone->title))->toBeLessThanOrEqual(255)
        ->and($clone->title)->toEndWith(' (copy)');
});
