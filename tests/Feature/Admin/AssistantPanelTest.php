<?php

/**
 * W75 item 17 (PLAN-2.4.md D37) — the admin settings page can explain the
 * assistant, and explaining it does not make it reachable.
 *
 * The reported symptom was "I could not find any trace of it". W50 chose
 * silence on a deployment with no inference runtime, which was right for the
 * *control* — a toggle that cannot work is worse than no toggle — and wrong
 * for the *explanation*: an admin cannot distinguish a build that lacks the
 * feature from an instance where it is switched off two containers away, and
 * silence answers that question wrongly.
 *
 * These tests are therefore in two halves:
 *
 * 1. **The page is given what a diagnosis needs**, in every one of the four
 *    Availability states, including the one where nothing is configured.
 * 2. **The gate is exactly where it was.** D25 is not reopened. Adding words
 *    to a settings page is the sort of change that quietly grows a "try
 *    anyway" affordance, so the absence of one is asserted here rather than
 *    assumed — the routes still 404, and the setting still cannot be turned
 *    on without a runtime.
 *
 * The health probe is faked throughout: nothing in this suite contacts a real
 * model, per PLAN-2.3-ai.md's rule for W53's fixtures.
 */

use App\Domain\Assistant\Availability;
use App\Models\InstanceSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // The probe is cached across a TTL, and these tests move between "up" and
    // "down" faster than that. Without this, the second test in a file reads
    // the first one's answer.
    Cache::flush();
});

/** Switches the instance-level half without going through the HTTP layer. */
function enableAssistantSetting(bool $enabled): void
{
    $row = InstanceSetting::singleton();
    $row->assistant_enabled = $enabled;
    $row->save();
}

// ---------------------------------------------------------------------------
// 1. What the page is given
// ---------------------------------------------------------------------------

test('the settings page is told the deployment has no runtime', function () {
    Http::fake();
    config()->set('assistant.enabled', false);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Assistant')
            ->where('assistant.configured', false)
            // Null rather than `not_configured`: the state machine is only
            // meaningful once a runtime exists, and sending a state here
            // would invite the page to branch on a distinction that has no
            // consequence.
            ->where('assistant.state', null)
            // And no model name, because there is no model. Naming the
            // configured default on an instance that runs nothing would be a
            // claim about a container that is not there.
            ->where('assistant.model', null)
            // Nor the presets: there is nothing here to install them into.
            ->where('assistant.presets', [])
            ->etc()
        );

    // And nothing was asked of a runtime that does not exist.
    Http::assertNothingSent();
});

/** A healthy runtime whose /props reports it loaded $file (llama.cpp's shape). */
function runtimeServing(string $file): void
{
    Http::fake([
        '*/props' => Http::response(['model_path' => '/models/'.$file, 'build_info' => 'b11176-f805c57a2']),
        '*/health' => Http::response('', 200),
    ]);
}

test('the settings page names the preset the runtime reports it loaded', function () {
    runtimeServing('gemma-4-E4B-it-qat-UD-Q4_K_XL.gguf');
    config()->set('assistant.enabled', true);
    enableAssistantSetting(true);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assistant.configured', true)
            // From the runtime, not from .env (D55): the old label could name
            // one model while the container ran another.
            ->where('assistant.model.preset', 'small')
            ->where('assistant.model.file', 'gemma-4-E4B-it-qat-UD-Q4_K_XL.gguf')
            ->where('assistant.model.label', 'Gemma 4 E4B (4-bit, quantization-aware)')
            ->where('assistant.model.memory_gib', 6)
            // Both presets, so the page can say what the other one needs.
            ->where('assistant.presets.0.name', 'small')
            ->where('assistant.presets.1.name', 'large')
            ->where('assistant.presets.1.memory_gib', 28)
            ->etc()
        );
});

