<?php

use App\Domain\Sharing\ShareTokenService;
use App\Events\NodeCreated;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Audit/Capture.php';

/*
 * routes/channels.php's `assessment.{assessmentUuid}` presence channel is
 * the fix for security.md defect #2 — the legacy relay broadcast every
 * assessment to any anonymous socket, filtering privacy client-side. Every
 * case here is "who may join the socket", mirroring
 * tests/Feature/Assessment/AssessmentShowTest.php's coverage of "who may
 * see the HTTP route" — the channel deliberately reuses AssessmentPolicy
 * rather than inventing a parallel authorization scheme (every route touching an
 * assessment goes through AssessmentPolicy, no exceptions).
 *
 * phpunit.xml pins BROADCAST_CONNECTION=null for the suite (so nothing
 * requires a live Reverb process), but Laravel's NullBroadcaster's auth()
 * is a deliberate no-op — it never consults routes/channels.php at all, so
 * POST /broadcasting/auth would return an empty 200 for every caller under
 * it regardless of authorization. Swapping the *resolved* config to the
 * real `reverb` connection (Pusher-protocol) here, rather than editing
 * phpunit.xml, exercises the actual
 * authorization path: Reverb's broadcaster signs presence/private auth
 * responses entirely locally (HMAC over the request), so this needs no
 * network call and no running Reverb process — only the app's own
 * Broadcast::channel() registration, which is exactly what these tests
 * verify.
 *
 * routes/channels.php's `Broadcast::channel()` calls ran once already, at
 * boot, against whatever the *original* default connection was (`null`);
 * switching `broadcasting.default` afterwards does not retroactively move
 * those registrations onto a freshly-resolved `reverb` broadcaster
 * instance, so channels.php is re-required after the swap to register them
 * on the connection this test actually exercises. It has no side effects
 * beyond that registration (no classes/functions declared), so re-running
 * it is safe.
 */
beforeEach(function () {
    // Assessment::factory()->create() creates a root node under the hood,
    // which — with `broadcasting.default` swapped to `reverb` below for
    // the rest of this file — would otherwise make NodeObserver's
    // NodeCreated a *real* queued broadcast (QUEUE_CONNECTION=sync in
    // phpunit.xml runs it inline) attempting an actual HTTP call to a
    // Reverb server that isn't running. These tests are about channel
    // *authorization*, not about App\Events\** or the
    // recalculation job, so both are faked off here; the events and the
    // debounced recompute each have their own dedicated test files.
    Event::fake([NodeCreated::class]);
    Queue::fake();

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        // Explicit, not relying on config/broadcasting.php's env()-derived
        // defaults: .env.testing carries no REVERB_HOST, so those default
        // to a *present* `options.host => null`, and the Pusher SDK only
        // falls back to its own default host when the 'host' key is
        // entirely absent (array_key_exists check) — present-but-null
        // survives straight through to `"https://" . null . ":443"`,
        // which throws building any URL. Auth signing itself never needs a
        // real host (it is a local HMAC, no request goes out), so any
        // non-empty value is sufficient here.
        'broadcasting.connections.reverb.options' => [
            'host' => 'localhost',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
        ],
    ]);
    require base_path('routes/channels.php');
});

function presenceAuthPayload(Assessment $assessment): array
{
    return [
        'socket_id' => '1234.5678',
        'channel_name' => 'presence-assessment.'.$assessment->uuid,
    ];
}

test('an anonymous caller cannot reach the broadcasting auth endpoint at all', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);

    $this->postJson('/broadcasting/auth', presenceAuthPayload($assessment))
        ->assertUnauthorized();
});

test('an unrelated user is rejected from the presence channel', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson('/broadcasting/auth', presenceAuthPayload($assessment))
        ->assertForbidden();
});

test('the owner is authorized to join the presence channel', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->postJson('/broadcasting/auth', presenceAuthPayload($assessment))
        ->assertOk()
        ->assertJsonStructure(['channel_data']);
});

test('a shared collaborator is authorized to join the presence channel', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    $assessment->members()->attach($viewer->id, [
        'role' => 'viewer',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->postJson('/broadcasting/auth', presenceAuthPayload($assessment))
        ->assertOk();
});

test('an unrelated user is rejected even when the assessment does not exist', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'presence-assessment.not-a-real-uuid',
        ])
        ->assertForbidden();
});

test('is_public does not admit a stranger to the presence channel, though they may read the assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => true]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('assessments.show', $assessment))->assertOk();

    $this->actingAs($stranger)
        ->postJson('/broadcasting/auth', presenceAuthPayload($assessment))
        ->assertForbidden();
});

test('a share-link session does not admit a signed-in account to the presence channel', function () {
    $assessment = Assessment::factory()->create();
    $token = ShareTokenService::generate($assessment->fresh());
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('share.resolve', $token))->assertRedirect();
    $this->actingAs($stranger)->get(route('assessments.show', $assessment))->assertOk();

    $this->actingAs($stranger)
        ->postJson('/broadcasting/auth', presenceAuthPayload($assessment))
        ->assertForbidden();
});

test('an admin is authorized to join the presence channel', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/broadcasting/auth', presenceAuthPayload($assessment))
        ->assertOk();
});

test('every collaborator role is authorized to join the presence channel', function (string $role) {
    $assessment = Assessment::factory()->create();
    $member = User::factory()->create();
    $assessment->members()->attach($member->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($member)
        ->postJson('/broadcasting/auth', presenceAuthPayload($assessment))
        ->assertOk();
})->with(['viewer', 'commenter', 'editor', 'manager']);

test('a refused subscription is not written to the security log as a denial', function () {
    $assessment = Assessment::factory()->create(['is_public' => true]);
    $log = captureSecurityLog();

    $this->actingAs(User::factory()->create())
        ->postJson('/broadcasting/auth', presenceAuthPayload($assessment))
        ->assertForbidden();

    expect(array_column(securityLines($log), 'event'))->not->toContain('authz.denied');
});
