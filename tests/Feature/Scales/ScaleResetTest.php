<?php

/**
 * "Reset to default", one per card on Assessment settings (W87, item 10).
 *
 * The default it resets to is the default system set (2.5.1:
 * `default_scale_calibration` is gone) — the same set a new assessment is
 * created with and the same set the admin Defaults tab edits, so
 * "default" means one thing across all three screens rather than naming a
 * constant on one and a setting on the others.
 *
 * The two things that would be easy to get wrong, and are asserted here:
 *
 * - It does **not** repoint the assessment back at the shared system scale. It
 *   writes the default's bands into the assessment's *own* copy. Repointing
 *   would look tidier and would mean a later admin edit to the default
 *   silently moved this assessment's figures — the thing clone-on-edit exists
 *   to prevent.
 * - It goes through `ScaleCalibrator::calibrate()` like any other save, so
 *   stored values are re-pointed by code and a value whose band the default
 *   does not have is cleared and counted, rather than left pointing into a
 *   deleted row.
 */

use App\Domain\Scales\ScaleDescriber;
use App\Domain\Scales\ScaleSets;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;

// The real seeder, not the factory. `Assessment::factory()` builds system
// scales with no `set_key`, so on a factory-only database there is no *set* to
// be the default — and "reset to the instance default" is a statement about a
// seeded set, which is what a real deployment has.
beforeEach(fn () => test()->seed(SystemScaleSeeder::class));

/** The bands of the seeded set the instance currently names as its default. */
function defaultBands(string $kind): array
{
    $described = ScaleDescriber::forSystemSet(
        ScaleSets::DEFAULT_KEY
    )[$kind];

    return $described['bands'];
}

function resetAs(User $actor, Assessment $assessment, string $kind)
{
    return test()->actingAs($actor)
        ->put(route('assessments.scales.reset', [$assessment, $kind]));
}

/** Bands in the shape the save endpoint takes. */
function resetTestBands(Assessment $assessment, string $kind): array
{
    return Scale::query()->findOrFail($assessment->getAttribute("{$kind}_scale_id"))
        ->levels->sortBy('ordinal')->map(fn (ScaleLevel $l): array => [
            'code' => $l->code,
            'label' => $l->label,
            'lower_bound' => (float) $l->lower_bound,
            'upper_bound' => $l->upper_bound === null ? null : (float) $l->upper_bound,
            'representative' => (float) $l->representative,
        ])->values()->all();
}

test('a reset restores the instance default labels and bounds', function () {
    $assessment = Assessment::factory()->create();

    $edited = resetTestBands($assessment, 'impact');
    $edited[0]['label'] = 'Utterly trivial';
    $edited[0]['representative'] = 1.0;

    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', [$assessment, 'impact']), ['bands' => $edited])
        ->assertRedirect();

    resetAs($assessment->owner, $assessment, 'impact')->assertRedirect();

    $after = resetTestBands($assessment->fresh(), 'impact');
    $expected = defaultBands('impact');

    expect(array_column($after, 'label'))->toBe(array_column($expected, 'label'))
        ->and(array_column($after, 'lower_bound'))->toBe(array_column($expected, 'lower_bound'))
        ->and(array_column($after, 'representative'))->toBe(array_column($expected, 'representative'));
})->group('scales');

test('a reset leaves the assessment with its own scale, not the shared one', function () {
    $assessment = Assessment::factory()->create();

    $bands = resetTestBands($assessment, 'impact');
    $bands[0]['label'] = 'Utterly trivial';

    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', [$assessment, 'impact']), ['bands' => $bands])
        ->assertRedirect();

    $ownScaleId = $assessment->fresh()->impact_scale_id;

    resetAs($assessment->owner, $assessment, 'impact')->assertRedirect();

    $after = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id);

    // The same private scale, still private. Repointing back at the shared row
    // would mean a later admin edit to the instance default silently moved
    // this assessment's figures — exactly what clone-on-edit exists to stop.
    expect($after->id)->toBe($ownScaleId)
        ->and($after->is_system)->toBeFalse()
        ->and($after->assessment_id)->toBe($assessment->id);
})->group('scales');

test('a reset re-points a stored value rather than orphaning it', function () {
    $assessment = Assessment::factory()->create();

    $bands = resetTestBands($assessment, 'impact');
    $bands[2]['label'] = 'Middling';

    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', [$assessment, 'impact']), ['bands' => $bands])
        ->assertRedirect();

    $moderate = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id)
        ->levels->firstWhere('code', 'moderate');

    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'impact_mode' => 'qualitative',
        'impact_amount' => null,
        'impact_level_id' => $moderate->id,
    ]);

    resetAs($assessment->owner, $assessment, 'impact')->assertRedirect();

    $after = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id)
        ->levels->firstWhere('code', 'moderate');

    // The value still names a band the assessment loads. That is the whole
    // assertion: an orphaned `impact_level_id` reads as *missing* and silently
    // leaves the total, understating risk — the one direction this codebase
    // never rounds in.
    $expectedLabel = collect(defaultBands('impact'))->firstWhere('code', 'moderate')['label'];

    expect($risk->fresh()->impact_level_id)->toBe($after->id)
        ->and($after->label)->toBe($expectedLabel)
        ->and($after->label)->not->toBe('Middling');
})->group('scales');

