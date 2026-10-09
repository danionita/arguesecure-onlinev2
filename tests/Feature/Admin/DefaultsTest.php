<?php

/**
 * The admin Defaults tab (W87, item 10, PLAN-2.5.md D47).
 *
 * Two things distinguish it from Assessment settings, and both are asserted
 * here because both are easy to get backwards:
 *
 * - It edits the seeded system set **in place**. No clone: that is the request.
 *   `ScaleCalibrator` exists to avoid exactly this, which is why a second
 *   class does it and refuses anything that is not a shared system scale.
 * - **Defaults are defaults (2.5.1).** Through 2.5 an assessment that had
 *   never calibrated was still pointing at these rows, so its figures moved
 *   with every edit here. Now it is moved onto its own copy of the bands as
 *   they were, first, and nothing it shows changes. The edit reaches only
 *   assessments created afterwards and "Reset to default".
 */

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Scales\ScaleDescriber;
use App\Domain\Scales\ScaleSets;
use App\Domain\Scales\SystemScaleCalibrator;
use App\Domain\Structure\AssessmentCreator;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;

beforeEach(fn () => test()->seed(SystemScaleSeeder::class));

function admin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

function defaultImpactScale(): Scale
{
    return Scale::query()
        ->whereNull('assessment_id')
        ->where('is_system', true)
        ->where('set_key', ScaleSets::DEFAULT_KEY)
        ->where('kind', 'impact')
        ->firstOrFail();
}

function defaultsBands(string $kind = 'impact'): array
{
    return collect(ScaleDescriber::forSystemSet(
        ScaleSets::DEFAULT_KEY
    )[$kind]['bands'])->map(fn (array $b): array => [
        'code' => $b['code'],
        'label' => $b['label'],
        'lower_bound' => $b['lower_bound'],
        'upper_bound' => $b['upper_bound'],
        'representative' => $b['representative'],
    ])->values()->all();
}

// --- the page -------------------------------------------------------------

test('the tab renders the three scales of the default set', function () {
    $props = test()->actingAs(admin())
        ->get(route('admin.defaults.edit'))
        ->viewData('page')['props'];

    expect(array_keys($props['scales']))->toEqualCanonicalizing(['impact', 'likelihood', 'strength'])
        // 2.5.1: one default set, so nothing to name.
        ->and($props)->not->toHaveKey('setKey')
        ->and($props['missingKinds'])->toBe([])
        ->and($props['scales']['impact']['bands'][0])->toHaveKey('code')
        // D45: the editor cannot change a colour, so it is not handed one.
        ->and($props['scales']['impact']['bands'][0])->not->toHaveKey('color');
})->group('scales');

test('a non-admin cannot reach the Defaults tab or write to it', function () {
    $user = User::factory()->create(['is_admin' => false]);

    test()->actingAs($user)->get(route('admin.defaults.edit'))->assertForbidden();
    test()->actingAs($user)
        ->put(route('admin.defaults.update', ['kind' => 'impact']), ['bands' => defaultsBands()])
        ->assertForbidden();
    test()->actingAs($user)
        ->put(route('admin.defaults.currency'), ['default_currency' => 'USD'])
        ->assertForbidden();
})->group('scales');

test('an anonymous caller is redirected to login', function () {
    test()->get(route('admin.defaults.edit'))->assertRedirect(route('login'));
})->group('scales');

test('an unknown kind is not found rather than reaching a scale', function () {
    test()->actingAs(admin())
        ->put(route('admin.defaults.update', ['kind' => 'colour']), ['bands' => defaultsBands()])
        ->assertNotFound();
})->group('scales');

// --- saving ---------------------------------------------------------------

test('saving edits the shared system scale in place rather than cloning it', function () {
    $before = defaultImpactScale();

    $bands = defaultsBands();
    $bands[0]['label'] = 'Barely worth mentioning';

    test()->actingAs(admin())
        ->put(route('admin.defaults.update', ['kind' => 'impact']), ['bands' => $bands])
        ->assertRedirect(route('admin.defaults.edit'));

    $after = defaultImpactScale();

    // The same row, still shared, still in the set. A clone here would leave
    // the instance default pointing at the unedited original and the edit
    // reachable from nowhere.
    expect($after->id)->toBe($before->id)
        ->and($after->is_system)->toBeTrue()
        ->and($after->assessment_id)->toBeNull()
        ->and($after->levels->sortBy('ordinal')->first()->label)->toBe('Barely worth mentioning');
})->group('scales');

