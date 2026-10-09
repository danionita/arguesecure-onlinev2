<?php

/**
 * W50 — the assistant runtime, its off switch, and its degradation path.
 *
 * This package ships **no feature**: a client, a health probe, a setting and
 * the states in between. These tests are therefore the package, not a check on
 * it, and they are written around the three things that are easy to get wrong
 * and invisible when they are:
 *
 * 1. **A deployment with no runtime must serve no assistant surface at all.**
 *    Not a 403, not a 200 with `available: false` — nothing. A feature this
 *    installation does not have should 404.
 * 2. **An unreachable runtime must not break the application.** Every page
 *    still renders; nothing is slower than the probe's own cached miss.
 * 3. **The explicit timeout must be explicit.** It is the defect D20 exists
 *    for, it is invisible on a small assessment, and it is mutation-tested
 *    below.
 *
 * Nothing here contacts a real model. `Http::fake()` stands in for the runtime
 * so the suite stays deterministic and CI-safe — the same discipline
 * PLAN-2.3-ai.md requires of W53's red-team fixtures.
 */

use App\Domain\Assistant\Availability;
use App\Domain\Assistant\Client;
use App\Domain\Assistant\Health;
use App\Models\InstanceSetting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config()->set('assistant.enabled', true);
    config()->set('assistant.base_url', 'http://assistant:8080');
});

/** Switches the instance-level setting without going through the HTTP layer. */
function setAssistantEnabled(bool $enabled): void
{
    $row = InstanceSetting::singleton();
    $row->assistant_enabled = $enabled;
    $row->save();
}

// ---------------------------------------------------------------------------
// 1. Absence
// ---------------------------------------------------------------------------

test('a deployment without a runtime serves no assistant endpoint', function () {
    config()->set('assistant.enabled', false);

    // 404, not 403. "This exists and you may not have it" would be false, and
    // it invites someone to go looking for a way in. The feature is not here.
    $this->actingAs(User::factory()->create())
        ->getJson('/assistant/status')
        ->assertNotFound();
});

test('the deployment guard bites before authentication is even considered as a reason', function () {
    config()->set('assistant.enabled', false);
    setAssistantEnabled(true);
    Http::fake(['*/health' => Http::response('', 200)]);

    // Everything else is in order — an admin switched it on, a runtime would
    // answer — and it still 404s, because the deployment has no runtime to
    // switch on. Mutation-test: drop `abort_unless` from
    // EnsureAssistantConfigured and confirm this goes red.
    $this->actingAs(User::factory()->create())
        ->getJson('/assistant/status')
        ->assertNotFound();
});

test('the assistant state is NOT_CONFIGURED when the deployment has no runtime, without touching the network', function () {
    config()->set('assistant.enabled', false);
    Http::fake();
    setAssistantEnabled(true);

    expect(app(Availability::class)->state())->toBe(Availability::NOT_CONFIGURED);

    // The cheapest condition is checked first on purpose: the overwhelming
    // majority of installations will never run a runtime, and they must not
    // pay a probe — or a database read — for a feature they do not have.
    Http::assertNothingSent();
});

