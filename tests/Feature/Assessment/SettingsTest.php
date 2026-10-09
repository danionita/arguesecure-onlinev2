<?php

use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\User;

/*
 * Assessment settings (W44, item 12).
 *
 * Two of the four settings here are **display only** (PLAN-2.2.md D14), and
 * that is the property most at risk of quietly becoming false: a later
 * package adds "and if it is over tolerance, mark it high", and the tool
 * starts issuing verdicts that calculation-engine.md forbids it from issuing.
 * So the display-only claim is pinned as an executable assertion — the totals
 * before and after are compared figure for figure — rather than left as a
 * sentence in a docblock.
 */

function settingsMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

/** An assessment whose totals are non-zero, so a change to them would show. */
function valuedAssessment(): Assessment
{
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'impact_mode' => 'quantitative',
        'impact_amount' => 100000,
    ]);
    $attack = Attack::factory()->create([
        'assessment_id' => $assessment->id,
        'likelihood_mode' => 'quantitative',
        'likelihood_aro' => 2,
    ]);
    $defence = Defence::factory()->create([
        'assessment_id' => $assessment->id,
        'status' => 'implemented',
        'capex_amount' => 30000,
        'opex_annual_amount' => 1000,
    ]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $attack->defences()->attach($defence->id, [
        'position' => 0,
        'strength_mode' => 'quantitative',
        'strength_fraction' => 0.5,
    ]);

    return $assessment;
}

/**
 * @return array<string, mixed>
 *
 * No `title`/`description` here (W86, item 9): they live on the root node
 * and are edited there. `UpdateAssessmentSettingsRequest` now `prohibited`s
 * both — see "the settings form rejects a title or a description" below,
 * which is the guard that used to be exercised by this payload carrying them
 * on every call.
 */
function settingsPayload(array $over = []): array
{
    return array_merge([
        'currency' => 'EUR',
        'amortization_years' => 3,
        'risk_tolerance' => null,
    ], $over);
}

test('a viewer and an editor are both refused; a manager is not', function () {
    $assessment = Assessment::factory()->create();

    $viewer = User::factory()->create();
    settingsMember($assessment, $viewer, 'viewer');
    $editor = User::factory()->create();
    settingsMember($assessment, $editor, 'editor');
    $manager = User::factory()->create();
    settingsMember($assessment, $manager, 'manager');

    $url = route('assessments.settings.edit', $assessment->uuid);

    $this->actingAs($viewer)->get($url)->assertForbidden();
    // An editor may change every value in the assessment and still not this:
    // these settings decide what those values *mean*.
    $this->actingAs($editor)->get($url)->assertForbidden();
    $this->actingAs($manager)->get($url)->assertOk();

    $this->actingAs($editor)
        ->patch(route('assessments.settings.update', $assessment->uuid), settingsPayload())
        ->assertForbidden();
});

test('an unknown currency is rejected', function () {
    $assessment = Assessment::factory()->create();

    // Not merely cosmetic: the value reaches Intl.NumberFormat in the
    // browser, which throws a RangeError on an unknown code and would blank
    // every figure on the page.
    $this->actingAs($assessment->owner)
        ->patch(route('assessments.settings.update', $assessment->uuid), settingsPayload([
            'currency' => 'XXX',
        ]))
        ->assertSessionHasErrors('currency');

    $this->actingAs($assessment->owner)
        ->patch(route('assessments.settings.update', $assessment->uuid), settingsPayload([
            'currency' => 'not-a-code',
        ]))
        ->assertSessionHasErrors('currency');

    expect($assessment->fresh()->currency)->toBe('EUR');
});

test('a currency from the configured list is accepted and reaches the editor and the report', function () {
    $assessment = valuedAssessment();

    $this->actingAs($assessment->owner)
        ->patch(route('assessments.settings.update', $assessment->uuid), settingsPayload([
            'currency' => 'USD',
        ]))
        ->assertRedirect(route('assessments.settings.edit', $assessment->uuid));

    expect($assessment->fresh()->currency)->toBe('USD');

    $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->assertInertia(fn ($page) => $page->where('assessment.currency', 'USD'));

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment->uuid))
        ->assertInertia(fn ($page) => $page->where('report.assessment.currency', 'USD'));
});

