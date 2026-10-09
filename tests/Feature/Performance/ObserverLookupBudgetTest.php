<?php

use App\Domain\Structure\AssessmentUuids;
use App\Domain\Structure\LinkService;
use App\Events\LinkChanged;
use App\Events\NodeCreated;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * W68 — the observer layer stops re-reading what its caller already held.
 *
 * Two lookups fired on every single mutation in the application: the broadcast
 * channel's assessment uuid, and an edge's two endpoint nodes. Both were
 * invisible in the source of any one mutation and only showed up in a query
 * log, which is why they survived from W16 to W64 unnamed. See
 * CatalogInsertQueryBudgetTest for the pin that held them and the measurement
 * that closed it.
 *
 * The tests below split into two halves that must both hold, and the second is
 * the more important one: a memo that answers *fast* and *wrong* would make
 * every performance assertion here greener while broadcasting to channels
 * nobody is subscribed to.
 */

/**
 * Runs `$work` with the query log on and hands back both it and the log.
 *
 * The log rather than `DB::listen()`: a listener cannot be removed once
 * registered, so a test that measures twice counts the second run twice — the
 * mistake CatalogInsertQueryBudgetTest's own comment records making.
 *
 * @return array{0: mixed, 1: list<array<string, mixed>>}
 */
function w68Measure(callable $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $result = $work();

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return [$result, array_values($log)];
}

/**
 * SELECTs against one table in a captured log.
 *
 * Counting per table rather than in total is what makes these assertions
 * survive an unrelated query being added to the path: what must stay zero is
 * the observer layer reading `nodes` and `assessments` back, not the total.
 *
 * @param  list<array<string, mixed>>  $log
 */
function w68SelectsAgainst(array $log, string $table): int
{
    $pattern = '/^select\b.*\bfrom\s+`?'.preg_quote($table, '/').'`?/is';

    return count(array_filter(
        $log,
        fn (array $entry): bool => (bool) preg_match($pattern, (string) $entry['query']),
    ));
}

test('resolving an assessment uuid queries once however many times it is asked', function () {
    $assessment = Assessment::factory()->create();

    // Cold, deliberately. Creating the assessment created its root node, whose
    // NodeCreated broadcast has already resolved and memoised the uuid — which
    // is the feature working, and would leave this test measuring nothing.
    app()->forgetInstance(AssessmentUuids::class);
    $uuids = app(AssessmentUuids::class);

    [$answers, $log] = w68Measure(
        fn (): array => array_map(fn (): string => $uuids->forId($assessment->id), range(1, 20)),
    );

    expect(w68SelectsAgainst($log, 'assessments'))->toBe(1)
        ->and(array_unique($answers))->toBe([$assessment->uuid]);
});

test('resolving an assessment uuid costs nothing when the caller already loaded it', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $risk->setRelation('assessment', $assessment);

    [$answer, $log] = w68Measure(fn (): string => app(AssessmentUuids::class)->forNode($risk));

    expect($log)->toBe([])
        ->and($answer)->toBe($assessment->uuid);
});

/**
 * The guard on the memo itself, and the reason it is a scoped binding rather
 * than a static.
 *
 * A memo keyed by id that ever returns the wrong uuid broadcasts every one of
 * an assessment's events onto another assessment's presence channel — a
 * cross-tenant leak (security.md §8) dressed up as a performance win. Nothing
 * else in the suite would notice, because both assessments' own tests pass
 * when run alone.
 */
test('two assessments resolved through the same memo keep their own uuids', function () {
    $first = Assessment::factory()->create();
    $second = Assessment::factory()->create();
    $uuids = app(AssessmentUuids::class);

    expect($uuids->forId($first->id))->toBe($first->uuid)
        ->and($uuids->forId($second->id))->toBe($second->uuid)
        // Again, now that both are memoised, in the other order.
        ->and($uuids->forId($second->id))->toBe($second->uuid)
        ->and($uuids->forId($first->id))->toBe($first->uuid);
});

/*
 * The two link tests below fake the queue.
 *
 * Not to make the numbers look better: under the `sync` driver the debounced
 * RecalculateAssessment runs inside the measured block and reads the whole
 * graph back — legitimately, and budgeted separately by
 * CalculationCacheConsultedTest. Leaving it in would mean asserting a number
 * that is mostly somebody else's, and it would go red the next time the
 * calculation's own query shape changed. What is measured here is the mutation.
 */
