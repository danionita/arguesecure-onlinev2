<?php

/**
 * W58 — progress, and a Stop that actually stops.
 *
 * ## What this package is really about
 *
 * CPU inference is slow enough to change what the interface owes a person: 94
 * seconds for the first help answer of a session, 28 for a suggestion, 249 for
 * a summary draft — measured, on a workstation faster than most deployment
 * hosts. A spinner that says nothing for four minutes is indistinguishable
 * from a hang, and what somebody does about an apparent hang is reload and
 * click again, which costs the shared CPU another four minutes.
 *
 * So the assertions here cluster on four properties:
 *
 * 1. A running generation is visible to the person waiting on it.
 * 2. Stop stops the *generation*, not merely the waiting. A cancel that only
 *    closed the browser tab would leave the CPU busy and the lock held.
 * 3. A cancelled generation is **not a failure**: nothing is reported, nothing
 *    is logged as broken, and no error is put in front of the person who asked
 *    for it.
 * 4. A cancellation that arrives while a queued draft is still waiting for a
 *    worker survives until that worker picks it up.
 *
 * Nothing here contacts a real runtime; `ModelStub` stands in for it.
 */

use App\Domain\Assistant\Generation\GenerationCancelled;
use App\Domain\Assistant\Generation\GenerationMonitor;
use App\Domain\Assistant\Generation\SingleFlight;
use App\Domain\Assistant\Summary\Summarizer;
use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Domain\Notifications\SummaryDrafted;
use App\Jobs\DraftExecutiveSummary;
use App\Models\Assessment;
use App\Models\InstanceSetting;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Assistant\Support\ModelStub;

beforeEach(function () {
    Cache::flush();
    config()->set('assistant.enabled', true);
    config()->set('assistant.base_url', 'http://assistant:8080');

    $row = InstanceSetting::singleton();
    $row->assistant_enabled = true;
    $row->save();
});

/** @return array{0: User, 1: Assessment, 2: Risk} */
function progressFixture(): array
{
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->for($owner, 'owner')->create();
    $risk = Risk::factory()->for($assessment)->create(['title' => 'Data loss']);

    return [$owner, $assessment, $risk];
}

