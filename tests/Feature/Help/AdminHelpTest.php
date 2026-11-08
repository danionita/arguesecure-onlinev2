<?php

use App\Domain\Assistant\Help\Corpus;
use App\Domain\Help\AdminHelpLibrary;
use App\Domain\Help\HelpLibrary;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The administrators' help: admin only, searchable, and not part of the public
 * help or the assistant's corpus.
 */

test('a guest is sent to sign in on every admin help route', function () {
    foreach (['/admin/help', '/admin/help/users', '/admin/help?q=email'] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
});

test('a non-admin is refused on every admin help route', function () {
    foreach (['/admin/help', '/admin/help/users', '/admin/help?q=email'] as $url) {
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    }
});

test('an admin sees the index and every page of the manifest', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/help')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Help')->has('pages', count(AdminHelpLibrary::all())));

    foreach (AdminHelpLibrary::all() as $page) {
        $this->actingAs($admin)->get("/admin/help/{$page['slug']}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/HelpPage')->where('title', $page['title']));
    }
});

test('an unknown page is a 404 and the slug never reaches the filesystem', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/help/nope')->assertNotFound();
    $this->actingAs($admin)->get('/admin/help/..%2F..%2F.env')->assertNotFound();
});

test('search finds pages by every word, ranks by count and returns a plain snippet', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/help?q=smtp')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('results.0.slug', 'email')
            ->where('results.0.title', 'Email')
            ->where('results', fn ($results) => collect($results)->every(fn ($r) => ! str_contains($r['snippet'], '**'))));

    $hits = AdminHelpLibrary::search('security log download');
    expect(collect($hits)->pluck('slug')->all())->toBe(['security-log']);
    expect(AdminHelpLibrary::search('SMTP')[0]['slug'])->toBe('email');
});

test('search is literal, needs two characters and finds nothing for what is not there', function () {
    expect(AdminHelpLibrary::search('s'))->toBe([])
        ->and(AdminHelpLibrary::search('   '))->toBe([])
        ->and(AdminHelpLibrary::search('.*'))->toBe([])
        ->and(AdminHelpLibrary::search('(?i)smtp'))->toBe([])
        ->and(AdminHelpLibrary::search('zzzqqqxxx'))->toBe([]);
});

test('the public help does not list the admin pages and the assistant never reads them', function () {
    expect(collect(HelpLibrary::all())->pluck('slug')->all())->not->toContain('administration');
    expect(app(Corpus::class)->text())->not->toContain('Send test email')
        ->not->toContain('Remove second factor');

    $this->get('/help/administration')->assertNotFound();
    $this->get('/help/users')->assertNotFound();
});

test('every admin help page in the manifest exists and the directory holds nothing else', function () {
    $slugs = collect(AdminHelpLibrary::all())->pluck('slug')->sort()->values()->all();
    $files = collect(glob(resource_path('markdown/admin-help/*.md')))->map(fn ($f) => basename($f, '.md'))->sort()->values()->all();

    expect($files)->toBe($slugs);
});

test('the public help index links to the admin help for admins only', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/help')
        ->assertInertia(fn (Assert $page) => $page->where('adminHelp', true));

    $this->actingAs(User::factory()->create())->get('/help')
        ->assertInertia(fn (Assert $page) => $page->where('adminHelp', false));

    auth()->logout();
    $this->get('/help')->assertInertia(fn (Assert $page) => $page->where('adminHelp', false));
});

test('a snippet keeps names with underscores, shows no markup and matches only visible words', function () {
    $hit = AdminHelpLibrary::search('APP_NOTICE')[0];

    expect($hit['slug'])->toBe('settings')
        ->and($hit['snippet'])->toContain('APP_NOTICE')
        ->and($hit['snippet'])->not->toContain('**')
        ->and($hit['snippet'])->not->toContain('`');

    // A link's target is not a word the reader sees.
    expect(AdminHelpLibrary::search('help/templates'))->toBe([]);
});

test('the query is capped once after trimming, and an over-long query does not error', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/help?q='.urlencode(str_repeat(' ', 150).'smtp'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('query', 'smtp')->where('results.0.slug', 'email'));
    $this->actingAs($admin)->get('/admin/help?q='.str_repeat('a', 500))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('query', str_repeat('a', 100)));
});
