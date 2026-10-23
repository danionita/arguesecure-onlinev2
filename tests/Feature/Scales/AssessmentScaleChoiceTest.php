<?php

/**
 * Which scale an assessment has, and how that is chosen (W126, D93, D94;
 * docs/PLAN-2.7-scales.md criteria 2, 12, 16, 18a, 19, 20, 21).
 */

use App\Domain\Scales\ScaleDescriber;
use App\Domain\Scales\ScaleSets;
use App\Domain\Structure\AssessmentCreator;
use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleSet;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => test()->seed(SystemScaleSeeder::class));

function choiceBands(string $key, string $kind): array
{
    return collect(ScaleDescriber::forSystemSet($key)[$kind]['bands'])->map(fn (array $b): array => [
        'code' => $b['code'],
        'label' => $b['label'],
        'lower_bound' => $b['lower_bound'],
        'upper_bound' => $b['upper_bound'],
        'representative' => $b['representative'],
    ])->values()->all();
}

function choicePayload(string $key): array
{
    return ['scales' => [
        'impact' => ['bands' => choiceBands($key, 'impact')],
        'likelihood' => ['bands' => choiceBands($key, 'likelihood')],
        'strength' => ['bands' => choiceBands($key, 'strength')],
    ]];
}

function choiceAssessment(?string $key = null): Assessment
{
    return app(AssessmentCreator::class)->create(User::factory()->create(), 'Subject', null, $key);
}

// --- creating (criteria 20, 21) --------------------------------------------

test('a new assessment starts on Standard unless another scale is chosen', function () {
    $owner = User::factory()->create();

    test()->actingAs($owner)->post(route('assessments.store'), ['title' => 'Plain'])->assertRedirect();

    $assessment = Assessment::query()->where('owner_user_id', $owner->id)->firstOrFail();

    expect($assessment->scale_set_key)->toBe('default')
        ->and($assessment->impact_scale_id)->toBe(ScaleSets::resolve('default')['impact']);
})->group('scales');

test('the chosen scale\'s rows are what the new assessment points at', function () {
    $owner = User::factory()->create();

    test()->actingAs($owner)
        ->post(route('assessments.store'), ['title' => 'Small', 'scale_set_key' => 'small-organisation'])
        ->assertRedirect();

    $assessment = Assessment::query()->where('owner_user_id', $owner->id)->firstOrFail();
    $small = ScaleSets::resolve('small-organisation');

    expect($assessment->scale_set_key)->toBe('small-organisation')
        ->and([$assessment->impact_scale_id, $assessment->likelihood_scale_id, $assessment->strength_scale_id])
        ->toBe([$small['impact'], $small['likelihood'], $small['strength']]);
})->group('scales');

test('an unknown scale is a 422 and creates nothing, rather than quietly becoming Standard', function () {
    $owner = User::factory()->create();

    test()->actingAs($owner)
        ->post(route('assessments.store'), ['title' => 'Nope', 'scale_set_key' => 'does-not-exist'])
        ->assertSessionHasErrors('scale_set_key');

    expect(Assessment::query()->where('owner_user_id', $owner->id)->count())->toBe(0);
})->group('scales');

test('a scale deleted after the form opened is a form error, not a server error', function () {
    $owner = User::factory()->create();

    // Past the request's `in:` rule, as a race would be: the creator itself refuses.
    expect(fn () => app(AssessmentCreator::class)->create($owner, 'Late', null, 'gone'))
        ->toThrow(InvalidArgumentException::class);

    expect(Assessment::query()->where('owner_user_id', $owner->id)->count())->toBe(0);
})->group('scales');

test('the list page hands the pop-up the same scales, Standard first', function () {
    $owner = User::factory()->create();

    $props = test()->actingAs($owner)->get(route('assessments.index'))->viewData('page')['props'];

    expect($props['scaleOptions'])->toBe([
        ['value' => 'default', 'label' => 'Standard'],
        ['value' => 'small-organisation', 'label' => 'Small organisation'],
    ]);
})->group('scales');

// --- the settings page (criterion 12) ---------------------------------------

test('the settings page starts the dropdown on the stored scale and sends every scale in full', function () {
    $assessment = choiceAssessment('small-organisation');

    $props = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page')['props'];

    expect($props['scaleSetKey'])->toBe('small-organisation')
        ->and(array_column($props['scaleSets'], 'key'))->toBe(['default', 'small-organisation'])
        ->and($props['scaleSets'][1]['scales']['impact']['bands'][0])->toHaveKey('code');
})->group('scales');

test('an assessment with no stored scale is sent as Custom', function () {
    $assessment = Assessment::factory()->create();

    $props = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page')['props'];

    expect($props['scaleSetKey'])->toBeNull();
})->group('scales');

