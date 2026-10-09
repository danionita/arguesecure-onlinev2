<?php

use App\Domain\Help\HelpLibrary;
use App\Domain\Help\MarkdownRenderer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * PLAN.md Phase 9 item 2 / W23: help is authored as markdown rendered by
 * the app, so updating it is not a code deploy. This suite is the "help
 * route renders, and is reachable by the audience it should be" test the
 * W23 brief asks for, plus "every markdown file the help index lists
 * actually exists and parses" — a broken link in help must fail a test,
 * not surprise a user.
 *
 * Reachability decision: help sits outside the `auth` group (routes/web.php
 * W23 block) because it is general product documentation, not assessment
 * data — a guest reading a public assessment or a share link, who may have
 * no account at all, should be able to reach it. These tests assert that
 * decision holds for both a guest and a logged-in user, and that a
 * deactivated user is still rejected (EnsureUserIsActive is active on this
 * route group too).
 */

test('the help index is reachable by an anonymous guest', function () {
    $this->get(route('help.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Help/Index'));
});

test('the help index is reachable by a logged-in user', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('help.index'))
        ->assertOk();
});

test('a deactivated user is rejected from the help index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('help.index'))->assertOk();

    $user->is_active = false;
    $user->save();

    $this->get(route('help.index'))->assertForbidden();
});

test('every page in the help manifest renders for an anonymous guest', function () {
    foreach (HelpLibrary::all() as $entry) {
        $this->get(route('help.show', $entry['slug']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Help/Show')
                ->where('slug', $entry['slug'])
                ->where('title', $entry['title'])
                ->has('html')
            );
    }
});

test('every markdown file the help manifest lists actually exists and parses to non-empty HTML', function () {
    $entries = HelpLibrary::all();
    expect($entries)->not->toBeEmpty();

    foreach ($entries as $entry) {
        $path = HelpLibrary::path($entry['slug']);

        expect(file_exists($path))->toBeTrue("Help page [{$entry['slug']}] has no markdown file at {$path}.");

        $html = MarkdownRenderer::toHtml(file_get_contents($path));

        expect(trim($html))->not->toBe('', "Help page [{$entry['slug']}] rendered to empty HTML.");
    }
});

test('an unknown help slug 404s rather than reading an arbitrary path', function () {
    $this->get(route('help.show', 'not-a-real-page'))->assertNotFound();
});

test('a slug attempting path traversal 404s', function () {
    // Route parameter, not URL-decoded into a path segment boundary by
    // Laravel's router in a way that would let this reach outside
    // resources/markdown/help — asserted directly rather than assumed.
    $this->get('/help/..%2F..%2F..%2Fetc%2Fpasswd')->assertNotFound();
});