test('NOT_CONFIGURED says nothing to the user', function () {
    config()->set('assistant.enabled', false);

    // Silence is the correct interface for a feature this installation does
    // not have. No banner, no disabled control, no explanation of something
    // the reader never asked about.
    expect(app(Availability::class)->reason())->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. The three live states
// ---------------------------------------------------------------------------

test('a configured runtime that no admin switched on is DISABLED, and is not probed', function () {
    Http::fake();
    setAssistantEnabled(false);

    expect(app(Availability::class)->state())->toBe(Availability::DISABLED);
    expect(app(Availability::class)->reason())->not->toBeNull();

    Http::assertNothingSent();
});

test('an enabled assistant whose runtime does not answer is UNREACHABLE, not an error', function () {
    Http::fake(['*/health' => Http::response('', 503)]);
    setAssistantEnabled(true);

    $availability = app(Availability::class);

    expect($availability->state())->toBe(Availability::UNREACHABLE);
    expect($availability->isAvailable())->toBeFalse();
    // The distinction from NOT_CONFIGURED: here something *is* wrong and
    // somebody should fix it, so the user is told.
    expect($availability->reason())->toContain('not responding');
});

test('an enabled assistant with a healthy runtime is AVAILABLE', function () {
    Http::fake(['*/health' => Http::response(['status' => 'ok'], 200)]);
    setAssistantEnabled(true);

    expect(app(Availability::class)->isAvailable())->toBeTrue();
});

test('a transport failure degrades rather than throwing', function () {
    // DNS failure, connection refused, TLS error — on a status probe none of
    // these are actionable, and letting one become a 500 on a page that merely
    // wanted to know whether to show a button would defeat the whole
    // degradation path.
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    setAssistantEnabled(true);

    expect(app(Availability::class)->state())->toBe(Availability::UNREACHABLE);
});

// ---------------------------------------------------------------------------
// 3. The probe must not sit on the render path
// ---------------------------------------------------------------------------

test('the health probe is cached, and failures are cached exactly as successes are', function () {
    Http::fake(['*/health' => Http::response('', 503)]);
    setAssistantEnabled(true);

    $health = app(Health::class);
    $health->isUp();
    $health->isUp();
    $health->isUp();

    // Caching only the successes would mean an absent runtime is re-probed on
    // every single request — which is precisely the cost this exists to avoid,
    // and it would bite hardest on the deployment that is already broken.
    Http::assertSentCount(1);
});

test('enabling the assistant drops the cached probe', function () {
    // A sequence, not two `Http::fake()` calls: the second call *appends* a
    // stub rather than replacing the first, so the 503 keeps matching and the
    // test passes or fails for the wrong reason.
    Http::fake([
        '*/health' => Http::sequence()
            ->push('', 503)
            ->push('', 200),
    ]);
    setAssistantEnabled(true);

    expect(app(Health::class)->isUp())->toBeFalse();

    app(Health::class)->forget();

    // Reaching the second element of the sequence is itself the proof that
    // the cache was dropped and a fresh probe went out. Without this an admin
    // who starts the container and switches the feature on is told "the
    // runtime is not responding" for as long as the TTL lasts, having done
    // everything correctly.
    expect(app(Health::class)->isUp())->toBeTrue();
});

test('the probe uses the short connect timeout, not the generation timeout', function () {
    // Captured in the fake's own callback, which receives Guzzle's options —
    // `Http::assertSent()` does not, it gets the request and the response, so
    // a timeout assertion written there passes vacuously.
    $options = [];
    Http::fake(function ($request, $opts) use (&$options) {
        $options = $opts;

        return Http::response('', 200);
    });

    setAssistantEnabled(true);
    config()->set('assistant.timeouts.connect', 3);
    config()->set('assistant.timeouts.total', 900);

    app(Health::class)->isUp();

    // A runtime that is up answers a connect immediately, so a slow connect
    // means absent — and absent must degrade fast. Waiting the 900s generation
    // budget to discover a missing container would hang whichever unlucky
    // request happened to refresh the cache.
    expect($options['timeout'])->toBeLessThan(60);
});

// ---------------------------------------------------------------------------
// 4. The endpoint
// ---------------------------------------------------------------------------

test('the status endpoint requires authentication', function () {
    $this->get('/assistant/status')->assertRedirect('/login');
});

test('the status endpoint reports the state to a signed-in user', function () {
    Http::fake(['*/health' => Http::response('', 200)]);
    setAssistantEnabled(true);

    $this->actingAs(User::factory()->create())
        ->getJson('/assistant/status')
        ->assertOk()
        ->assertJson([
            'state' => Availability::AVAILABLE,
            'available' => true,
            'reason' => null,
        ]);
});

test('the status endpoint reports unavailability without failing', function () {
    Http::fake(['*/health' => Http::response('', 503)]);
    setAssistantEnabled(true);

    $this->actingAs(User::factory()->create())
        ->getJson('/assistant/status')
        ->assertOk()
        ->assertJson(['state' => Availability::UNREACHABLE, 'available' => false]);
});

// ---------------------------------------------------------------------------
// 5. The client, and the timeout that is the whole point of D20
// ---------------------------------------------------------------------------

/*
 * FIXED (W59): the client used to post a raw prompt to `/completion`, which
 * applies no chat template. `gemma-3-4b-it` read a finished document and
 * stopped, so **job A and job C returned an empty string on every run** while
 * the whole suite stayed green — a stub answers whatever it is asked. Measured
 * both ways in docs/spikes/2.3-threads.md: 1 token raw against 88 and 401 on
 * the chat endpoint.
 *
 * These two assert the *specified* thing rather than the presence of
 * something, per this repository's rule about presence assertions: the URL the
 * request goes to, and that the prompt travels as a chat message rather than
 * as a raw completion. Reverting either turns them red.
 *
 * `LiveRuntimeTest` is the other half — it is the only test that can tell
 * whether a model actually answers, and it needs a real runtime.
 */
test('the client posts to the chat endpoint, so the model gets its own chat template', function () {
    Http::fake(['*/completions' => Http::response("data: [DONE]\n")]);

    app(Client::class)->complete('a prompt', 128);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/chat/completions'));
});

test('the prompt travels as a chat message, not as a raw completion', function () {
    Http::fake(['*/completions' => Http::response("data: [DONE]\n")]);

    app(Client::class)->complete('a prompt', 128);

    Http::assertSent(function ($request) {
        return $request['messages'] === [['role' => 'user', 'content' => 'a prompt']]
            && ! isset($request['prompt']);
    });
});

test('the client reads the chat endpoint own stream shape', function () {
    Http::fake([
        '*/completions' => Http::response(
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'Cred']]]])."\n".
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'ential ']]]])."\n".
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'stuffing']]]])."\n".
            "data: [DONE]\n"
        ),
    ]);

    expect(app(Client::class)->complete('a prompt', 128))
        ->toBe('Credential stuffing');
});

