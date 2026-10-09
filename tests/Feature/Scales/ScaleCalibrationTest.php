<?php

/**
 * The scale calibration screen (W65, ui.md §8).
 *
 * The last specified-but-unbuilt feature. What is asserted here is not "the
 * form saves" — it is the four things that make editing a scale safe to offer
 * at all:
 *
 * 1. **Editing a system scale never touches another assessment.** A system
 *    scale is shared by every assessment on the instance, so an in-place edit
 *    would silently move every figure in all of them. This is the legacy
 *    global-tags defect (schema.md §3.11) with money attached, and the clone
 *    is the only thing standing between this feature and it.
 * 2. **Stored values survive the clone.** Every qualitative value points at a
 *    `scale_levels.id`. After a clone those ids belong to a scale the
 *    assessment no longer loads, so an unrepointed value reads as *missing* and
 *    leaves the total — understating risk, invisibly, which is the one
 *    direction this codebase never rounds in.
 * 3. **Removing a band is reported, not absorbed.** Its values are cleared and
 *    counted, because someone spent a workshop arriving at them.
 * 4. **The five invariants hold**, checked by `ScaleValidator` before anything
 *    is written — so a rejected calibration leaves no half-applied scale.
 */

use App\Domain\Scales\ScaleCalibrator;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** The bands of an assessment's current scale, in the shape the endpoint takes. */
function bandsOf(Assessment $assessment, string $kind): array
{
    $scale = Scale::query()->findOrFail($assessment->getAttribute("{$kind}_scale_id"));

    return $scale->levels->map(fn (ScaleLevel $level): array => [
        'code' => $level->code,
        'label' => $level->label,
        'lower_bound' => (float) $level->lower_bound,
        'upper_bound' => $level->upper_bound === null ? null : (float) $level->upper_bound,
        'representative' => (float) $level->representative,
    ])->values()->all();
}

function calibrateAs(User $actor, Assessment $assessment, string $kind, array $bands): TestResponse
{
    return test()->actingAs($actor)
        ->put(route('assessments.scales.update', [$assessment, $kind]), ['bands' => $bands]);
}

// --- the clone, which is the whole point ---------------------------------

