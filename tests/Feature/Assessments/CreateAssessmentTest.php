<?php

/**
 * The sidebar's "New assessment" control (2.4 item 19).
 *
 * ## What this file used to guard, and why it changed
 *
 * W73 gave the control its own endpoint, `POST assessments/new`
 * (`AssessmentController::create`), which created an untitled assessment and
 * redirected into the editor. That endpoint is **gone**, removed at 2.4
 * integration, and these tests changed with it.
 *
 * The reason was not the endpoint itself, which worked. It was that the list
 * page already had a button reading "New assessment" which opens a dialog
 * asking for a title, so the page ended up carrying two controls with the
 * same accessible name doing two different things — one prompting, one not.
 * Same name and same behaviour is ordinary duplication; same name and
 * *different* behaviour is ambiguous to anyone reading the page and
 * indistinguishable to anyone hearing it announced. The end-to-end suite hit
 * the same wall from the other side, unable to resolve the locator.
 *
 * The sidebar now links to `assessments.index` with `?new=1`, which opens the
 * dialog `Pages/Assessment/Index.vue` already owns. So creation has one flow
 * and one write path, `assessments.store`, whose own authorization,
 * validation and ownership guarantees are covered by
 * `StoreAssessmentRequest` and `tests/Feature/Assessment/**`.
 *
 * ## What is left to guard here
 *
 * Exactly the thing that regressed: that a second creation write path has not
 * come back. A test asserting the *absence* of a route is unusual and would
 * normally be weak — but the defect this file records was born of having two
 * ways to create an assessment, so "there is still only one" is the property,
 * and it is the one a future edit could quietly undo.
 */

use App\Models\Assessment;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;
use Illuminate\Support\Facades\Route;

// AssessmentCreator calibrates a new assessment from the system scale set
// (W77), so creation needs them present. Without this the route 500s and
// every assertion below fails for a reason that has nothing to do with what
// it is testing.
beforeEach(function () {
    $this->seed(SystemScaleSeeder::class);
});

test('there is no second endpoint that creates an assessment', function () {
    // By name, and by URI, because a reintroduction could easily pick a
    // different name for the same path or the reverse.
    expect(Route::getRoutes()->getByName('assessments.new'))->toBeNull();

    $creating = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('POST', $route->methods(), true))
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => $uri === 'assessments/new' || $uri === 'assessments')
        ->values();

    // `assessments` (assessments.store) is the one legitimate creation POST.
    expect($creating->all())->toBe(['assessments']);
});

test('the one creation path still refuses an anonymous visitor', function () {
    $this->post(route('assessments.store'), ['title' => 'Anything'])
        ->assertRedirect(route('login'));

    expect(Assessment::query()->count())->toBe(0);
});

test('the one creation path still refuses a deactivated account', function () {
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)
        ->post(route('assessments.store'), ['title' => 'Anything'])
        ->assertForbidden();

    expect(Assessment::query()->count())->toBe(0);
});

test('it still requires a title, which is the behaviour the sidebar now shares', function () {
    // The removed endpoint took no body at all and supplied a placeholder.
    // Routing the sidebar through this one means the title requirement is now
    // uniform, which is the whole point of the change -- so it is asserted
    // rather than left implied.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('assessments.store'), ['title' => ''])
        ->assertSessionHasErrors('title');

    expect(Assessment::query()->count())->toBe(0);
});

test('the new assessment is owned by whoever asked for it, and by nobody else', function () {
    $creator = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($creator)
        ->post(route('assessments.store'), ['title' => 'Mine']);

    $assessment = Assessment::query()->sole();

    expect($assessment->owner_user_id)->toBe($creator->id);
    expect($assessment->members()->where('users.id', $stranger->id)->exists())->toBeFalse();
});

test('one account cannot create an assessment owned by another', function () {
    $creator = User::factory()->create();
    $victim = User::factory()->create();

    $this->actingAs($creator)->post(route('assessments.store'), [
        'title' => 'Smuggled',
        // Never mass-assigned: asserting the specified owner, not merely that
        // a row appeared.
        'owner_user_id' => $victim->id,
        'is_public' => true,
        'is_template' => true,
    ]);

    $assessment = Assessment::query()->sole();

    expect($assessment->owner_user_id)->toBe($creator->id);
    expect($assessment->is_public)->toBeFalse();
    expect($assessment->is_template)->toBeFalse();
});

test('the title lives on the root node, where the schema puts it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('assessments.store'), ['title' => 'On the root']);

    $assessment = Assessment::query()->sole();
    $root = $assessment->nodes()->where('type', 'root')->sole();

    // schema.md: the assessment's title lives on its root node, not on the
    // assessment row.
    expect($root->title)->toBe('On the root');
});