test('the client streams, and assembles the reply from the event stream', function () {
    Http::fake([
        '*/completions' => Http::response(
            "data: {\"content\":\"Cred\"}\n".
            "data: {\"content\":\"ential \"}\n".
            "data: {\"content\":\"stuffing\"}\n".
            "data: {\"content\":\"\",\"stop\":true}\n"
        ),
    ]);

    expect(app(Client::class)->complete('a prompt', 128))
        ->toBe('Credential stuffing');
});

test('the client sets an explicit timeout far larger than any HTTP default', function () {
    $options = [];
    Http::fake(function ($request, $opts) use (&$options) {
        $options = $opts;

        return Http::response("data: {\"content\":\"x\"}\n");
    });

    config()->set('assistant.timeouts.total', 900);

    app(Client::class)->complete('a prompt', 128);

    // **Mutation-test this one.** Drop `->timeout(...)` from
    // App\Domain\Assistant\Client::complete() and confirm it goes red before
    // trusting it. Assert the *value*, never that some timeout is present:
    // CLAUDE.md's "a presence assertion is not a guard" was learned on exactly
    // this shape, where a laxer setting still satisfied a test looking only
    // for the option's existence.
    //
    // This is the defect D20 exists for, and it is invisible until an
    // assessment is large enough to generate for longer than the client's
    // default — at which point the request dies while the server is still
    // working and the failure looks like a server fault. It is not. It cost
    // half of the spike's first benchmark, twice, at exactly 300 seconds.
    expect($options['timeout'])->toBeGreaterThanOrEqual(300);
});

test('the client asks the runtime to stream', function () {
    Http::fake(['*/completions' => Http::response("data: {\"content\":\"x\"}\n")]);

    app(Client::class)->complete('a prompt', 128);

    Http::assertSent(function ($request) {
        // Not a preference: without this the runtime sends no headers at all
        // until the whole generation is finished, and every timeout in the
        // stack then measures the wrong thing.
        return $request['stream'] === true;
    });
});

test('the client passes the generation budget through, because it is the latency dial', function () {
    Http::fake(['*/completions' => Http::response("data: {\"content\":\"x\"}\n")]);

    app(Client::class)->complete('a prompt', 321);

    // `max_tokens` since W59, where the client moved to the chat endpoint so
    // that the model's own chat template gets applied — `n_predict` is the raw
    // endpoint's spelling of the same dial.
    Http::assertSent(fn ($request) => $request['max_tokens'] === 321);
});

test('the client raises a runtime failure rather than returning an empty answer', function () {
    Http::fake(['*/completions' => Http::response('', 500)]);

    expect(fn () => app(Client::class)->complete('a prompt', 128))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// 6. The admin setting
// ---------------------------------------------------------------------------

test('an admin can switch the assistant on where the deployment has a runtime', function () {
    Http::fake(['*/health' => Http::response('', 200)]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/assistant', [
        'default_currency' => 'EUR',
        'default_scale_calibration' => 'default',
        'public_assessments_allowed' => true,
        'assistant_enabled' => true,
        'session_lifetime_minutes' => 480,
    ])->assertRedirect(route('admin.assistant.edit'));

    expect(InstanceSetting::singleton()->assistant_enabled)->toBeTrue();
});

test('an admin cannot switch on an assistant the deployment cannot serve', function () {
    config()->set('assistant.enabled', false);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/assistant', [
        'default_currency' => 'EUR',
        'default_scale_calibration' => 'default',
        'public_assessments_allowed' => true,
        'assistant_enabled' => true,
        'session_lifetime_minutes' => 480,
    ])->assertSessionHasErrors('assistant_enabled');

    // The value must not have been written on the way to being rejected.
    expect(InstanceSetting::singleton()->assistant_enabled)->toBeFalse();
});

test('saving other settings on a deployment without a runtime still works', function () {
    config()->set('assistant.enabled', false);
    $admin = User::factory()->admin()->create();

    // The page renders no toggle in this state and so submits no value. The
    // rule constrains the value, not the field, precisely so an ordinary save
    // does not fail on a deployment that will never run an assistant.
    $this->actingAs($admin)->put('/admin/settings', [
        'default_currency' => 'USD',
        'default_scale_calibration' => 'default',
        'public_assessments_allowed' => true,
        'session_lifetime_minutes' => 480,
    ])->assertSessionHasNoErrors();
});

test('the assistant is off on a fresh instance', function () {
    // D25. A deployment that adds the container does not thereby hand the
    // feature to its users; somebody decides.
    expect(InstanceSetting::singleton()->assistant_enabled)->toBeFalse();
});