test('a code is frozen across a rename here too', function () {
    $bands = defaultsBands();
    $index = collect($bands)->search(fn (array $b): bool => $b['code'] === 'moderate');
    $bands[$index]['label'] = 'Medium';

    test()->actingAs(admin())
        ->put(route('admin.defaults.update', ['kind' => 'impact']), ['bands' => $bands])
        ->assertRedirect();

    $codes = defaultImpactScale()->levels->pluck('code');

    // The catalogue fixtures name these codes. Re-deriving one here would
    // detach every entry that referenced it, on every assessment on the
    // instance at once.
    expect($codes)->toContain('moderate')
        ->and($codes)->not->toContain('medium');
})->group('scales');

test('an assessment that has calibrated is untouched by a default edit', function () {
    $assessment = Assessment::factory()->create();

    // Give it its own copy first, which is what a save on the assessment's own
    // screen does.
    $own = collect(ScaleDescriber::forAssessment($assessment)['impact']['bands'])
        ->map(fn (array $b): array => [
            'code' => $b['code'],
            'label' => $b['label'],
            'lower_bound' => $b['lower_bound'],
            'upper_bound' => $b['upper_bound'],
            'representative' => $b['representative'],
        ])->values()->all();
    $own[0]['label'] = 'Ours alone';

    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', [$assessment, 'impact']), ['bands' => $own])
        ->assertRedirect();

    $bands = defaultsBands();
    $bands[0]['label'] = 'Changed centrally';

    test()->actingAs(admin())
        ->put(route('admin.defaults.update', ['kind' => 'impact']), ['bands' => $bands])
        ->assertRedirect();

    $theirs = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id);

    expect($theirs->is_system)->toBeFalse()
        ->and($theirs->levels->sortBy('ordinal')->first()->label)->toBe('Ours alone');
})->group('scales');

test('an assessment still following the default keeps its bands and values when the default changes (2.5.1)', function () {
    $assessment = Assessment::factory()->create();

    // Point the assessment at the seeded default set, which is the state of
    // any assessment that has never calibrated.
    $scale = defaultImpactScale();
    $assessment->impact_scale_id = $scale->id;
    $assessment->save();

    $level = $scale->levels->sortBy('ordinal')->first();
    $originalLabel = $level->label;

    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'impact_mode' => 'qualitative',
        'impact_amount' => null,
        'impact_level_id' => $level->id,
    ]);

    $bands = defaultsBands();
    // Drop the lowest band — the one the risk uses — giving its range to the
    // next one so the set stays contiguous from zero.
    array_shift($bands);
    $bands[0]['lower_bound'] = 0.0;

    test()->actingAs(admin())
        ->put(route('admin.defaults.update', ['kind' => 'impact']), ['bands' => $bands])
        ->assertRedirect();

    // Through 2.5 this cleared the risk's impact and moved the assessment's
    // figures. Now the assessment was given its own copy first.
    $own = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id);
    $value = ScaleLevel::query()->find($risk->fresh()->impact_level_id);

    expect($own->id)->not->toBe($scale->id)
        ->and($own->is_system)->toBeFalse()
        ->and($own->assessment_id)->toBe($assessment->id)
        ->and($value)->not->toBeNull()
        ->and($value->scale_id)->toBe($own->id)
        ->and($value->label)->toBe($originalLabel)
        ->and($own->levels)->toHaveCount(count($bands) + 1);

    // …while the default itself did change, for the next assessment.
    expect(defaultImpactScale()->fresh()->levels)->toHaveCount(count($bands));

    $props = test()->actingAs(admin())
        ->get(route('admin.defaults.edit'))
        ->viewData('page')['props'];

    expect($props['flashSuccess'])->toContain('existing assessments keep their bands');
})->group('scales');

