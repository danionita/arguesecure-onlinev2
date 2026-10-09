<?php

use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Events\LinkChanged;
use App\Events\NodeCreated;
use App\Events\NodeUpdated;
use App\Events\TotalsRecomputed;
use App\Jobs\RecalculateAssessment;
use App\Models\Assessment;
use App\Models\Risk;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/*
 * PLAN.md Phase 6 item 4: "a queued RecalculateAssessment job debounced
 * 250ms per assessment, so dragging a slider produces one broadcast
 * rather than forty." Required by the W16 brief: "the debounce collapses
 * rapid changes into one recompute."
 *
 * Queue::fake() replaces the queue manager outright — it records what was
 * pushed without running it (unlike the sync driver phpunit.xml
 * configures, which would execute each dispatch immediately and clear the
 * debounce flag before the next one lands) — which is what makes a burst
 * of rapid mutations observable as "how many jobs got pushed" rather than
 * "how many ran".
 */
afterEach(function () {
    $keys = Redis::keys('calc:pending:*');
    if ($keys !== []) {
        Redis::del($keys);
    }
});

test('several rapid node updates collapse into a single queued RecalculateAssessment', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    Queue::fake();
    Event::fake([NodeUpdated::class, NodeCreated::class, LinkChanged::class]);

    // Five rapid saves — a slider being dragged — each individually
    // touching the node the calculation depends on.
    for ($i = 0; $i < 5; $i++) {
        $risk->title = "Title {$i}";
        $risk->save();
    }

    Queue::assertPushed(RecalculateAssessment::class, 1);
    Queue::assertPushed(fn (RecalculateAssessment $job) => $job->assessmentId === $assessment->id);
});

test('bursts for two different assessments each get their own debounced job', function () {
    $assessmentA = Assessment::factory()->create();
    $assessmentB = Assessment::factory()->create();
    $riskA = Risk::factory()->create(['assessment_id' => $assessmentA->id]);
    $riskB = Risk::factory()->create(['assessment_id' => $assessmentB->id]);

    Queue::fake();
    Event::fake([NodeUpdated::class, NodeCreated::class]);

    $riskA->title = 'A1';
    $riskA->save();
    $riskB->title = 'B1';
    $riskB->save();
    $riskA->title = 'A2';
    $riskA->save();

    Queue::assertPushed(RecalculateAssessment::class, 2);
});

test('running the debounced job clears the flag, recomputes, caches and broadcasts totals', function () {
    $assessment = Assessment::factory()->create();
    Risk::factory()->create(['assessment_id' => $assessment->id, 'impact_mode' => 'quantitative', 'impact_amount' => 1000]);

    Event::fake([TotalsRecomputed::class]);

    // The observer already queued a job (real dispatch, not faked, since
    // this test is about what the job itself does once it runs) —
    // running it directly is equivalent to the queue worker picking it up.
    (new RecalculateAssessment($assessment->id))->handle(
        app(AssessmentGraphLoader::class),
        app(RiskCalculator::class),
        app(CalculationCache::class),
    );

    expect(Redis::get("calc:pending:{$assessment->id}"))->toBeNull();
    expect((new CalculationCache)->get($assessment->fresh()))->not->toBeNull();

    Event::assertDispatched(TotalsRecomputed::class, fn (TotalsRecomputed $event) => $event->assessmentUuid === $assessment->uuid);
});

test('the debounced job is a no-op if the assessment was deleted before it ran', function () {
    $assessment = Assessment::factory()->create();
    $assessmentId = $assessment->id;
    $assessment->delete();

    // Must not throw.
    (new RecalculateAssessment($assessmentId))->handle(
        app(AssessmentGraphLoader::class),
        app(RiskCalculator::class),
        app(CalculationCache::class),
    );

    expect(true)->toBeTrue();
});
