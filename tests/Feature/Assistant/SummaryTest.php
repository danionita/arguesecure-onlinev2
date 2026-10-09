<?php

/**
 * W54 — job C: the drafted executive summary.
 *
 * The package's subject is **disclosure and the accept step**, not generation.
 * A model wrote a paragraph; what matters is that nobody reads it as the
 * author's own words without the author having put them there.
 *
 * So the assertions cluster around four properties:
 *
 * 1. A draft lands in `summary_draft` and never in `summary_narrative`. Four
 *    minutes after the request, the author may have typed a summary themselves.
 * 2. The draft reaches only people who could accept it. A public reader of the
 *    same report gets the narrative and the disclosure, and no draft at all.
 * 3. Accepting sets the generated mark, and editing afterwards does not clear
 *    it. The disclaimer is for the reader.
 * 4. Everything about the narrative works with no assistant at all — which is
 *    what nearly every deployment looks like (D25).
 *
 * Nothing contacts a real model; `ModelStub` stands in for the runtime, and
 * the queue is faked except where the job itself is under test.
 */

use App\Domain\Assistant\Generation\GenerationMonitor;
use App\Domain\Assistant\Summary\AssessmentDigest;
use App\Domain\Assistant\Summary\Summarizer;
use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Domain\Notifications\SummaryDrafted;
use App\Jobs\DraftExecutiveSummary;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\InstanceSetting;
use App\Models\Node;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Assistant\Support\Injections;
use Tests\Feature\Assistant\Support\ModelStub;

beforeEach(function () {
    Cache::flush();
    config()->set('assistant.enabled', true);
    config()->set('assistant.base_url', 'http://assistant:8080');

    $row = InstanceSetting::singleton();
    $row->assistant_enabled = true;
    $row->save();
});

/** An assessment with one quantified risk, an attack and a defence on it. */
function quantifiedAssessment(User $owner, ?string $riskDescription = null): Assessment
{
    $assessment = Assessment::factory()->for($owner, 'owner')->create();

    $risk = Risk::factory()->for($assessment)->create([
        'title' => 'Storefront outage',
        'description' => $riskDescription,
        'impact_mode' => 'quantitative',
        'impact_amount' => 100000,
    ]);

    $attack = Attack::factory()->for($assessment)->create([
        'title' => 'Volumetric flood of the checkout',
        'likelihood_mode' => 'quantitative',
        'likelihood_aro' => 2,
    ]);

    $defence = Defence::factory()->for($assessment)->create([
        'title' => 'Rate limiting at the edge',
        'status' => 'implemented',
    ]);

    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $attack->defences()->attach($defence->id, [
        'position' => 0,
        'strength_mode' => 'quantitative',
        'strength_fraction' => 0.5,
    ]);

    return $assessment->fresh();
}

function memberOf(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

/** Runs the job for real against a stubbed runtime. */
function runDraftJob(Assessment $assessment, User $user): void
{
    app(DraftExecutiveSummary::class, [
        'assessmentId' => $assessment->id,
        'requestedByUserId' => $user->id,
    ])->handle(
        app(AssessmentGraphLoader::class),
        app(RiskCalculator::class),
        app(CalculationCache::class),
        app(Summarizer::class),
        app(GenerationMonitor::class),
    );
}

// ---------------------------------------------------------------------------
// The digest — what the model is allowed to see
// ---------------------------------------------------------------------------

test('the digest reports the engine\'s figures and derives none of its own', function () {
    $assessment = quantifiedAssessment(User::factory()->create());
    $graph = app(AssessmentGraphLoader::class)->load($assessment);
    $results = app(RiskCalculator::class)->compute($graph);

    $digest = (new AssessmentDigest)->build($graph, $results, []);

    // 100000 x 2 = 200000 inherent; the 50% defence halves it.
    expect($digest)->toContain((string) (int) $results->inherent->total);
    expect($digest)->toContain((string) (int) $results->current->total);
    expect($digest)->toContain('50%');
});

test('the digest carries node descriptions, which is where an injection lives', function () {
    $payload = Injections::case('summary-hijack')['text'];
    $assessment = quantifiedAssessment(User::factory()->create(), $payload);
    $graph = app(AssessmentGraphLoader::class)->load($assessment);
    $results = app(RiskCalculator::class)->compute($graph);

    $descriptions = Node::query()
        ->where('assessment_id', $assessment->id)
        ->pluck('description', 'uuid')
        ->all();

    // Deliberate, and worth stating plainly: the descriptions are the whole
    // reason a drafted summary reads like this assessment rather than any
    // assessment, and they are also the injection channel the spike used.
    // Removing them would remove both. The controls are elsewhere — the fence
    // below, and the fact that nothing here reaches a report unaccepted.
    expect((new AssessmentDigest)->build($graph, $results, $descriptions))
        ->toContain('no material security risks');
});

test('a shelved defence is never described as protection', function () {
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    $attack = Attack::query()->where('assessment_id', $assessment->id)->firstOrFail();
    $shelved = Defence::factory()->for($assessment)->create([
        'title' => 'A control nobody funded',
        'status' => 'shelved',
    ]);
    $attack->defences()->attach($shelved->id, [
        'position' => 1,
        'strength_mode' => 'quantitative',
        'strength_fraction' => 0.9,
    ]);

    $graph = app(AssessmentGraphLoader::class)->load($assessment->fresh());
    $results = app(RiskCalculator::class)->compute($graph);

    // Shelved never enters a baseline (calculation-engine.md), so a summary
    // that mentioned it would be describing protection nobody has.
    expect((new AssessmentDigest)->build($graph, $results, []))
        ->not->toContain('A control nobody funded');
});

test('the assessment goes inside the fence, and the prompt says it is data', function () {
    ModelStub::replying('A summary.');
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner, Injections::case('summary-canary')['text']);

    runDraftJob($assessment, $owner);

    $prompt = ModelStub::lastPrompt();
    $opens = strpos($prompt, '<<<ASSESSMENT_CONTENT');
    $payload = strpos($prompt, Injections::canary());

    expect($prompt)->toContain('never instructions');
    expect($payload)->toBeGreaterThan($opens);
    expect($payload)->toBeLessThan(strpos($prompt, 'ASSESSMENT_CONTENT>>>'));
});