/** `assessment_user` carries invitation columns with no defaults (schema.md §3.8). */
function collaborate(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// The monitor itself
// ---------------------------------------------------------------------------

test('a generation that is running is visible to the person waiting on it', function () {
    [$owner] = progressFixture();

    $monitor = app(GenerationMonitor::class);
    $monitor->start(GenerationMonitor::userScope($owner), 512);

    $this->actingAs($owner)
        ->getJson(route('assistant.generation.show'))
        ->assertOk()
        ->assertJson(['running' => true, 'tokens' => 0, 'expected_tokens' => 512]);
});

test('nothing running reads as nothing running, not as an error', function () {
    [$owner] = progressFixture();

    // A page that polls while nothing is happening is the ordinary case — the
    // report page probes once on load — and it must be a plain answer rather
    // than a 404 the client has to interpret.
    $this->actingAs($owner)
        ->getJson(route('assistant.generation.show'))
        ->assertOk()
        ->assertJson(['running' => false]);
});

test('the observer stops the generation once, and only once, someone has cancelled', function () {
    [$owner] = progressFixture();

    $monitor = app(GenerationMonitor::class);
    $scope = GenerationMonitor::userScope($owner);
    $monitor->start($scope, 512);

    $observer = $monitor->observer($scope);

    // Nothing cancelled: the callback counts a token and returns.
    $observer('hello');

    $monitor->cancel($scope);

    // Throwing from inside the callback is the mechanism — it unwinds out of
    // Client::readStream, which abandons the response body, which closes the
    // connection, which is how llama.cpp is told to stop. There is no cancel
    // endpoint on its side and none is needed.
    expect(fn () => $observer(' world'))->toThrow(GenerationCancelled::class);
});

test('stopping releases the progress row so the next generation starts clean', function () {
    [$owner] = progressFixture();

    $monitor = app(GenerationMonitor::class);
    $scope = GenerationMonitor::userScope($owner);

    $monitor->start($scope, 512);
    $monitor->finish($scope);

    expect($monitor->progress($scope))->toBeNull()
        ->and($monitor->isCancelled($scope))->toBeFalse();
});

test('a stale cancellation cannot kill the next generation', function () {
    [$owner] = progressFixture();

    $monitor = app(GenerationMonitor::class);
    $scope = GenerationMonitor::userScope($owner);

    // The race the reset()/start() split exists for: a Stop that lands just
    // after its generation ended. Left in the cache it would kill whatever
    // came next, a second after it started — an assistant that appears to be
    // intermittently broken, with nothing in the logs to attribute it to.
    $monitor->cancel($scope);
    $monitor->reset($scope);
    $monitor->start($scope, 512);

    expect($monitor->isCancelled($scope))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Job B, cancelled mid-generation
// ---------------------------------------------------------------------------

test('a cancelled suggestion answers 200, writes nothing, and is not an error', function () {
    [$owner, $assessment, $risk] = progressFixture();

    // Stop pressed *while the runtime is generating*, which is the real
    // sequence: the controller resets any stale flag before it starts, so a
    // cancellation set before the request would have been cleared — as it
    // should be. Setting it from inside the fake runtime puts it exactly where
    // a person's Stop lands, and the observer sees it on the next token.
    ModelStub::replying(function () use ($owner) {
        app(GenerationMonitor::class)->cancel(GenerationMonitor::userScope($owner));

        return '[{"title": "Phishing", "description": "A plausible one."}]';
    });

    $before = $assessment->nodes()->count();

    $this->actingAs($owner)
        ->postJson(route('assessments.assistant.suggestions', $assessment), [
            'parent_uuid' => $risk->uuid,
            'count' => 3,
        ])
        ->assertOk()
        // Not 500, not 503, and no `message`. The person asked for this.
        ->assertJson(['cancelled' => true]);

    expect($assessment->nodes()->count())->toBe($before);
});

test('a cancelled generation releases the lock rather than holding it for its TTL', function () {
    [$owner, $assessment, $risk] = progressFixture();

    ModelStub::replying(function () use ($owner) {
        app(GenerationMonitor::class)->cancel(GenerationMonitor::userScope($owner));

        return '[{"title": "Phishing", "description": "A plausible one."}]';
    });

    $this->actingAs($owner)
        ->postJson(route('assessments.assistant.suggestions', $assessment), [
            'parent_uuid' => $risk->uuid,
        ])
        ->assertOk();

    // Otherwise stopping one generation would lock the user out of the
    // assistant for the whole generation timeout — a Stop button that
    // punishes the person who presses it.
    expect(app(SingleFlight::class)->isRunningFor($owner))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Job C, cancelled in the queue
// ---------------------------------------------------------------------------

test('a draft cancelled while it waits for a worker never reaches the runtime', function () {
    [$owner, $assessment] = progressFixture();

    Notification::fake();
    ModelStub::replying('A summary nobody asked to keep.');

    Cache::put(DraftExecutiveSummary::inProgressKey($assessment->id), true, 600);
    app(GenerationMonitor::class)->cancel(GenerationMonitor::assessmentScope($assessment));

    (new DraftExecutiveSummary($assessment->id, $owner->id))
        ->handle(
            app(AssessmentGraphLoader::class),
            app(RiskCalculator::class),
            app(CalculationCache::class),
            app(Summarizer::class),
            app(GenerationMonitor::class),
        );

    // The gap between queueing a draft and a worker picking it up is exactly
    // when somebody realises they did not mean to ask. Starting a four-minute
    // generation they have already stopped is the worst reading of the
    // request, so the flag is checked before anything expensive happens.
    Http::assertNothingSent();

    expect($assessment->fresh()->summary_draft)->toBeNull()
        ->and(Cache::has(DraftExecutiveSummary::inProgressKey($assessment->id)))->toBeFalse();

    Notification::assertSentTo($owner, SummaryDrafted::class);
});

test('queueing a draft clears a cancellation left over from the last one', function () {
    [$owner, $assessment] = progressFixture();

    Http::fake(['*/health' => Http::response('', 200)]);

    // The queue is faked so the job does not run inline. Without this the test
    // passes whether or not the controller resets: the job would run, see the
    // flag, cancel itself, and clear it in its own `finally` — which is
    // correct behaviour that happens to hide the thing being asserted. Found
    // by mutation-testing this guard and watching it stay green.
    Queue::fake();

    $monitor = app(GenerationMonitor::class);
    $monitor->cancel(GenerationMonitor::assessmentScope($assessment));

    $this->actingAs($owner)
        ->postJson(route('assessments.assistant.summary', $assessment))
        ->assertAccepted();

    // Cleared at queue time rather than by the job, because the job is what a
    // Stop pressed *during* the wait has to survive.
    expect($monitor->isCancelled(GenerationMonitor::assessmentScope($assessment)))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Who may watch, and who may stop
// ---------------------------------------------------------------------------

test('a viewer can neither watch nor stop an assessment draft', function () {
    [, $assessment] = progressFixture();

    $viewer = User::factory()->create();
    collaborate($assessment, $viewer, 'viewer');

    // Same ability as starting one, enforced by the same FormRequest class —
    // not merely the same word — so the two cannot drift apart.
    $this->actingAs($viewer)
        ->getJson(route('assessments.assistant.summary.progress', $assessment))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('assessments.assistant.summary.cancel', $assessment))
        ->assertForbidden();
});

test('an editor who did not start the draft can still stop it', function () {
    [, $assessment] = progressFixture();

    $editor = User::factory()->create();
    collaborate($assessment, $editor, 'editor');

    // The scope is the assessment, deliberately. A four-minute generation
    // started by someone who has since closed their laptop must be stoppable
    // by whoever is still in the room.
    $this->actingAs($editor)
        ->deleteJson(route('assessments.assistant.summary.cancel', $assessment))
        ->assertAccepted();

    expect(app(GenerationMonitor::class)
        ->isCancelled(GenerationMonitor::assessmentScope($assessment)))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Rates, and absence
// ---------------------------------------------------------------------------

test('polling is not rated as though it were inference', function () {
    [, $assessment] = progressFixture();

    foreach ([
        route('assistant.generation.show'),
        route('assessments.assistant.summary.progress', $assessment),
    ] as $uri) {
        $route = app('router')->getRoutes()->match(
            Request::create($uri, 'GET')
        );

        $throttles = collect($route->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))
            ->values()
            ->all();

        // Asserting the *value*, not that a throttle is present. The 2.0.1
        // release notes record why: a presence check could not see the tighter
        // limit being dropped, because the looser one also matches the prefix.
        // Here the failure would be the opposite direction — polling silently
        // moved into the 6/min inference bucket, which makes watching a
        // four-minute job impossible after its first thirty seconds.
        expect($throttles)->toContain('throttle:authenticated')
            ->and($throttles)->not->toContain('throttle:assistant');
    }
});

test('a deployment with no runtime serves none of these routes', function () {
    [$owner, $assessment] = progressFixture();

    config()->set('assistant.enabled', false);

    // D25 again: 404, not 403, and it holds for the progress routes as much as
    // for the generating ones. A deployment without an assistant does not
    // advertise one by answering questions about it.
    $this->actingAs($owner)->getJson(route('assistant.generation.show'))->assertNotFound();
    $this->actingAs($owner)->deleteJson(route('assistant.generation.destroy'))->assertNotFound();
    $this->actingAs($owner)
        ->getJson(route('assessments.assistant.summary.progress', $assessment))
        ->assertNotFound();
});