test('changing the currency changes no figure — it is a label, not a conversion', function () {
    $assessment = valuedAssessment();

    $before = $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->viewData('page')['props']['results'];

    $this->actingAs($assessment->owner)
        ->patch(route('assessments.settings.update', $assessment->uuid), settingsPayload([
            'currency' => 'JPY',
        ]));

    $after = $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->viewData('page')['props']['results'];

    // Identical to the last digit. Someone switching EUR to JPY reasonably
    // expects conversion; nothing converts, and the honest behaviour is that
    // the same numbers are now labelled ¥. If that ever changes, this fails.
    expect($after)->toEqual($before);
});

test('the risk tolerance changes no computed figure — the pinned form of "display only"', function () {
    $assessment = valuedAssessment();

    $before = $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->viewData('page')['props']['results'];

    $this->actingAs($assessment->owner)
        ->patch(route('assessments.settings.update', $assessment->uuid), settingsPayload([
            'risk_tolerance' => 1000,
        ]));

    $after = $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->viewData('page')['props']['results'];

    /*
     * PLAN-2.2.md D14 and calculation-engine.md together: the tolerance draws
     * a marker and a badge, and it must never enter RiskCalculator or
     * band-map a result into low/medium/high. The temptation to "just" mark
     * an over-tolerance risk as high is exactly what this pins against — the
     * whole reason this tool reports currency rather than colours.
     */
    expect($after)->toEqual($before);
    expect($assessment->fresh()->risk_tolerance)->toBe(1000.0);
});

test('a zero tolerance is a statement, and blank is a different one', function () {
    $assessment = Assessment::factory()->create();
    $url = route('assessments.settings.update', $assessment->uuid);

    $this->actingAs($assessment->owner)->patch($url, settingsPayload(['risk_tolerance' => 0]));
    // Zero says "every euro of residual loss is worth arguing about". It must
    // survive as zero rather than being normalised back to "unset".
    expect($assessment->fresh()->risk_tolerance)->toBe(0.0);

    $this->actingAs($assessment->owner)->patch($url, settingsPayload(['risk_tolerance' => null]));
    expect($assessment->fresh()->risk_tolerance)->toBeNull();

    $this->actingAs($assessment->owner)
        ->patch($url, settingsPayload(['risk_tolerance' => -1]))
        ->assertSessionHasErrors('risk_tolerance');
});

test('the amortisation period is bounded and does move the figures it should', function () {
    $assessment = valuedAssessment();
    $url = route('assessments.settings.update', $assessment->uuid);
    $max = (int) config('arguesecure.max_amortization_years');

    $this->actingAs($assessment->owner)
        ->patch($url, settingsPayload(['amortization_years' => 0]))
        ->assertSessionHasErrors('amortization_years');
    $this->actingAs($assessment->owner)
        ->patch($url, settingsPayload(['amortization_years' => $max + 1]))
        ->assertSessionHasErrors('amortization_years');

    $before = $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->viewData('page')['props']['results']['current']['defences'][0]['annualCost'];

    $this->actingAs($assessment->owner)->patch($url, settingsPayload(['amortization_years' => 6]));

    $after = $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->viewData('page')['props']['results']['current']['defences'][0]['annualCost'];

    // 1,000 opex + 30,000 capex over 3 years = 11,000; over 6 = 6,000. This
    // is the one setting on the screen that changes a computed figure, and
    // the point of asserting it is that the cache must not serve the old one:
    // `calc_version` is bumped precisely because of this.
    expect($before)->toBe(11000.0);
    expect($after)->toBe(6000.0);
});

/**
 * W86, item 9. Title and description live on the root node and are edited
 * there, in the tree — this screen carrying its own copies was the two-
 * editors-for-one-field mistake, and the write went through the ordinary
 * `NodeObserver`/`RevisionRecorder` path on `$root->save()`, so the history
 * page was recording a title/description edit from a second place on top of
 * the tree's own (see `tests/Feature/History/RevisionCoverageTest.php`'s
 * `FIXED (W86)` guard for that half).
 *
 * The acceptance criterion is at the request class, not just the absence of
 * the Vue inputs: a removed input is not a removed endpoint, so this posts
 * both fields directly and asserts they are *rejected*, not merely ignored.
 */