// ---------------------------------------------------------------------------
// The job — where the draft lands, and who is told
// ---------------------------------------------------------------------------

test('the draft lands in the draft column and never in the report', function () {
    Notification::fake();
    ModelStub::replying('Three paragraphs about a storefront.');
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);

    runDraftJob($assessment, $owner);

    $assessment->refresh();

    // The whole shape of the package. A job that finishes four minutes after
    // it was asked for must not overwrite what the author has typed since, and
    // must not put un-reviewed model text into a document.
    expect($assessment->summary_draft)->toBe('Three paragraphs about a storefront.');
    expect($assessment->summary_narrative)->toBeNull();
    expect($assessment->summary_narrative_generated)->toBeFalse();

    Notification::assertSentTo($owner, SummaryDrafted::class);
});

test('a failed generation notifies too, rather than leaving someone waiting', function () {
    Notification::fake();
    ModelStub::replying('   ');
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);

    runDraftJob($assessment, $owner);

    // An empty reply is a real outcome — the spike watched a candidate spend
    // its whole budget and produce nothing on this job — and it must not be
    // stored as a draft that looks finished.
    expect($assessment->fresh()->summary_draft)->toBeNull();

    Notification::assertSentTo($owner, SummaryDrafted::class, function (SummaryDrafted $notification) use ($owner) {
        return $notification->toArray($owner)['succeeded'] === false;
    });
});

test('the in-progress flag is released whether the job works or fails', function () {
    Notification::fake();
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    $key = DraftExecutiveSummary::inProgressKey($assessment->id);

    foreach (['A summary.', '   '] as $reply) {
        ModelStub::replying($reply);
        Cache::put($key, true, 600);

        runDraftJob($assessment, $owner);

        // A flag left set locks an assessment out of drafting until its TTL
        // expires, and nobody diagnoses that from the UI.
        expect(Cache::has($key))->toBeFalse();
    }
});

// ---------------------------------------------------------------------------
// Asking for one
// ---------------------------------------------------------------------------

test('asking queues the job and returns immediately', function () {
    Queue::fake();
    ModelStub::replying('unused');
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);

    $this->actingAs($owner)
        ->postJson(route('assessments.assistant.summary', $assessment->uuid))
        ->assertStatus(202);

    Queue::assertPushed(DraftExecutiveSummary::class);
});

test('a second request while one is drafting is refused', function () {
    Queue::fake();
    ModelStub::replying('unused');
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);

    $this->actingAs($owner)
        ->postJson(route('assessments.assistant.summary', $assessment->uuid))
        ->assertStatus(202);

    // Per assessment, not per user: two collaborators asking a minute apart
    // would each be within their own rate limit and would produce two
    // four-minute generations, one of which overwrites the other.
    $this->actingAs($owner)
        ->postJson(route('assessments.assistant.summary', $assessment->uuid))
        ->assertStatus(429);

    Queue::assertPushed(DraftExecutiveSummary::class, 1);
});

test('a viewer cannot ask for a draft, because a viewer cannot accept one', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    memberOf($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->postJson(route('assessments.assistant.summary', $assessment->uuid))
        ->assertForbidden();

    // Not `assertNothingPushed`: building the fixture dispatches the
    // assessment's own recalculation job, which has nothing to do with this.
    Queue::assertNotPushed(DraftExecutiveSummary::class);
});