test('linking an edge does not re-read the nodes or the assessment the caller held', function () {
    Queue::fake();

    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);

    // Warm the memo, as any real request has by the time it mutates anything:
    // it has already loaded the assessment to authorize the route.
    app(AssessmentUuids::class)->forId($assessment->id);

    [, $log] = w68Measure(fn () => app(LinkService::class)->linkRiskToAttack($risk, $attack));

    // `nodes` is what the observer and LinkChanged used to lazy-load three
    // times per edge; `assessments` is the channel lookup. What is left is the
    // edge INSERT, LinkService's own MAX(position) against `risk_attack`, and
    // the calc_version UPDATE — writes and one genuine lookup.
    expect(w68SelectsAgainst($log, 'nodes'))->toBe(0)
        ->and(w68SelectsAgainst($log, 'assessments'))->toBe(0)
        ->and($log)->toHaveCount(3);
});

test('linking a defence does not re-read its endpoints either', function () {
    Queue::fake();

    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    app(AssessmentUuids::class)->forId($assessment->id);

    [, $log] = w68Measure(fn () => app(LinkService::class)->linkAttackToDefence($attack, $defence));

    expect(w68SelectsAgainst($log, 'nodes'))->toBe(0)
        ->and(w68SelectsAgainst($log, 'assessments'))->toBe(0)
        ->and($log)->toHaveCount(3);
});

/**
 * The correctness half of `carryEndpoints()`.
 *
 * `setRelation()` is unchecked by design — it will happily attach a Defence
 * under the key `attack`, and the payload would then carry the wrong uuid to a
 * client that patches its store by uuid. Nothing about the shape of the code
 * catches a transposed pair, so it is asserted.
 */
test('an edge broadcast carries the endpoints it was actually built from', function () {
    Event::fake([LinkChanged::class]);

    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    app(LinkService::class)->linkAttackToDefence($attack, $defence);

    Event::assertDispatched(LinkChanged::class, fn (LinkChanged $event): bool => $event->assessmentUuid === $assessment->uuid
        && $event->edge === 'attack_defence'
        && $event->action === 'created'
        && $event->link['attack_uuid'] === $attack->uuid
        && $event->link['defence_uuid'] === $defence->uuid);
});

test('a risk_attack broadcast carries the endpoints it was actually built from', function () {
    Event::fake([LinkChanged::class]);

    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);

    app(LinkService::class)->linkRiskToAttack($risk, $attack);

    Event::assertDispatched(LinkChanged::class, fn (LinkChanged $event): bool => $event->assessmentUuid === $assessment->uuid
        && $event->edge === 'risk_attack'
        && $event->link['risk_uuid'] === $risk->uuid
        && $event->link['attack_uuid'] === $attack->uuid);
});

/**
 * Unlink goes through the same helper, and is the path where getting it wrong
 * would be quietest: a `deleted` broadcast on the wrong channel leaves the node
 * on every collaborator's screen after it is gone.
 */
test('unlinking broadcasts on the assessment the edge belonged to', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);

    app(LinkService::class)->linkRiskToAttack($risk, $attack);

    Event::fake([LinkChanged::class]);
    app(LinkService::class)->unlinkRiskFromAttack($risk, $attack);

    Event::assertDispatched(LinkChanged::class, fn (LinkChanged $event): bool => $event->assessmentUuid === $assessment->uuid
        && $event->action === 'deleted'
        && $event->link['risk_uuid'] === $risk->uuid);
});

test('a node broadcast still names its own assessment when several are in play', function () {
    Event::fake([NodeCreated::class]);

    $first = Assessment::factory()->create();
    $second = Assessment::factory()->create();

    Risk::factory()->create(['assessment_id' => $first->id, 'title' => 'First risk']);
    Risk::factory()->create(['assessment_id' => $second->id, 'title' => 'Second risk']);

    Event::assertDispatched(NodeCreated::class, fn (NodeCreated $event): bool => $event->node['title'] === 'First risk'
        && $event->assessmentUuid === $first->uuid);

    Event::assertDispatched(NodeCreated::class, fn (NodeCreated $event): bool => $event->node['title'] === 'Second risk'
        && $event->assessmentUuid === $second->uuid);
});