test('the settings page reports a custom model by its file name', function () {
    // Every 2.5 deployment runs this, through ASSISTANT_MODEL_FILE, until
    // someone installs a preset (D54). It is not an error, and it is not one
    // of the presets either.
    runtimeServing('gemma-3-4b-it-Q4_K_M.gguf');
    config()->set('assistant.enabled', true);
    enableAssistantSetting(true);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assistant.model.file', 'gemma-3-4b-it-Q4_K_M.gguf')
            ->where('assistant.model.preset', null)
            ->where('assistant.model.label', null)
            ->etc()
        );
});

test('the settings page claims no model when the runtime does not answer', function () {
    Http::fake(['*' => Http::response('', 503)]);
    config()->set('assistant.enabled', true);
    enableAssistantSetting(true);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assistant.state', Availability::UNREACHABLE)
            // Not the preset .env asks for, and not the last one seen: a
            // model nobody can confirm is running is not reported as running.
            ->where('assistant.model', null)
            ->etc()
        );
});

test('the settings page reports a runtime that is switched off but present', function () {
    runtimeServing('gemma-4-26B-A4B-it-qat-UD-Q4_K_XL.gguf');
    config()->set('assistant.enabled', true);
    enableAssistantSetting(false);

    $admin = User::factory()->admin()->create();

    foreach ([1, 2] as $render) {
        $this->actingAs($admin)
            ->get('/admin/assistant')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('assistant.state', Availability::DISABLED)
                // Which model is installed is worth knowing before deciding
                // to switch it on, so it is asked even now (D55)...
                ->where('assistant.model.preset', 'large')
                ->etc()
            );
    }

    // ...but Availability still returns DISABLED before it probes health, and
    // the model probe is cached: two renders, one request, and never
    // /health. Before 2.6 this asserted that nothing at all was sent; the
    // model question is the one deliberate addition, and it is bounded to one
    // short request per TTL on this one page (D25's render-path rule).
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/props'));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/health'));
});

test('the settings page reports a healthy runtime', function () {
    runtimeServing('gemma-4-E4B-it-qat-UD-Q4_K_XL.gguf');
    config()->set('assistant.enabled', true);
    enableAssistantSetting(true);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assistant.state', Availability::AVAILABLE)
            ->etc()
        );
});

test('the settings page reports a runtime that is not answering', function () {
    // A 503 is what llama.cpp returns while it is still loading its weights,
    // and a refused connection is what an absent container gives. Both are
    // "not answering" as far as an admin is concerned.
    Http::fake(['*' => Http::response('', 503)]);
    config()->set('assistant.enabled', true);
    enableAssistantSetting(true);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assistant.state', Availability::UNREACHABLE)
            ->etc()
        );
});

// ---------------------------------------------------------------------------
// 2. The gate is where it was
// ---------------------------------------------------------------------------

test('explaining the assistant does not register a route for it', function () {
    config()->set('assistant.enabled', false);
    $admin = User::factory()->admin()->create();

    // The panel now tells this admin the feature exists. D25's whole point is
    // that reading about it must not make it reachable: 404, not 403, and not
    // a 200 carrying `available: false`.
    $this->actingAs($admin)->get('/admin/assistant')->assertOk();
    $this->actingAs($admin)->getJson('/assistant/status')->assertNotFound();
});

test('an admin who has read the panel still cannot switch on an absent runtime', function () {
    config()->set('assistant.enabled', false);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/assistant', [
        'default_currency' => 'EUR',
        'default_scale_calibration' => 'default',
        'public_assessments_allowed' => true,
        'assistant_enabled' => true,
        'session_lifetime_minutes' => 480,
    ])->assertSessionHasErrors('assistant_enabled');

    expect(InstanceSetting::singleton()->assistant_enabled)->toBeFalse();
});

test('a non-admin cannot read the deployment facts the panel shows', function () {
    config()->set('assistant.enabled', true);

    // The panel names the model file and the runtime's health. That is
    // infrastructure detail about the host, and /admin/settings is the only
    // place it is served — so the ordinary admin gate is also the gate on it.
    $this->actingAs(User::factory()->create())
        ->get('/admin/assistant')
        ->assertForbidden();
});