test('editing a shared system scale gives this assessment its own copy', function () {
    $assessment = Assessment::factory()->create();
    $systemScaleId = $assessment->impact_scale_id;

    expect(Scale::query()->findOrFail($systemScaleId)->is_system)->toBeTrue();

    $bands = bandsOf($assessment, 'impact');
    $bands[0]['label'] = 'Trivial';

    calibrateAs($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    $assessment->refresh();

    expect($assessment->impact_scale_id)->not->toBe($systemScaleId)
        ->and($assessment->impactScale->is_system)->toBeFalse()
        ->and($assessment->impactScale->assessment_id)->toBe($assessment->id);
})->group('scales');

test('editing a shared scale leaves every other assessment untouched', function () {
    $mine = Assessment::factory()->create();
    $theirs = Assessment::factory()->create();

    // Pointed at one shared row explicitly. AssessmentFactory builds a fresh
    // system scale per assessment, which is convenient for isolation and is
    // *not* what production does — AssessmentCreator looks up the one seeded
    // system scale per kind and every assessment on the instance ends up
    // sharing it. Relying on the factory here would have quietly tested a
    // world where this defect cannot happen.
    $theirs->impact_scale_id = $mine->impact_scale_id;
    $theirs->save();

    $systemScaleId = $mine->impact_scale_id;
    $before = Scale::query()->findOrFail($systemScaleId)->levels
        ->map(fn (ScaleLevel $l): array => [$l->code, (float) $l->representative])->all();

    $bands = bandsOf($mine, 'impact');
    $bands[0]['representative'] = 999.0;

    calibrateAs($mine->owner, $mine, 'impact', $bands)->assertRedirect();

    $theirs->refresh();

    // The other assessment still points at the system scale, and the system
    // scale is byte-for-byte what it was. Without clone-on-edit this test is
    // the one that fails, and in production it would be silent.
    expect($theirs->impact_scale_id)->toBe($systemScaleId)
        ->and(Scale::query()->findOrFail($systemScaleId)->fresh()->levels
            ->map(fn (ScaleLevel $l): array => [$l->code, (float) $l->representative])->all())
        ->toBe($before);
})->group('scales');

test('a second edit reuses the assessment’s own scale rather than cloning again', function () {
    $assessment = Assessment::factory()->create();

    $bands = bandsOf($assessment, 'impact');
    $bands[0]['label'] = 'First';
    calibrateAs($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    $assessment->refresh();
    $ownScaleId = $assessment->impact_scale_id;

    $bands = bandsOf($assessment, 'impact');
    $bands[0]['label'] = 'Second';
    calibrateAs($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    $assessment->refresh();

    // A clone per save would leave a trail of orphaned scales and would break
    // the repoint, which only runs on the save that clones.
    expect($assessment->impact_scale_id)->toBe($ownScaleId)
        ->and(Scale::query()->where('assessment_id', $assessment->id)->where('kind', 'impact')->count())->toBe(1)
        ->and($assessment->impactScale->levels->first()->label)->toBe('Second');
})->group('scales');

// --- stored values survive it --------------------------------------------

test('a qualitative impact still points at a real band after the clone', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();

    $moderate = $assessment->impactScale->levels->firstWhere('code', 'moderate');
    $risk->impact_mode = 'qualitative';
    $risk->impact_amount = null;
    $risk->impact_level_id = $moderate->id;
    $risk->save();

    $bands = bandsOf($assessment, 'impact');
    $bands[0]['label'] = 'Trivial';

    calibrateAs($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    $assessment->refresh();
    $risk->refresh();

    $newLevel = ScaleLevel::query()->findOrFail($risk->impact_level_id);

    // Matched by code, because a code is a band's stable name across a copy.
    // Without this the id would still point into the system scale — which the
    // graph loader no longer loads for this assessment — and the value would
    // read as missing and quietly leave the total.
    expect($newLevel->code)->toBe('moderate')
        ->and($newLevel->scale_id)->toBe($assessment->impact_scale_id);
})->group('scales');

test('a defence strength on the pivot is repointed too', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->for($assessment)->create();
    $defence = Defence::factory()->for($assessment)->create();

    $strong = $assessment->strengthScale->levels->firstWhere('code', 'strong');
    $attack->defences()->attach($defence->id, [
        'position' => 0,
        'strength_mode' => 'qualitative',
        'strength_level_id' => $strong->id,
    ]);

    $bands = bandsOf($assessment, 'strength');
    $bands[0]['label'] = 'Negligible';

    calibrateAs($assessment->owner, $assessment, 'strength', $bands)->assertRedirect();

    $assessment->refresh();
    $link = $attack->defences()->first()->pivot;

    // `attack_defence` carries no assessment_id, so it is reached through its
    // attack. A strength left pointing at the old scale is the same silent
    // understatement as an impact — it is a multiplicand in every ALE.
    $level = ScaleLevel::query()->findOrFail($link->strength_level_id);

    expect($level->code)->toBe('strong')
        ->and($level->scale_id)->toBe($assessment->strength_scale_id);
})->group('scales');

test('another assessment’s values are not dragged onto this one’s new scale', function () {
    $mine = Assessment::factory()->create();
    $theirs = Assessment::factory()->create();

    // Sharing one system scale, as production does — see the note above.
    $theirs->impact_scale_id = $mine->impact_scale_id;
    $theirs->save();
    $theirs->refresh();

    $moderate = $theirs->impactScale->levels->firstWhere('code', 'moderate');
    $theirRisk = Risk::factory()->for($theirs)->create();
    $theirRisk->impact_mode = 'qualitative';
    $theirRisk->impact_amount = null;
    $theirRisk->impact_level_id = $moderate->id;
    $theirRisk->save();

    $bands = bandsOf($mine, 'impact');
    $bands[0]['label'] = 'Trivial';

    calibrateAs($mine->owner, $mine, 'impact', $bands)->assertRedirect();

    $mine->refresh();

    // The repoint is scoped to the editing assessment. Unscoped, the UPDATE
    // would match every row pointing at the shared level and pull other
    // assessments' values onto a private scale — reintroducing exactly the
    // blast radius the clone exists to prevent, one line below it.
    expect($theirRisk->fresh()->impact_level_id)->toBe($moderate->id)
        ->and(ScaleLevel::query()->findOrFail($theirRisk->fresh()->impact_level_id)->scale_id)
        ->not->toBe($mine->impact_scale_id);
})->group('scales');

// --- removing a band ------------------------------------------------------

test('removing a band clears the values that used it and says how many', function () {
    $assessment = Assessment::factory()->create();

    $minor = $assessment->impactScale->levels->firstWhere('code', 'minor');

    foreach (range(1, 2) as $i) {
        $risk = Risk::factory()->for($assessment)->create();
        $risk->impact_mode = 'qualitative';
        $risk->impact_amount = null;
        $risk->impact_level_id = $minor->id;
        $risk->save();
    }

    // Drop 'minor', widening 'negligible' to cover its range so the bands stay
    // contiguous.
    $bands = bandsOf($assessment, 'impact');
    $bands[0]['upper_bound'] = $bands[1]['upper_bound'];
    unset($bands[1]);
    $bands = array_values($bands);

    $response = calibrateAs($assessment->owner, $assessment, 'impact', $bands);

    $response->assertSessionHas('success', fn (string $m): bool => str_contains($m, '2 stored value(s)'));

    // Cleared, not left dangling. The FK is restrictOnDelete, so a delete that
    // skipped this would have failed outright — and `qualitative` with no level
    // chosen is a state the schema permits and the engine reads as missing.
    expect(Risk::query()->where('assessment_id', $assessment->id)
        ->whereNotNull('impact_level_id')->count())->toBe(0);
})->group('scales');

// --- the invariants -------------------------------------------------------

test('a calibration with a gap between bands is refused and writes nothing', function () {
    $assessment = Assessment::factory()->create();
    $systemScaleId = $assessment->impact_scale_id;

    $bands = bandsOf($assessment, 'impact');
    // Invariant 2: a band's upper bound must equal the next one's lower bound.
    $bands[0]['upper_bound'] = 500.0;

    calibrateAs($assessment->owner, $assessment, 'impact', $bands)
        ->assertSessionHasErrors('bands');

    $assessment->refresh();

    // Validated before anything is written, so a rejected calibration does not
    // even clone. A half-applied scale with a hole in it is the state every one
    // of these invariants exists to make unreachable.
    expect($assessment->impact_scale_id)->toBe($systemScaleId)
        ->and(Scale::query()->where('assessment_id', $assessment->id)->count())->toBe(0);
})->group('scales');

test('a representative outside its own band is refused', function () {
    $assessment = Assessment::factory()->create();

    $bands = bandsOf($assessment, 'impact');
    // Invariant 5, the load-bearing one: it is the only thing making the
    // qualitative -> numeric -> qualitative round trip lossless. A
    // representative of 5,000 in the 0–1,000 band bands back as 'minor'.
    $bands[0]['representative'] = 5_000.0;

    calibrateAs($assessment->owner, $assessment, 'impact', $bands)
        ->assertSessionHasErrors('bands');
})->group('scales');

test('two bands sent with the same code do not collide on the unique index', function () {
    $assessment = Assessment::factory()->create();

    $bands = bandsOf($assessment, 'impact');
    $duplicated = $bands[0]['code'];
    $bands[1]['code'] = $duplicated;
    $bands[1]['label'] = 'Slight';

    // W87, D44: codes are no longer a user input, so a duplicate is not a form
    // error any more — it is a payload the first band claims and the second
    // one cannot. The second is treated as a new band and named from its
    // label. Before W87 this was a 422; the requirement now is only that
    // `scale_levels_code_unique (scale_id, code)` is never reached, because
    // reaching it is a 500 partway through a save.
    calibrateAs($assessment->owner, $assessment, 'impact', $bands)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $codes = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id)
        ->levels->pluck('code');

    expect($codes->count())->toBe($codes->unique()->count())
        ->and($codes)->toContain($duplicated)
        ->and($codes)->toContain('slight');
})->group('scales');

test('a fraction scale must close its top band at 1', function () {
    $assessment = Assessment::factory()->create();

    $bands = bandsOf($assessment, 'strength');
    // Invariant 4's exception. Left open, a strength above 1 would band-map to
    // "very strong" and multiply a risk by a negative residual.
    $bands[count($bands) - 1]['upper_bound'] = null;

    calibrateAs($assessment->owner, $assessment, 'strength', $bands)
        ->assertSessionHasErrors('bands');
})->group('scales');

// --- the version bump -----------------------------------------------------

test('a calibration bumps the calculation version exactly once', function () {
    $assessment = Assessment::factory()->create();
    $before = (int) $assessment->fresh()->calc_version;

    $bands = bandsOf($assessment, 'impact');
    $bands[0]['label'] = 'Trivial';

    calibrateAs($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    // Once, not once per band. Every ScaleLevel write fires ScaleLevelObserver;
    // W64's coalesce() collapses them. Not zero, though — every figure cached
    // against the old calibration has to become unreachable.
    expect((int) $assessment->fresh()->calc_version)->toBe($before + 1);
})->group('scales');

// --- who can reach it -----------------------------------------------------

test('an editor cannot calibrate a scale', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor', 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    // calculation-engine.md §8.4 pitfall 6 says the manager role outright, and
    // unlike W61's restore there is no lower-gated route to the same effect: an
    // editor changes one value, a recalibration moves every figure in the
    // assessment and every report already computed from it.
    test()->actingAs($editor)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->assertForbidden();

    calibrateAs($editor, $assessment, 'impact', bandsOf($assessment, 'impact'))
        ->assertForbidden();
})->group('scales');

test('a manager can calibrate a scale', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager', 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    test()->actingAs($manager)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->assertOk();
})->group('scales');

