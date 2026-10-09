<?php

use App\Domain\Structure\AssessmentCreator;
use App\Models\Assessment;
use App\Models\RootNode;
use App\Models\Scale;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * W26 — listing, creating and deleting assessments.
 *
 * This is the entry point the application shipped without: every route was
 * `assessments/{assessment}/...`, so nothing could produce the assessment
 * they all presuppose, and a fresh deployment reached first-run setup and
 * then stopped. The end-to-end run is what surfaced it, because scenario 2
 * begins "User A creates an assessment" and nothing in the repo could.
 */
/*
 * The system scales are reference data seeded by the deployment
 * (docker/entrypoint.sh), not by a migration, so a test database has none
 * unless it asks — exactly the state every fresh deployment was in before
 * W26, and the reason creating the first assessment failed there. Seeding
 * here reproduces a correctly-provisioned instance; the test named
 * "fails loudly when no system scales are seeded" deliberately tears them
 * back down.
 */
beforeEach(function () {
    (new SystemScaleSeeder)->run();
});

function lifecycleMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

// --- creating ------------------------------------------------------------

test('an anonymous user cannot create an assessment', function () {
    $this->post(route('assessments.store'), ['title' => 'Nope'])
        ->assertRedirect(route('login'));

    expect(Assessment::query()->count())->toBe(0);
});

test('creating an assessment puts the title on the root node, not on the assessment', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('assessments.store'), [
            'title' => 'Payment platform',
            'description' => 'The cardholder data environment.',
        ])
        ->assertRedirect();

    $assessment = Assessment::query()->firstOrFail();

    expect($assessment->owner_user_id)->toBe($user->id);

    // schema.md: "Assessment has no title column — the title and
    // description live on the root node". A create that skipped the root
    // would leave an assessment that renders untitled everywhere and
    // violates nodes_root_unique on the next node added.
    $root = RootNode::query()->where('assessment_id', $assessment->id)->firstOrFail();

    expect($root->title)->toBe('Payment platform')
        ->and($root->description)->toBe('The cardholder data environment.')
        ->and($root->position)->toBe(0);
});

test('a title is required, because an untitled assessment is unfindable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('assessments.store'), ['title' => ''])
        ->assertSessionHasErrors('title');

    expect(Assessment::query()->count())->toBe(0);
});

test('creating an assessment resolves the system scales rather than leaving them null', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('assessments.store'), ['title' => 'Scaled'])
        ->assertRedirect();

    $assessment = Assessment::query()->firstOrFail();

    // All three are NOT NULL foreign keys, so an assessment cannot exist
    // without them — this asserts they point at the *system* scales rather
    // than at whatever row happened to be first.
    foreach (['impact', 'likelihood', 'strength'] as $kind) {
        $scaleId = $assessment->{$kind.'_scale_id'};
        $scale = Scale::query()->findOrFail($scaleId);

        expect($scale->kind)->toBe($kind)
            ->and($scale->is_system)->toBeTrue()
            ->and($scale->assessment_id)->toBeNull();
    }
});

test('creation fails loudly when no system scales are seeded, rather than on a foreign key', function () {
    // The state every fresh deployment was actually in: migrated, but
    // never seeded, so `scales` was empty. The failure needs to name the
    // cause — the entrypoint now seeds these, and a constraint violation
    // would send whoever hits it looking in the wrong place.
    Assessment::query()->forceDelete();
    Scale::query()->delete();

    expect(fn () => app(AssessmentCreator::class)->create(User::factory()->create(), 'No scales'))
        ->toThrow(RuntimeException::class, 'No system impact scale exists');
});

// --- listing -------------------------------------------------------------

test('an anonymous user is redirected to login from the assessment list', function () {
    $this->get(route('assessments.index'))->assertRedirect(route('login'));
});

test('"My assessments" lists what the caller owns, and nothing else', function () {
    $user = User::factory()->create();

    $own = Assessment::factory()->create(['owner_user_id' => $user->id]);
    $shared = Assessment::factory()->create();
    lifecycleMember($shared, $user, 'viewer');

    $strangers = Assessment::factory()->create();

    // Deliberately public: `is_public` grants read to anyone with the
    // link, but listing it here would turn a personal index into a
    // directory of other people's work and make "public" mean "advertised".
    $publicStranger = Assessment::factory()->public()->create();

    $this->actingAs($user)
        ->get(route('assessments.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($own, $shared, $strangers, $publicStranger) {
            $uuids = collect($page->toArray()['props']['assessments'])->pluck('uuid');

            // 2.5.1: shared assessments have their own page and are no
            // longer mixed into this one.
            expect($uuids)->toContain($own->uuid)
                ->and($uuids)->not->toContain($shared->uuid)
                ->and($uuids)->not->toContain($strangers->uuid)
                ->and($uuids)->not->toContain($publicStranger->uuid);
        });

    $this->actingAs($user)
        ->get(route('assessments.shared'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($own, $shared) {
            $uuids = collect($page->toArray()['props']['assessments'])->pluck('uuid');

            expect($uuids)->toContain($shared->uuid)
                ->and($uuids)->not->toContain($own->uuid);
        });
});

test('the list never serializes another user email, only a display name', function () {
    $user = User::factory()->create();
    $shared = Assessment::factory()->create();
    lifecycleMember($shared, $user, 'viewer');

    // Inertia ships the whole prop bag in the page's data-page attribute,
    // so an unrendered field is still readable with View Source. An
    // owner-email leak of exactly this shape was already found once, on the
    // report cover (W17).
    // Asserted on the page that lists someone else's assessments — since
    // 2.5.1 that is "Shared with me", not "My assessments".
    $response = $this->actingAs($user)->get(route('assessments.shared'))->assertOk();

    $content = $response->getContent();

    expect($content)->not->toContain($shared->owner->email)
        ->and($content)->toContain($shared->owner->name);
});

test('the dashboard is a real page, not a placeholder', function () {
    // W26 wrote this as "the dashboard *is* the assessment list", which was
    // the honest thing to assert when no dashboard existed: `/dashboard` is
    // where Laravel sends people after login, and pointing it at the list was
    // better than a stub. W42 gave it a page of its own, so the component
    // changed — but the claim behind the test did not, and it is the claim
    // that is worth keeping: this route answers with something useful.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('myAssessments')
            ->has('sharedWithMe')
        );
});

// --- deleting ------------------------------------------------------------

test('a viewer cannot delete an assessment', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    lifecycleMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->delete(route('assessments.destroy', $assessment))
        ->assertForbidden();

    expect(Assessment::query()->find($assessment->id))->not->toBeNull();
});

test('an editor cannot delete an assessment', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    lifecycleMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->delete(route('assessments.destroy', $assessment))
        ->assertForbidden();

    expect(Assessment::query()->find($assessment->id))->not->toBeNull();
});

test('the owner can delete an assessment, and it soft deletes', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->delete(route('assessments.destroy', $assessment))
        ->assertRedirect(route('assessments.index'));

    // Not fresh(): it queries via newQueryWithoutScopes(), so it happily
    // returns a soft-deleted row and would assert nothing at all here.
    expect(Assessment::query()->find($assessment->id))->toBeNull()
        ->and(Assessment::withTrashed()->find($assessment->id))->not->toBeNull();
});
