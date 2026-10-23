<?php

/**
 * Choosing a scale for an assessment, and what that does and does not do
 * (W126, D94; before W126 this file tested "Reset to default", W87 item 10).
 *
 * There is no reset any more: the settings dropdown fills the editors with an
 * admin scale's bands and **Save** stores all three kinds in one request. The
 * server is what sees only bands, so "choosing a scale" is, to it, a save whose
 * bands equal that scale's — and these tests are about what such a save must
 * keep true:
 *
 * - The assessment keeps its **own** copy. Repointing it at the shared rows
 *   would make a later admin edit silently move this assessment's figures,
 *   which is what clone-on-edit exists to prevent.
 * - It goes through `ScaleCalibrator::calibrate()`, so stored values are
 *   re-pointed by code and a value whose band the new scale lacks is cleared
 *   and counted, not left pointing into a deleted row.
 * - The server decides which scale the assessment is labelled with.
 */

use App\Domain\Scales\ScaleDescriber;
use App\Domain\Scales\ScaleSets;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;

// The real seeder, not the factory. `Assessment::factory()` builds system
// scales with no `set_key`, so on a factory-only database there is no *set* to
// choose — and choosing one is a statement about a seeded scale, which is what
// a real deployment has.
beforeEach(fn () => test()->seed(SystemScaleSeeder::class));

/** The bands of a seeded scale, in the shape the save endpoint takes. */
function setBands(string $key, string $kind): array
{
    return collect(ScaleDescriber::forSystemSet($key)[$kind]['bands'])->map(fn (array $b): array => [
        'code' => $b['code'],
        'label' => $b['label'],
        'lower_bound' => $b['lower_bound'],
        'upper_bound' => $b['upper_bound'],
        'representative' => $b['representative'],
    ])->values()->all();
}

/** What Assessment settings sends after a scale is picked from the dropdown. */
function chooseScale(User $actor, Assessment $assessment, string $key)
{
    return test()->actingAs($actor)->put(route('assessments.scales.update', $assessment), [
        'scales' => [
            'impact' => ['bands' => setBands($key, 'impact')],
            'likelihood' => ['bands' => setBands($key, 'likelihood')],
            'strength' => ['bands' => setBands($key, 'strength')],
        ],
    ]);
}

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

function editImpact(Assessment $assessment, array $bands)
{
    return test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', $assessment), ['scales' => ['impact' => ['bands' => $bands]]]);
}

test('choosing a scale writes its labels and bounds into all three kinds', function () {
    $assessment = Assessment::factory()->create();

    $edited = resetTestBands($assessment, 'impact');
    $edited[0]['label'] = 'Utterly trivial';
    editImpact($assessment, $edited)->assertRedirect();

    chooseScale($assessment->owner, $assessment, ScaleSets::SMALL_ORGANISATION_KEY)->assertRedirect();

    foreach (['impact', 'likelihood', 'strength'] as $kind) {
        $after = resetTestBands($assessment->fresh(), $kind);
        $expected = setBands(ScaleSets::SMALL_ORGANISATION_KEY, $kind);

        expect(array_column($after, 'label'))->toBe(array_column($expected, 'label'))
            ->and(array_column($after, 'lower_bound'))->toBe(array_column($expected, 'lower_bound'))
            ->and(array_column($after, 'representative'))->toBe(array_column($expected, 'representative'));
    }
})->group('scales');

test('choosing a scale leaves the assessment with its own scales, not the shared ones', function () {
    $assessment = Assessment::factory()->create();

    $bands = resetTestBands($assessment, 'impact');
    $bands[0]['label'] = 'Utterly trivial';
    editImpact($assessment, $bands)->assertRedirect();

    $ownScaleId = $assessment->fresh()->impact_scale_id;

    chooseScale($assessment->owner, $assessment, ScaleSets::DEFAULT_KEY)->assertRedirect();

    $after = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id);

    // The same private scale, still private. Repointing back at the shared row
    // would mean a later admin edit silently moved this assessment's figures.
    expect($after->id)->toBe($ownScaleId)
        ->and($after->is_system)->toBeFalse()
        ->and($after->assessment_id)->toBe($assessment->id);
})->group('scales');

test('choosing a scale re-points a stored value rather than orphaning it', function () {
    $assessment = Assessment::factory()->create();

    $bands = resetTestBands($assessment, 'impact');
    $bands[2]['label'] = 'Middling';
    editImpact($assessment, $bands)->assertRedirect();

    $moderate = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id)
        ->levels->firstWhere('code', 'moderate');

    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'impact_mode' => 'qualitative',
        'impact_amount' => null,
        'impact_level_id' => $moderate->id,
    ]);

    chooseScale($assessment->owner, $assessment, ScaleSets::DEFAULT_KEY)->assertRedirect();

    $after = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id)
        ->levels->firstWhere('code', 'moderate');

    // The value still names a band the assessment loads. An orphaned
    // `impact_level_id` reads as *missing* and silently leaves the total,
    // understating risk — the one direction this codebase never rounds in.
    $expectedLabel = collect(setBands(ScaleSets::DEFAULT_KEY, 'impact'))->firstWhere('code', 'moderate')['label'];

    expect($risk->fresh()->impact_level_id)->toBe($after->id)
        ->and($after->label)->toBe($expectedLabel)
        ->and($after->label)->not->toBe('Middling');
})->group('scales');