test('the settings form rejects a title or a description', function () {
    $assessment = Assessment::factory()->create();
    $originalTitle = $assessment->rootNode->title;
    $originalDescription = $assessment->rootNode->description;

    $this->actingAs($assessment->owner)
        ->patch(route('assessments.settings.update', $assessment->uuid), settingsPayload([
            'title' => 'Renamed from settings',
            'description' => 'And described from here too.',
        ]))
        ->assertSessionHasErrors(['title', 'description']);

    // Neither reached the root node. A weaker guard — `nullable` instead of
    // `prohibited` — would let this request through silently and still pass
    // a test that only checked the response was not a 500.
    $root = $assessment->fresh()->rootNode;
    expect($root->title)->toBe($originalTitle);
    expect($root->description)->toBe($originalDescription);

    // One field alone is enough to trip the guard.
    $this->actingAs($assessment->owner)
        ->patch(route('assessments.settings.update', $assessment->uuid), settingsPayload([
            'title' => 'Renamed from settings',
        ]))
        ->assertSessionHasErrors('title');
});

test('the settings page offers the currency list and the assessment’s scales', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->assertInertia(fn ($page) => $page
            ->component('Assessment/Settings')
            ->has('currencies.EUR')
            ->where('assessment.currency', 'EUR')
            // Read-only, because no scale calibration screen exists anywhere
            // in the application — ui.md §8 describes one that was never
            // built. Naming the scales is information a manager otherwise
            // cannot reach at all.
            ->has('scales.impact')
            ->has('scales.likelihood')
            ->has('scales.strength')
        );
});

/**
 * W82, D41: Settings left the sidebar for `AssessmentActions` (rendered
 * from `Pages/Assessment/Show.vue`'s own `store.abilities.manage`, asserted
 * in `tests/js/pages/AssessmentShowActions.spec.ts`), and the shared
 * `nav.currentAssessment` Inertia prop this test used to assert on existed
 * for no other reason — `HandleInertiaRequests::currentAssessment()` is
 * gone with it. This is the negative half of that removal: a prop nothing
 * reads is exactly the kind of dead control CLAUDE.md's
 * `ReportOptions::$figures` write-up warns stays in a codebase for years,
 * and a reintroduced `nav.currentAssessment` computed for a sidebar that no
 * longer reads it would be that mistake again with an extra query on every
 * request inside an assessment.
 */
test('nav carries no currentAssessment prop — the sidebar does not gate on it any more', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->assertInertia(fn ($page) => $page->where('nav.authenticated', true)
            ->missing('nav.currentAssessment'));

    $this->actingAs($assessment->owner)
        ->get(route('assessments.index'))
        ->assertInertia(fn ($page) => $page->missing('nav.currentAssessment'));
});

test('only the amortisation period invalidates the calculation cache', function () {
    $assessment = valuedAssessment();
    $url = route('assessments.settings.update', $assessment->uuid);

    // Settle: since W86 (item 9) this screen never touches the root node at
    // all — title and description are gone from the payload — so this first
    // save is a plain baseline read, not a version-bumping write.
    $this->actingAs($assessment->owner)->patch($url, settingsPayload());
    $settled = $assessment->fresh()->calc_version;

    /*
     * Currency and risk tolerance are display only (PLAN-2.2.md D14). The
     * cache key is (assessment, calc_version, engine_version), so bumping it
     * for either would discard a correct result to recompute the identical
     * one on the next page load — on an assessment large enough for the cache
     * to matter, which is exactly when it is felt.
     */
    // As a *second* manager, deliberately. The root node is written only when
    // its content changed, and the reason that guard exists is `updated_by`:
    // stamping it on every save would make the model dirty, fire
    // NodeObserver, and bump the version on a change that touched no node at
    // all. With one actor throughout, `updated_by` never differs and the
    // guard is never reached — the test would pass with it removed.
    $secondManager = User::factory()->create();
    settingsMember($assessment, $secondManager, 'manager');

    $this->actingAs($secondManager)->patch($url, settingsPayload([
        'currency' => 'USD',
        'risk_tolerance' => 5000,
    ]));
    expect($assessment->fresh()->calc_version)->toBe($settled);

    /*
     * Amortisation does change computed figures — DefenceRanker spreads capex
     * over it — so the cached result is genuinely stale and must not be
     * served. Without this the report would keep showing costs amortised over
     * the old period until some unrelated edit happened to invalidate them.
     */
    $this->actingAs($assessment->owner)->patch($url, settingsPayload([
        'currency' => 'USD',
        'risk_tolerance' => 5000,
        'amortization_years' => 6,
    ]));
    expect($assessment->fresh()->calc_version)->toBeGreaterThan($settled);
});