// --- the server decides the label (criterion 16) -----------------------------

test('saving bands that equal a scale stores that scale; anything else stores Custom', function () {
    $assessment = choiceAssessment('default');

    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', $assessment), choicePayload('small-organisation'))
        ->assertRedirect();

    expect($assessment->fresh()->scale_set_key)->toBe('small-organisation');

    $edited = choicePayload('small-organisation');
    $edited['scales']['impact']['bands'][0]['label'] = 'Edited';

    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', $assessment), $edited)
        ->assertRedirect();

    expect($assessment->fresh()->scale_set_key)->toBeNull();
})->group('scales');

test('a request cannot claim a scale it does not match', function () {
    $assessment = choiceAssessment('default');

    $edited = choicePayload('default');
    $edited['scales']['strength']['bands'][0]['label'] = 'Not Standard';

    // The key is not an input at all: sending one changes nothing.
    test()->actingAs($assessment->owner)
        ->put(route('assessments.scales.update', $assessment), $edited + ['scale_set_key' => 'default'])
        ->assertRedirect();

    expect($assessment->fresh()->scale_set_key)->toBeNull();
})->group('scales');

// --- an admin edit does not relabel (criterion 18a) ----------------------------

test('after an admin edits the scale, the assessment still names it and keeps its bands', function () {
    $assessment = choiceAssessment('default');
    $before = choiceBands('default', 'impact');
    $own = Scale::query()->findOrFail($assessment->impact_scale_id);

    $bands = $before;
    $bands[0]['label'] = 'Edited centrally';

    test()->actingAs(User::factory()->admin()->create())
        ->put(route('admin.scales.update', 'default'), ['scales' => ['impact' => ['bands' => $bands]]])
        ->assertRedirect();

    $props = test()->actingAs($assessment->owner)
        ->get(route('assessments.settings.edit', $assessment->uuid))
        ->viewData('page')['props'];

    expect($props['scaleSetKey'])->toBe('default')
        ->and($props['scales']['impact']['bands'][0]['label'])->toBe($before[0]['label'])
        ->and($props['scales']['impact']['id'])->not->toBe($own->id);
})->group('scales');

// --- independence (criterion 19) --------------------------------------------------

test('saving one assessment never changes another\'s bands', function () {
    $a = choiceAssessment('default');
    $b = choiceAssessment('default');
    $bBefore = $b->fresh()->impact_scale_id;

    $edited = choicePayload('default');
    $edited['scales']['impact']['bands'][0]['label'] = 'Only A';

    test()->actingAs($a->owner)
        ->put(route('assessments.scales.update', $a), $edited)
        ->assertRedirect();

    expect($b->fresh()->impact_scale_id)->toBe($bBefore)
        ->and(Scale::query()->findOrFail($bBefore)->levels->sortBy('ordinal')->first()->label)
        ->not->toBe('Only A');
})->group('scales');

// --- the migration's backfill (criterion 2) ------------------------------------------

test('the migration labels an assessment on Standard\'s bands as Standard and any other as Custom', function () {
    $same = choiceAssessment('default');
    $edited = choiceAssessment('default');
    $small = choiceAssessment('small-organisation');

    $bands = choiceBands('default', 'impact');
    $bands[0]['label'] = 'Edited';
    test()->actingAs($edited->owner)
        ->put(route('assessments.scales.update', $edited), ['scales' => ['impact' => ['bands' => $bands]]])
        ->assertRedirect();

    Assessment::withTrashed()->update(['scale_set_key' => null]);
    $calc = [$same, $edited, $small];
    $versions = array_map(fn (Assessment $x): int => (int) DB::table('assessments')->where('id', $x->id)->value('calc_version'), $calc);

    $migration = require database_path('migrations/2026_10_15_000001_create_scale_sets_table.php');
    $backfill = new ReflectionMethod($migration, 'backfill');
    $backfill->invoke($migration);

    expect($same->fresh()->scale_set_key)->toBe('default')
        ->and($edited->fresh()->scale_set_key)->toBeNull()
        // Only the default is compared: it is the only scale choosable before W126.
        ->and($small->fresh()->scale_set_key)->toBeNull()
        // A label, not a calculation input: nothing was recalculated.
        ->and(array_map(fn (Assessment $x): int => (int) DB::table('assessments')->where('id', $x->id)->value('calc_version'), $calc))
        ->toBe($versions);
})->group('scales');

test('the Standard and Small organisation scales are not equal, so the match is meaningful', function () {
    expect(choiceBands('default', 'impact'))->not->toBe(choiceBands('small-organisation', 'impact'))
        ->and(ScaleSet::query()->count())->toBe(2);
})->group('scales');