test('choosing a scale brings in the scale as the admin last edited it (2.5.1)', function () {
    // Defaults are used in two places: creating an assessment, and choosing the
    // scale in an assessment's settings. An admin's edit did not touch the
    // existing assessment when it was made; choosing the scale brings it in.
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();

    $bands = setBands(ScaleSets::DEFAULT_KEY, 'impact');
    $bands[0]['label'] = 'Edited centrally';

    $this->actingAs($admin)
        ->put(route('admin.scales.update', ScaleSets::DEFAULT_KEY), ['scales' => ['impact' => ['bands' => $bands]]])
        ->assertRedirect();

    expect(resetTestBands($assessment->fresh(), 'impact')[0]['label'])->not->toBe('Edited centrally');

    chooseScale($assessment->owner, $assessment, ScaleSets::DEFAULT_KEY)->assertRedirect();

    expect(resetTestBands($assessment->fresh(), 'impact')[0]['label'])->toBe('Edited centrally');
})->group('scales');

test('an editor cannot save scales', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor', 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    // `manage`, as ever: a save moves every figure computed from the scales.
    chooseScale($editor, $assessment, ScaleSets::DEFAULT_KEY)->assertForbidden();
})->group('scales');

test('Reset to default is gone: no route, no per-kind save, no screen', function () {
    // D94: choosing a scale is the reset. D46: a route that still answers is
    // how a second editor survives, so the assertion is on the route table.
    $routes = collect(app('router')->getRoutes()->getRoutes());
    $names = $routes->map(fn ($r) => $r->getName())->filter()->values();

    expect($names)->not->toContain('assessments.scales.reset')
        ->and($names)->not->toContain('assessments.scales.edit')
        ->and($names)->not->toContain('admin.defaults.update')
        ->and($routes->filter(fn ($r): bool => str_contains($r->uri(), '/scales/{kind}')))->toHaveCount(0)
        ->and(file_exists(resource_path('js/Pages/Scales/Calibrate.vue')))->toBeFalse();

    $assessment = Assessment::factory()->create();

    test()->actingAs($assessment->owner)
        ->put("/assessments/{$assessment->uuid}/scales/impact/reset")
        ->assertNotFound();
})->group('scales');

test('the scales save carries the authenticated rate limit', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->getName() === 'assessments.scales.update');

    // The specified value, not "a throttle is present" (RELEASE-2.0.1).
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:authenticated');
})->group('scales');

// --- the message actually reaches the page --------------------------------

test('the count of emptied values reaches the screen, not just the session', function () {
    $assessment = Assessment::factory()->create();
    $bands = resetTestBands($assessment, 'impact');

    // Drop the lowest band, which a risk uses.
    $level = Scale::query()->findOrFail($assessment->impact_scale_id)->levels->sortBy('ordinal')->first();
    Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'impact_mode' => 'qualitative',
        'impact_amount' => null,
        'impact_level_id' => $level->id,
    ]);
    array_shift($bands);
    $bands[0]['lower_bound'] = 0.0;

    editImpact($assessment, $bands)->assertRedirect();

    $props = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page')['props'];

    // Until W115 nothing passed the session's flash bag to the page, so the
    // controller reads it and passes it as a prop, beside the editors.
    expect($props)->toHaveKey('flashSuccess')
        ->and($props['flashSuccess'])->toBe('Scales saved. 1 stored value(s) used a band you removed and are now unset — they will need choosing again.');
})->group('scales');

test('FIXED: a flash message reaches the page, so ->with(success) alone is visible (W115)', function () {
    /*
     * Was the `DEFECT:` pin "a flash message is not a shared Inertia prop", and
     * is flipped in place rather than deleted so the reasoning survives.
     *
     * `HandleInertiaRequests::share()` shared `auth`, `nav` and `reverb` and
     * nothing else, so every `->with('success', …)` reached the server-side
     * session and stopped: "Snapshot created.", "Snapshot deleted.", the
     * restore summary, the template messages and — worst, and not on the
     * pin's list — the generated password an admin needs to hand over.
     *
     * The pin proposed a shared `flash` prop. W115 used Inertia's own flash
     * data instead (`HandleInertiaRequests::handle()` says why: a prop is kept
     * in browser history and would toast again on Back), rendered once in the
     * shared chrome. So the assertion is on the page object's `flash`, and on
     * the exact sentence the controller wrote — not that a key is present.
     *
     * The pin also asked whoever fixed it to decide whether W87's per-page
     * `flashSuccess` props become redundant. They do not: Settings and
     * Defaults show the message beside the card it describes, which a toast
     * at the foot of the window cannot, and the chrome defers to a page that
     * does (`Components/Chrome/flashToasts.ts`). The test above still asserts
     * that prop; this one asserts the general path every other screen uses.
     */
    $assessment = Assessment::factory()->create();

    $bands = resetTestBands($assessment, 'impact');
    $bands[0]['label'] = 'Trivial';

    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', $assessment), ['scales' => ['impact' => ['bands' => $bands]]])
        ->assertRedirect();

    $page = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page');

    expect($page['flash'] ?? null)->toBe([
        'success' => 'Scales saved.',
    ]);

    // Delivered with one response, not every one after it.
    $again = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page');

    expect($again)->not->toHaveKey('flash');
})->group('scales');
