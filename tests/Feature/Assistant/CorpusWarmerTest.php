<?php

/**
 * W95 (PLAN-2.6.md D57) — the help corpus is read into a freshly started
 * runtime once, before anyone asks.
 *
 * What matters is when it does *nothing*: on a deployment without the
 * feature, with the help switch off, on a runtime that has already served a
 * request, on one that is busy, and on one that will not say. A warm-up that
 * fired in any of those would be a minute and a half of CPU on every
 * scheduler tick. And when it does fire, it must cache the prefix a real
 * question reuses — a warm-up with a different prompt warms nothing.
 */

use App\Domain\Assistant\Help\CorpusWarmer;
use App\Domain\Assistant\Help\HelpAnswerer;
use App\Models\InstanceSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config()->set('assistant.enabled', true);
    config()->set('assistant.base_url', 'http://assistant:8080');

    $row = InstanceSetting::singleton();
    $row->assistant_enabled = true;
    $row->save();
});

/**
 * A runtime whose /slots answers with $slots — the shape llama.cpp's pinned
 * build returns — and which replies to any completion with one token.
 *
 * @param  list<array<string, mixed>>|null  $slots  null: /slots is disabled
 */
function runtimeWithSlots(?array $slots): void
{
    Http::preventStrayRequests();
    Http::fake([
        '*/health' => Http::response('', 200),
        '*/slots' => $slots === null
            ? Http::response(['error' => ['message' => 'This server does not support slots endpoint.']], 501)
            : Http::response($slots),
        '*/completions' => Http::response(
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'A']]]])."\ndata: [DONE]\n"
        ),
    ]);
}

/** @return list<Request> */
function completionRequests(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/completions'))
        ->map(fn (array $pair) => $pair[0])
        ->values()
        ->all();
}

/** Exactly what the pinned build returns before its first request. */
const FRESH_SLOT = ['id' => 0, 'n_ctx' => 12288, 'speculative' => false, 'is_processing' => false];

test('a freshly started runtime is warmed with the prefix a real question reuses', function () {
    runtimeWithSlots([FRESH_SLOT]);

    expect(app(CorpusWarmer::class)->warmIfFresh())->toBe(CorpusWarmer::WARMED);

    // A real question, afterwards, through the same fake.
    app(HelpAnswerer::class)->answer('How do I share an assessment?');

    [$warm, $real] = completionRequests();
    $upTo = strpos($real['messages'][0]['content'], '<<<USER_QUESTION');

    expect($upTo)->toBeGreaterThan(1000);
    // Byte for byte up to the question: this is what the runtime caches, and
    // a warm-up with any other prefix would have cached something no question
    // ever sends.
    expect(substr($warm['messages'][0]['content'], 0, $upTo))
        ->toBe(substr($real['messages'][0]['content'], 0, $upTo));
    // And it asks for one token: the cost worth moving is reading, not writing.
    expect($warm['max_tokens'])->toBe(1);
});

test('a runtime that has already served a request is not warmed again', function () {
    // After its first request a slot carries an id_task — including after
    // this class's own warm-up, which is what makes it once per start.
    runtimeWithSlots([FRESH_SLOT + ['id_task' => 337, 'n_prompt_tokens' => 6601]]);

    expect(app(CorpusWarmer::class)->warmIfFresh())->toBe(CorpusWarmer::ALREADY_USED);
    expect(completionRequests())->toBe([]);
});

test('a busy runtime is left alone', function () {
    runtimeWithSlots([['is_processing' => true] + FRESH_SLOT]);

    expect(app(CorpusWarmer::class)->warmIfFresh())->toBe(CorpusWarmer::BUSY);
    expect(completionRequests())->toBe([]);
});

test('a runtime that will not describe its slots is not guessed about', function () {
    // Started with --no-slots, or answering something else. Treating that as
    // "fresh" would warm it on every scheduler tick, forever.
    runtimeWithSlots(null);

    expect(app(CorpusWarmer::class)->warmIfFresh())->toBe(CorpusWarmer::UNKNOWN);
    expect(completionRequests())->toBe([]);
});

test('nothing is warmed for a help feature an admin switched off', function () {
    runtimeWithSlots([FRESH_SLOT]);
    $row = InstanceSetting::singleton();
    $row->assistant_help_enabled = false;
    $row->save();

    expect(app(CorpusWarmer::class)->warmIfFresh())->toBe(CorpusWarmer::UNAVAILABLE);
    Http::assertNothingSent();
});

test('a deployment with no runtime is never asked anything', function () {
    Http::fake();
    config()->set('assistant.enabled', false);

    expect(app(CorpusWarmer::class)->warmIfFresh())->toBe(CorpusWarmer::UNAVAILABLE);
    Http::assertNothingSent();
});

test('the scheduler runs it every minute, in the background, only where there is a runtime', function () {
    runtimeWithSlots([FRESH_SLOT]);

    $this->artisan('assistant:warm')
        ->expectsOutput('Help corpus: warmed.')
        ->assertExitCode(0);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'assistant:warm'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('* * * * *');
    // A warm-up takes as long as the question it saves; in the foreground it
    // would hold up the version saver behind it.
    expect($event->runInBackground)->toBeTrue();
    expect($event->withoutOverlapping)->toBeTrue();
    // Minutes, not Laravel's default 1440: a warm-up killed mid-run (the
    // scheduler restarted with the runtime) never releases the lock, and a
    // day-long one would skip every warm-up until tomorrow. The value, not
    // the presence — CLAUDE.md's rule for guards.
    expect($event->expiresAt)->toBe(10);

    // And not at all on a deployment without the assistant.
    config()->set('assistant.enabled', false);
    expect($event->filtersPass(app()))->toBeFalse();
});