test('an anonymous caller is refused', function () {
    $assessment = Assessment::factory()->create();

    test()->put(route('assessments.scales.update', [$assessment, 'impact']), ['bands' => []])
        ->assertRedirect(route('login'));
})->group('scales');

test('an unknown scale kind is not found rather than reaching a column name', function () {
    $assessment = Assessment::factory()->create();

    // The kind selects a column on `assessments`. Building one from request
    // input is a legacy defect this codebase forbids by name, so the value is
    // checked against a fixed list first.
    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', [$assessment, 'colour']), ['bands' => []])
        ->assertNotFound();
})->group('scales');

test('the calibration route carries the authenticated rate limit', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->getName() === 'assessments.scales.update');

    // Asserted by its specified value, not by "a throttle is present":
    // RELEASE-2.0.1 records a fix that a presence assertion could not see,
    // because an enclosing group's looser limiter also matched.
    expect($route->gatherMiddleware())->toContain('throttle:authenticated');
})->group('scales');

// --- the page -------------------------------------------------------------

test('the page sends every band field the editor needs', function () {
    $assessment = Assessment::factory()->create();

    $props = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page')['props'];

    $band = $props['scales']['impact']['bands'][0];

    // The editor needs the bounds and the representative, plus what the rule
    // would suggest for those bounds — and `code`, which it carries back
    // untouched and draws nowhere, because that is what freezes a code across
    // a rename (W87, D44).
    //
    // No `color`: D45 stopped it being editable, so the editor is not handed a
    // value it cannot change and does not draw. The column keeps its `#9CA3AF`
    // default.
    expect(array_keys($band))->toEqualCanonicalizing([
        'ordinal', 'code', 'label', 'lower_bound', 'upper_bound',
        'representative', 'suggested_representative',
    ]);
})->group('scales');

