<?php

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * `/assessments/shared` — *Assessments shared with me* (W73 item 20, D33,
 * ui.md §8).
 *
 * Before W73 the sidebar's "Shared with me" group header and the dashboard's
 * shared section both pointed at `/assessments`, a list that mixes owned and
 * shared rows and does not distinguish them. This is the page they point at
 * now.
 *
 * The interesting half of this file is scoping. A collection route cannot
 * abort(403) on a subject it does not have, so what would leak here is a row,
 * not a status code — and the row that would leak is the one
 * `AssessmentPolicy::view()` says yes to for a reason that has nothing to do
 * with this page: `is_public` grants read to whoever holds the link
 * (security.md §3.1), which is not the same statement as "advertise it on a
 * stranger's arrival page".
 */

function sharedMember(Assessment $assessment, User $user, string $role = 'editor'): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

// --- authorization -------------------------------------------------------

test('a guest is sent to log in', function () {
    User::factory()->create();

    $this->get(route('assessments.shared'))->assertRedirect(route('login'));
});

test('a deactivated account cannot reach it', function () {
    // security.md §4: enforced on every request, not only at login.
    $this->actingAs(User::factory()->inactive()->create())
        ->get(route('assessments.shared'))
        ->assertForbidden();
});

test('the route carries the authenticated rate limit by name', function () {
    // The specified value, not "it has something starting with throttle" —
    // CLAUDE.md, and the defect W68 found behind exactly that mistake.
    $middleware = Route::getRoutes()->getByName('assessments.shared')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('auth')
        ->and($middleware)->toContain('active')
        ->and($middleware)->toContain('throttle:authenticated');
});

// --- scoping: what must not appear ---------------------------------------

test('an assessment I have no assessment_user row for never appears', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    Assessment::factory()->create(['owner_user_id' => $stranger->id]);

    $this->actingAs($user)
        ->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page
            ->component('Assessment/Shared')
            ->has('assessments', 0)
        );
});

test('a public assessment I have nothing to do with is not advertised here', function () {
    // The one row AssessmentPolicy::view() would grant and this page must
    // still refuse. The membership scope is what refuses it, and the policy
    // pass runs after that scope precisely so it cannot add the row back.
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    Assessment::factory()->create([
        'owner_user_id' => $stranger->id,
        'is_public' => true,
    ]);

    $this->actingAs($user)
        ->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->has('assessments', 0));
});

test('a template someone else owns is not advertised here either', function () {
    // A template is readable by every account on the instance (W45), which is
    // the same shape of grant as is_public and must be refused for the same
    // reason: readable is not shared-with-me.
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    Assessment::factory()->create([
        'owner_user_id' => $stranger->id,
        'is_template' => true,
        'is_public' => true,
    ]);

    $this->actingAs($user)
        ->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->has('assessments', 0));
});

test('my own assessments are not on the shared page', function () {
    // "Shared with me" means someone else owns it. An owned row here would
    // make the page a second copy of the list it exists to be distinct from.
    $user = User::factory()->create();
    Assessment::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->has('assessments', 0));
});

test('an assessment I was removed from disappears from it', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    sharedMember($assessment, $user);

    $this->actingAs($user)
        ->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->has('assessments', 1));

    $assessment->members()->detach($user->id);

    $this->actingAs($user)
        ->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->has('assessments', 0));
});

// --- what must appear ----------------------------------------------------

test('an assessment shared with me appears, naming its owner', function () {
    // The owner's name on every row is the whole reason this page exists
    // rather than the combined list.
    $user = User::factory()->create();
    $owner = User::factory()->create(['name' => 'Dana']);
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    sharedMember($assessment, $user);

    $this->actingAs($user)
        ->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page
            ->has('assessments', 1)
            ->where('assessments.0.uuid', $assessment->uuid)
            ->where('assessments.0.owner_name', 'Dana')
        );
});

test('the owner’s address never reaches the page', function () {
    // security.md, and the W17 report cover: a page about other people's work
    // is exactly where an address leaks.
    $user = User::factory()->create();
    $owner = User::factory()->create(['email' => 'dana@example.test']);
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    sharedMember($assessment, $user);

    $response = $this->actingAs($user)->get(route('assessments.shared'));

    expect($response->getContent())->not->toContain('dana@example.test');
});

test('it is the whole collection, not the dashboard’s excerpt', function () {
    // The dashboard tab shows six (DashboardData::SECTION_LIMIT). The page
    // the "see all" leads to has to show more than six, or the link is a lie.
    $user = User::factory()->create();
    $owner = User::factory()->create();

    foreach (range(1, 8) as $ignored) {
        sharedMember(
            Assessment::factory()->create(['owner_user_id' => $owner->id]),
            $user
        );
    }

    $this->actingAs($user)
        ->get(route('assessments.shared'))
        ->assertInertia(fn ($page) => $page->has('assessments', 8));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('sharedWithMe', 6));
});

test('every role that can be invited can read its own shared page', function () {
    // The page is not gated on `manage` or `update`: a viewer invited to an
    // assessment has been shared something and must be able to find it.
    foreach (['viewer', 'commenter', 'editor', 'manager'] as $role) {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);
        sharedMember($assessment, $user, $role);

        $this->actingAs($user)
            ->get(route('assessments.shared'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assessments', 1)
                ->where('assessments.0.uuid', $assessment->uuid)
            );
    }
});

test('the literal path is not swallowed by the uuid route next to it', function () {
    // `assessments.show` binds `assessments/{assessment:uuid}` with no
    // pattern constraint, so this route only works because it is registered
    // before it. Appending it at the foot of routes/web.php — the convention
    // every other block follows — would bind uuid "shared" and 404. This is
    // the assertion that fails if anyone tidies the block downward.
    $this->actingAs(User::factory()->create())
        ->get('/assessments/shared')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Assessment/Shared'));
});
