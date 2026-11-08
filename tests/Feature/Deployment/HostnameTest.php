<?php

/**
 * Moving an instance to another hostname is configuration and the reverse
 * proxy, not app code (docs/specs/deployment.md §4.1). These tests pin the
 * properties that make that true, so a change that breaks one of them shows
 * up here rather than on the day an operator renames a host:
 *
 *  - links built during a request follow the host of that request, so both
 *    names work while an instance is moving;
 *  - the OIDC redirect URI follows the request host;
 *  - the CSP websocket origin follows APP_URL behind a proxy;
 *  - session and CSRF cookies are host-only;
 *  - nothing the database stores is an absolute URL to the app.
 *
 * They pin behaviour that is already correct; none is a DEFECT: pin.
 */

use App\Domain\Notifications\MentionedInComment;
use App\Domain\Notifications\NodeAssigned;
use App\Domain\Notifications\SummaryDrafted;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/../Auth/Oidc/helpers.php';

const NEW_HOST = 'https://demo.example.com';
const OLD_HOST = 'https://old.example.com';

/** A request as the app's own nginx passes it on: from a private proxy address, scheme forwarded. */
function viaProxy(): array
{
    return ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'];
}

it('builds a share link from the host the request used (new host)', function () {
    config(['app.url' => NEW_HOST]);
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);

    $link = $this->actingAs($owner)->withServerVariables(viaProxy())
        ->postJson(NEW_HOST.'/assessments/'.$assessment->uuid.'/share/token')
        ->assertOk()->json('share_link');

    expect($link)->toStartWith(NEW_HOST.'/s/')->and(substr($link, strlen(NEW_HOST.'/s/')))->not->toBe('');
});

it('builds a share link from the old host when the old host is asked, whatever APP_URL says', function () {
    // During a move both names serve the app. Forcing the root URL to
    // APP_URL would send visitors of the old name to the new one mid-session.
    config(['app.url' => NEW_HOST]);
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);

    $link = $this->actingAs($owner)->withServerVariables(viaProxy())
        ->postJson(OLD_HOST.'/assessments/'.$assessment->uuid.'/share/token')
        ->assertOk()->json('share_link');

    expect($link)->toStartWith(OLD_HOST.'/s/');
});

it('sends the OIDC redirect URI for the host the sign-in started on, and shows the same value to the admin', function () {
    config(['app.url' => NEW_HOST]);
    fakeIdp();

    $location = $this->withServerVariables(viaProxy())
        ->get(NEW_HOST.'/auth/oidc/redirect')->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query['redirect_uri'])->toBe(NEW_HOST.'/auth/oidc/callback');

    $this->actingAs(User::factory()->admin()->create())->withServerVariables(viaProxy())
        ->get(NEW_HOST.'/admin/authentication')
        ->assertInertia(fn ($page) => $page->where('redirectUri', NEW_HOST.'/auth/oidc/callback'));
});

it('sends the old host\'s redirect URI when sign-in starts on the old host, whatever APP_URL says', function () {
    // The request host decides, not APP_URL: this is why the old URI must stay
    // registered at the provider while both names serve the app.
    config(['app.url' => NEW_HOST]);
    fakeIdp();

    $location = $this->withServerVariables(viaProxy())
        ->get(OLD_HOST.'/auth/oidc/redirect')->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query['redirect_uri'])->toBe(OLD_HOST.'/auth/oidc/callback');
});

it('names APP_URL as the websocket origin when the proxy reaches the app by an internal name', function () {
    config(['app.url' => NEW_HOST]);

    $csp = (string) $this->withServerVariables(viaProxy())->get('http://app/')->headers->get('Content-Security-Policy');
    preg_match('#connect-src ([^;]+)#', $csp, $matches);
    $sources = preg_split('/\s+/', trim($matches[1] ?? '')) ?: [];

    expect(in_array('wss://demo.example.com', $sources, true))
        ->toBeTrue('connect-src must name the public host, got: '.($matches[1] ?? '(none)'));
});

it('keeps session and CSRF cookies host-only', function () {
    // A Domain attribute would send them to every sibling service on the
    // parent domain (security.md §5).
    $response = $this->withServerVariables(viaProxy())->get(NEW_HOST.'/login');
    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());

    foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $name) {
        expect($cookies->has($name))->toBeTrue("$name is set on a page response")
            ->and($cookies[$name]->getDomain())->toBeNull("$name carries no Domain");
    }
});

it('ships .env.example without a value for SESSION_DOMAIN', function () {
    $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES);

    $assigned = array_filter($lines, fn (string $l) => preg_match('/^\s*SESSION_DOMAIN\s*=\s*\S/', $l) === 1);

    expect($assigned)->toBe([]);
});

it('stores no absolute URL in a database notification payload', function () {
    URL::forceRootUrl(NEW_HOST);
    $user = User::factory()->create();
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->for($assessment)->create();
    $comment = Comment::factory()->create(['assessment_id' => $assessment->id, 'node_id' => $node->id]);

    $payloads = [
        (new NodeAssigned($node, $user))->toArray($user),
        (new MentionedInComment($comment))->toArray($user),
        (new SummaryDrafted($assessment, true))->toArray($user),
        (new SummaryDrafted($assessment, false, 'timeout'))->toArray($user),
    ];

    // Not vacuous: each payload carries the fields it is for.
    expect($payloads)->each->not->toBeEmpty()
        ->and($payloads[0]['assessment_uuid'])->toBe($assessment->uuid);

    array_walk_recursive($payloads, function ($value, $key) {
        if (is_string($value)) {
            expect($value)->not->toMatch('#^https?://#i', "payload key $key holds an absolute URL");
        }
    });
});