test('a reset follows the default as the admin last edited it (2.5.1)', function () {
    // "Reset to default" is one of the two places a default is used — the
    // other is creating an assessment — so an admin's edit on the Defaults
    // tab must be what a reset produces, even though it did not touch the
    // assessment when it was made.
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();

    $bands = collect(ScaleDescriber::forSystemSet(ScaleSets::DEFAULT_KEY)['impact']['bands'])
        ->map(fn (array $b): array => [
            'code' => $b['code'],
            'label' => $b['label'],
            'lower_bound' => $b['lower_bound'],
            'upper_bound' => $b['upper_bound'],
            'representative' => $b['representative'],
        ])->values()->all();
    $bands[0]['label'] = 'Edited centrally';

    $this->actingAs($admin)
        ->put(route('admin.defaults.update', ['kind' => 'impact']), ['bands' => $bands])
        ->assertRedirect();

    // The edit did not reach the existing assessment…
    expect(resetTestBands($assessment->fresh(), 'impact')[0]['label'])->not->toBe('Edited centrally');

    // …and a reset brings it in.
    resetAs($assessment->owner, $assessment, 'impact')->assertRedirect();

    expect(resetTestBands($assessment->fresh(), 'impact')[0]['label'])->toBe('Edited centrally');
})->group('scales');

test('an editor cannot reset a scale', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor', 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    // The same gate as a save, and for the same reason: a reset moves every
    // figure computed from this scale just as thoroughly as an edit does.
    resetAs($editor, $assessment, 'impact')->assertForbidden();
})->group('scales');

test('an unknown kind is not found on the reset route either', function () {
    $assessment = Assessment::factory()->create();

    resetAs($assessment->owner, $assessment, 'colour')->assertNotFound();
})->group('scales');

test('the reset route carries the authenticated rate limit', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->getName() === 'assessments.scales.reset');

    // The specified value, not "a throttle is present": RELEASE-2.0.1 records
    // a fix a presence assertion could not see, because an enclosing group's
    // looser limiter also matched.
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:authenticated');
})->group('scales');

test('the calibration screen and its route are gone', function () {
    // D46: a screen that still answers its URL after its entry points are
    // removed is how a second, divergent editor survives a consolidation. So
    // the assertion is about the route table, not about whether anything links
    // to it.
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter()
        ->values();

    expect($names)->not->toContain('assessments.scales.edit')
        ->and(file_exists(resource_path('js/Pages/Scales/Calibrate.vue')))->toBeFalse();
})->group('scales');

// --- the message actually reaches the page --------------------------------

test('the count of emptied values reaches the screen, not just the session', function () {
    $assessment = Assessment::factory()->create();

    $bands = resetTestBands($assessment, 'impact');
    $bands[0]['label'] = 'Trivial';

    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', [$assessment, 'impact']), ['bands' => $bands])
        ->assertRedirect();

    $props = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page')['props'];

    // `HandleInertiaRequests::share()` does not share the session's flash bag,
    // so `->with('success', …)` reaches the server-side session and stops
    // there. The controller reads it and passes it as a prop instead — without
    // that, a calibration that empties a workshop's worth of stored values
    // would report the fact to nobody.
    expect($props)->toHaveKey('flashSuccess')
        ->and($props['flashSuccess'])->toContain('its own copy of the scale');
})->group('scales');

test('DEFECT: a flash message is not a shared Inertia prop, so ->with(success) alone is invisible', function () {
    /*
     * Pinned rather than fixed, because the fix is outside this package.
     *
     * `App\Http\Middleware\HandleInertiaRequests::share()` shares `auth`,
     * `nav` and `reverb` and nothing else. Four controllers write
     * `->with('success', …)` on redirect — SnapshotController (three times),
     * TemplateController, and this package's two — and no page component reads
     * one, because there is no shared `flash` prop for it to read.
     *
     * W87 works around it for the two screens it owns by reading the value in
     * the controller and passing it as an ordinary prop. That is correct for
     * those screens and does nothing for the snapshot and template messages,
     * which stay invisible: "Snapshot created.", "Snapshot deleted." and the
     * restore summary are all written and all discarded.
     *
     * **The fix** is one line in `share()` —
     * `'flash' => ['success' => $request->session()->get('success')]` — plus a
     * component that renders it once, which is a layout concern
     * (`AuthenticatedLayout` or the W81 chrome) rather than a per-page one.
     *
     * **The trade-off** is that a shared prop is evaluated on every Inertia
     * response on every page, and that a single global renderer has to decide
     * where a message appears on screens whose layouts differ — which is why
     * it is a package of its own and not a line snuck into this one.
     *
     * Fixing it turns this test red, which is the point: whoever does it
     * should also decide whether the per-page props W87 added here become
     * redundant, rather than leaving two mechanisms for one message.
     */
    $shared = app(HandleInertiaRequests::class)
        ->share(request());

    expect($shared)->not->toHaveKey('flash');
})->group('scales');