test('kinds() and the page agree on which three scales exist', function () {
    $assessment = Assessment::factory()->create();

    $props = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page')['props'];

    expect(array_keys($props['scales']))->toEqualCanonicalizing(ScaleCalibrator::kinds());
})->group('scales');

// --- ordinals, renumbered without colliding on their own unique index ------

test('removing a band on an assessment’s own scale renumbers without colliding', function () {
    $assessment = Assessment::factory()->create();

    // The first save clones, so the new scale starts with no levels and
    // nothing is renumbered — which is exactly why this defect survived W65.
    // It takes a *second* edit, on a scale that already has rows, to reach
    // `scale_levels_ordinal_unique (scale_id, ordinal)`.
    $bands = bandsOf($assessment, 'impact');
    $bands[0]['label'] = 'Trivial';
    calibrateAs($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    $bands = bandsOf($assessment->fresh(), 'impact');
    array_shift($bands);
    $bands[0]['lower_bound'] = 0.0;

    calibrateAs($assessment->owner, $assessment, 'impact', $bands)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $ordinals = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id)
        ->levels->pluck('ordinal')->sort()->values()->all();

    // Contiguous 1..n, which is invariant 1 — and not the parked 101.. range
    // LevelOrdinals moves them through, which would render the bands in an
    // order nobody chose while breaking no constraint.
    expect($ordinals)->toBe(range(1, count($bands)));
})->group('scales');

test('reordering bands does not collide on the ordinal index either', function () {
    $assessment = Assessment::factory()->create();

    $bands = bandsOf($assessment, 'impact');
    calibrateAs($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    // Same bands, same bounds, but the labels shifted down one position — so
    // every row keeps its ordinal and only the content moves. This is the
    // cheap case; the expensive one is above.
    $bands = bandsOf($assessment->fresh(), 'impact');
    $labels = array_column($bands, 'label');
    foreach ($bands as $i => $_) {
        $bands[$i]['label'] = $labels[(count($labels) - 1 + $i) % count($labels)];
    }

    calibrateAs($assessment->owner, $assessment, 'impact', $bands)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $levels = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id)->levels;

    expect($levels->pluck('ordinal')->sort()->values()->all())->toBe(range(1, $levels->count()));
})->group('scales');
