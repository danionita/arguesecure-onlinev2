<?php

/**
 * The one test that talks to a real model (W59).
 *
 * ## Why it exists, and why it is opt-in
 *
 * Everything else in `tests/Feature/Assistant/**` runs against `ModelStub`,
 * deliberately: a suite that pulled a 2.5 GB GGUF would be slow,
 * non-deterministic and impossible in CI. That was the right decision and it
 * stays.
 *
 * It also cannot catch a whole class of defect, and one of them shipped. The
 * client posted **raw prompts** to llama.cpp's `/completion`, which applies no
 * chat template; `gemma-3-4b-it` saw a finished document and stopped, so job A
 * and job C returned an **empty string on every run**. A stub replies to
 * whatever it is asked, so every test stayed green. The 2.3 spike measured
 * through Ollama, which applies the template for you. The runtime comparison
 * that chose llama.cpp exercised job B — the one job whose prompt happens to
 * invite a continuation. Three reasonable decisions, and the defect lived in
 * the gap between them (docs/spikes/2.3-threads.md).
 *
 * So: one test, against a real runtime, asserting the only thing a stub can
 * never assert — **that a model actually answers**. It skips when there is no
 * runtime configured, which is every CI run and every ordinary `pest`.
 *
 * ## Running it
 *
 *   docker run -d --name arsec-live -p 11438:8080 \
 *     -v "$PWD/data-spike-models":/models:ro \
 *     ghcr.io/ggml-org/llama.cpp:server \
 *     -m /models/gemma-3-4b-it-Q4_K_M.gguf -c 12288 -t 8 --host 0.0.0.0 --port 8080
 *
 *   ASSISTANT_LIVE_URL=http://localhost:11438 vendor/bin/pest --filter=LiveRuntime
 *
 * Minutes, not seconds, and it takes every core it is given. See
 * `docs/specs/deployment.md` §9.2 for fetching the model, including why the
 * byte count has to be checked.
 */

use App\Domain\Assistant\Help\HelpAnswerer;
use App\Domain\Assistant\Suggestions\Suggester;
use App\Models\Assessment;
use App\Models\InstanceSetting;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $url = env('ASSISTANT_LIVE_URL');

    if (! is_string($url) || $url === '') {
        // Skipped, not failed. A missing runtime is the ordinary state of this
        // repository and of CI; the point of the test is that it is *runnable*
        // before a release, not that it runs every time.
        test()->markTestSkipped('Set ASSISTANT_LIVE_URL to run the live contract tests.');
    }

    Cache::flush();
    config()->set('assistant.enabled', true);
    config()->set('assistant.base_url', $url);
    // Generous: a first help answer measured 134s on four cores, and this
    // suite is deliberately not in a hurry.
    config()->set('assistant.timeouts.total', 900);

    $row = InstanceSetting::singleton();
    $row->assistant_enabled = true;
    $row->save();
});

test('a real model actually answers a help question', function () {
    $answer = app(HelpAnswerer::class)->answer('What is a transfer?');

    // The assertion that would have caught the defect: not "is it correct",
    // which no test can decide, but "is there anything there at all". The
    // shipped client returned '' here, every time, on the recommended model.
    expect(trim($answer))->not->toBe('')
        ->and(mb_strlen($answer))->toBeGreaterThan(40);
})->group('live');

test('a real model returns suggestions the parser can read', function () {
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->for($owner, 'owner')->create();
    $risk = Risk::factory()->for($assessment)->create([
        'title' => 'Customer account takeover',
        'description' => 'An attacker gains control of a customer account on the web shop.',
    ]);

    $suggestions = app(Suggester::class)->suggest($assessment, $risk, 3);

    // Through the real parser, so this covers the whole path: template
    // applied, JSON located inside whatever fencing the model chose, and the
    // allowlist keeping `title` and `description` only.
    expect($suggestions)->not->toBeEmpty()
        ->and($suggestions[0]['title'])->not->toBe('')
        ->and(array_keys($suggestions[0]))->toBe(['title', 'description']);
})->group('live');
