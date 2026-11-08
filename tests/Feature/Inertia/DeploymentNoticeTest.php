<?php

use App\Models\Assessment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * APP_NOTICE reaches every page as the shared `notice` prop, and with it
 * unset the app is unchanged.
 */

test('the notice reaches the login page, a signed-in page and a public assessment', function () {
    config(['arguesecure.notice' => 'Public demo. No real data.']);

    // An empty users table redirects /login to the first-run setup screen.
    $user = User::factory()->create();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('notice', 'Public demo. No real data.'));

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('notice', 'Public demo. No real data.'));

    auth()->logout();
    $assessment = Assessment::factory()->public()->create();
    $this->get(route('assessments.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page->where('notice', 'Public demo. No real data.'));
});

test('with no notice the prop is null', function () {
    User::factory()->create();
    expect(config('arguesecure.notice'))->toBeNull();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('notice', null));
});

test('the config key is null for unset and whitespace, trimmed, and cut at 300 characters', function () {
    $read = function (?string $value): ?string {
        putenv($value === null ? 'APP_NOTICE' : "APP_NOTICE={$value}");
        $_ENV['APP_NOTICE'] = $_SERVER['APP_NOTICE'] = $value;
        if ($value === null) {
            unset($_ENV['APP_NOTICE'], $_SERVER['APP_NOTICE']);
        }

        return (require base_path('config/arguesecure.php'))['notice'];
    };

    try {
        expect($read(null))->toBeNull()
            ->and($read("  \t "))->toBeNull()
            ->and($read('  Demo  '))->toBe('Demo')
            ->and($read("Demo\nno real\tdata\u{202E}"))->toBe('Demo no real data')
            ->and($read(str_repeat('a', 400)))->toBe(str_repeat('a', 300));
    } finally {
        $read(null);
    }
});

test('markup in the notice is escaped in the page payload', function () {
    config(['arguesecure.notice' => '<script>x</script><b>y</b>']);
    User::factory()->create();

    $html = $this->get(route('login'))->getContent();

    expect($html)->not->toContain('<script>x</script>')
        ->and($html)->not->toContain('<b>y</b>');
});

test('.env.example carries APP_NOTICE commented out', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toMatch('/^# APP_NOTICE=$/m')
        ->and($env)->not->toMatch('/^APP_NOTICE=/m');
});
