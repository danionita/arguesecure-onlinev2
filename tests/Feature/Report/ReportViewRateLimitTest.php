<?php

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * W110 (D70). The report page and the exports are limited separately.
 *
 * Every option toggle on the report re-requests the page. Until W110 the page
 * shared `reports` — security.md §9's "Report generation: 10/min per user" —
 * with the five exports, so the eleventh toggle in a minute answered 429.
 * Signed-in users now get 60/min on the page; guests keep 10 (D70).
 *
 * Asserted by value, not presence (.claude/rules/pinned-tests.md): the page
 * carrying *a* throttle would stay green if it fell back to `reports`, and
 * the exports' guard lives in ExportAuthorizationTest.
 */
test('the report page carries the report-views limiter and not the exports\' one', function () {
    $middleware = Route::getRoutes()->getByName('assessments.report.show')->gatherMiddleware();

    expect($middleware)->toContain('throttle:report-views')
        ->and($middleware)->not->toContain('throttle:reports');
});

test('the report-views limiter allows a signed-in user 60 a minute and a guest 10, as security.md specifies', function () {
    $limiter = app(RateLimiter::class)->limiter('report-views');

    expect($limiter)->not->toBeNull();

    $first = fn ($limits) => is_array($limits) ? $limits[0] : $limits;

    $user = User::factory()->create();
    $signedIn = Request::create('/');
    $signedIn->setUserResolver(fn () => $user);
    $limit = $first($limiter($signedIn));

    expect($limit->maxAttempts)->toBe(60)
        ->and($limit->decaySeconds)->toBe(60);

    // Assembling the page costs about what an export does (D70), so an
    // anonymous public or share-link reader keeps the 10/min it always had.
    $guest = Request::create('/');
    $guest->setUserResolver(fn () => null);
    $limit = $first($limiter($guest));

    expect($limit->maxAttempts)->toBe(10)
        ->and($limit->decaySeconds)->toBe(60);
});

test('toggling report options twenty times in a minute is not refused', function () {
    $assessment = Assessment::factory()->create();
    $owner = $assessment->owner;

    foreach (range(1, 20) as $i) {
        $this->actingAs($owner)
            ->get(route('assessments.report.show', [
                'assessment' => $assessment,
                'include_methodology' => $i % 2 === 0 ? '1' : '0',
            ]))
            ->assertOk();
    }
});

test('an export is still refused after ten in a minute', function () {
    $assessment = Assessment::factory()->create();
    $owner = $assessment->owner;

    foreach (range(1, 10) as $i) {
        $this->actingAs($owner)
            ->get(route('assessments.report.export-json', $assessment))
            ->assertOk();
    }

    $this->actingAs($owner)
        ->get(route('assessments.report.export-json', $assessment))
        ->assertStatus(429);
});