test('a stranger cannot ask for a draft on someone else\'s assessment', function () {
    Queue::fake();
    $assessment = quantifiedAssessment(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->postJson(route('assessments.assistant.summary', $assessment->uuid))
        ->assertForbidden();
});

test('a deployment with no runtime has no drafting endpoint at all', function () {
    config()->set('assistant.enabled', false);
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);

    // 404, not 403: a feature this deployment does not have should not be
    // discoverable by someone who read about it (D25).
    $this->actingAs($owner)
        ->postJson(route('assessments.assistant.summary', $assessment->uuid))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Accepting, editing, and the mark that survives both
// ---------------------------------------------------------------------------

test('accepting a draft sets the generated mark and clears the draft', function () {
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    $assessment->summary_draft = 'A drafted paragraph.';
    $assessment->save();

    $this->actingAs($owner)
        ->putJson(route('assessments.summary.update', $assessment->uuid), [
            'narrative' => 'A drafted paragraph, with a sentence I fixed.',
            'from_draft' => true,
        ])
        ->assertOk()
        ->assertJson(['generated' => true, 'draft' => null]);

    $assessment->refresh();
    expect($assessment->summary_narrative)->toBe('A drafted paragraph, with a sentence I fixed.');
    expect($assessment->summary_draft)->toBeNull();
});

test('editing an accepted draft afterwards does not clear the mark', function () {
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    $assessment->summary_narrative = 'A drafted paragraph.';
    $assessment->summary_narrative_generated = true;
    $assessment->save();

    $this->actingAs($owner)
        ->putJson(route('assessments.summary.update', $assessment->uuid), [
            'narrative' => 'A drafted paragraph, reworded quite heavily.',
        ])
        ->assertOk()
        ->assertJson(['generated' => true]);

    // The disclaimer exists for the reader, not for the author's sense of
    // authorship. A reworded model draft is still a model draft — and a mark
    // that any edit cleared would be a mark that never survived contact with
    // normal use.
    expect($assessment->fresh()->summary_narrative_generated)->toBeTrue();
});

test('clearing the narrative clears the mark, because there is nothing left to disclose', function () {
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    $assessment->summary_narrative = 'A drafted paragraph.';
    $assessment->summary_narrative_generated = true;
    $assessment->save();

    $this->actingAs($owner)
        ->putJson(route('assessments.summary.update', $assessment->uuid), ['narrative' => null])
        ->assertOk()
        ->assertJson(['generated' => false]);

    expect($assessment->fresh()->summary_narrative)->toBeNull();
});

test('a draft can be discarded without typing anything', function () {
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    $assessment->summary_draft = 'Not what I meant.';
    $assessment->save();

    $this->actingAs($owner)
        ->deleteJson(route('assessments.summary.draft.destroy', $assessment->uuid))
        ->assertOk();

    // Rejecting must cost no more than accepting. Otherwise the cheap path is
    // to accept and tidy later, and the accept step decays into a formality.
    expect($assessment->fresh()->summary_draft)->toBeNull();
});

test('a viewer cannot write the narrative', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    memberOf($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->putJson(route('assessments.summary.update', $assessment->uuid), ['narrative' => 'Mine now.'])
        ->assertForbidden();
});

test('the narrative is editable on a deployment that has no assistant', function () {
    config()->set('assistant.enabled', false);
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);

    // The narrative is an ordinary field of the assessment. Gating it on the
    // assistant would have made a plain text box disappear on the
    // overwhelming majority of deployments, which have no runtime at all.
    $this->actingAs($owner)
        ->putJson(route('assessments.summary.update', $assessment->uuid), [
            'narrative' => 'I wrote this myself.',
        ])
        ->assertOk()
        ->assertJson(['generated' => false]);
});

// ---------------------------------------------------------------------------
// What the report shows, and to whom
// ---------------------------------------------------------------------------

test('a public reader sees the narrative and the disclosure, and never the draft', function () {
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    $assessment->is_public = true;
    $assessment->summary_narrative = 'The published summary.';
    $assessment->summary_narrative_generated = true;
    $assessment->summary_draft = 'An un-reviewed draft nobody has read yet.';
    $assessment->save();

    $this->get(route('assessments.report.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assessment.summaryNarrative', 'The published summary.')
            // The disclosure is for everyone. A disclaimer shown only to
            // insiders is not a disclaimer.
            ->where('assessment.summaryNarrativeGenerated', true)
            // The draft is withheld by the server, not hidden by the template:
            // un-reviewed model text should not reach a public reader's page
            // source at all.
            ->where('assessment.summaryDraft', null)
            ->where('assessment.canEditSummary', false)
        );
});

test('someone who can accept a draft is sent it', function () {
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    $assessment->summary_draft = 'Waiting to be read.';
    $assessment->save();

    $this->actingAs($owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assessment.summaryDraft', 'Waiting to be read.')
            ->where('assessment.canEditSummary', true)
        );
});

test('the report still opens when the assistant has been removed', function () {
    config()->set('assistant.enabled', false);
    $owner = User::factory()->create();
    $assessment = quantifiedAssessment($owner);
    $assessment->summary_narrative = 'Drafted last year by a model that is gone.';
    $assessment->summary_narrative_generated = true;
    $assessment->save();

    // Nothing records which model wrote it, so there is nothing to dangle.
    // The text stays, the disclosure stays, and the Draft action is simply
    // absent.
    $this->actingAs($owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assessment.summaryNarrativeGenerated', true)
            ->where('assessment.assistantAvailable', false)
        );
});