test('a new assessment starts from the edited default', function () {
    $bands = defaultsBands();
    $bands[0]['label'] = 'Barely worth mentioning';

    test()->actingAs(admin())
        ->put(route('admin.defaults.update', ['kind' => 'impact']), ['bands' => $bands])
        ->assertRedirect();

    $assessment = app(AssessmentCreator::class)->create(admin(), 'Next one');
    $labels = Scale::query()->findOrFail($assessment->impact_scale_id)->levels->sortBy('ordinal')->pluck('label');

    expect($labels->first())->toBe('Barely worth mentioning');
})->group('scales');

test('the system calibrator refuses an assessment\'s own scale', function () {
    $assessment = Assessment::factory()->create();

    $bands = defaultsBands();

    $own = Scale::query()->create([
        'kind' => 'impact',
        'name' => 'Private',
        'unit' => 'currency',
        'is_system' => false,
    ]);
    $own->assessment_id = $assessment->id;
    $own->save();

    // Handed a private scale this class would edit it without cloning, which
    // is correct for a shared default and wrong for an assessment. Refusing is
    // the only answer that cannot be wrong by accident.
    expect(fn () => app(SystemScaleCalibrator::class)->calibrate($own, $bands))
        ->toThrow(InvalidArgumentException::class);
})->group('scales');

// --- the currency ---------------------------------------------------------

test('the default currency saves and is validated against the fixed list', function () {
    test()->actingAs(admin())
        ->put(route('admin.defaults.currency'), ['default_currency' => 'USD'])
        ->assertRedirect(route('admin.defaults.edit'));

    expect(InstanceSettings::all()['default_currency'])->toBe('USD');

    // Not `size:3`. The value reaches `Intl.NumberFormat`, which throws on an
    // unknown code and would blank every figure on the page, so "three
    // characters" is not the constraint that matters.
    test()->actingAs(admin())
        ->put(route('admin.defaults.currency'), ['default_currency' => 'ZZZ'])
        ->assertSessionHasErrors('default_currency');

    expect(InstanceSettings::all()['default_currency'])->toBe('USD');
})->group('scales');

// --- the payload shape shared with the assessment screen ------------------

test('both editors are handed the same band shape', function () {
    $assessment = Assessment::factory()->create();

    $adminBand = test()->actingAs(admin())
        ->get(route('admin.defaults.edit'))
        ->viewData('page')['props']['scales']['impact']['bands'][0];

    $assessmentBand = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page')['props']['scales']['impact']['bands'][0];

    // D47: the admin "wants the same band editor the assessment gets, not a
    // different one". A component can only be the same if the payload is, so
    // the two are compared key for key rather than trusted to agree.
    expect(array_keys($adminBand))->toEqualCanonicalizing(array_keys($assessmentBand));
})->group('scales');

test('an incomplete set is named rather than silently rendered short', function () {
    // A set missing a kind cannot start an assessment — the three scale FKs
    // are NOT NULL — so the screen has to say which row is absent instead of
    // drawing two cards where there should be three.
    ScaleLevel::query()->whereIn('scale_id', Scale::query()
        ->whereNull('assessment_id')->where('kind', 'strength')->select('id'))->delete();
    Scale::query()->whereNull('assessment_id')->where('kind', 'strength')->delete();

    $props = test()->actingAs(admin())
        ->get(route('admin.defaults.edit'))
        ->viewData('page')['props'];

    expect($props['missingKinds'])->toBe(['strength'])
        ->and($props['scales']['strength'])->toBeNull();
})->group('scales');

test('instance settings no longer writes the default currency', function () {
    // W87, D47: the control moved to the Defaults tab and did not stay here.
    // Two controls writing one column with different rules is how they
    // disagree — and the weaker of the two was `size:3`, which accepts "ZZZ".
    // The three feature switches default on and are `declined` where the
    // deployment has no runtime, so an otherwise-valid settings save would be
    // rejected for a reason that has nothing to do with the currency.
    config()->set('assistant.enabled', true);

    InstanceSettings::update(['default_currency' => 'EUR']);

    test()->actingAs(admin())
        ->put(route('admin.settings.update'), [
            ...InstanceSettings::all(),
            'default_currency' => 'ZZZ',
        ])
        ->assertSessionHasNoErrors();

    // Ignored, not written: the key has no rule, so it never reaches
    // `validated()` and `InstanceSettings::update()` never sees it.
    expect(InstanceSettings::all()['default_currency'])->toBe('EUR');
})->group('scales');
