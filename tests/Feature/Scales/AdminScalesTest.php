<?php

/**
 * Admin-defined scales (W126, D92-D94): create, save, rename, delete.
 *
 * Authorization first, as for every route: each write is refused to a
 * non-admin and to an anonymous caller. Then the rules of the plan
 * (docs/PLAN-2.7-scales.md), one test per acceptance criterion.
 */

use App\Domain\Retention\AssessmentEraser;
use App\Domain\Scales\ScaleDescriber;
use App\Domain\Scales\ScaleSets;
use App\Domain\Structure\AssessmentCreator;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\ScaleSet;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => test()->seed(SystemScaleSeeder::class));

function adminScalesActor(): User
{
    return User::factory()->admin()->create();
}

/** A custom scale made through the real route, copied from Standard. */
function makeAcme(string $name = 'Acme', string $from = 'default'): ScaleSet
{
    test()->actingAs(adminScalesActor())
        ->post(route('admin.scales.store'), ['name' => $name, 'from' => $from])
        ->assertRedirect(route('admin.defaults.edit'));

    return ScaleSet::query()->where('name', $name)->firstOrFail();
}

function adminScaleBands(string $key, string $kind): array
{
    return collect(ScaleDescriber::forSystemSet($key)[$kind]['bands'])->map(fn (array $b): array => [
        'code' => $b['code'],
        'label' => $b['label'],
        'lower_bound' => $b['lower_bound'],
        'upper_bound' => $b['upper_bound'],
        'representative' => $b['representative'],
    ])->values()->all();
}

// --- authorization (criterion 4) -------------------------------------------

test('every admin scale write is refused to a non-admin and sends an anonymous caller to login', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $set = ScaleSet::query()->where('key', 'default')->firstOrFail();

    $requests = [
        ['post', route('admin.scales.store'), ['name' => 'X', 'from' => 'default']],
        ['put', route('admin.scales.update', $set->key), ['scales' => ['impact' => ['bands' => adminScaleBands('default', 'impact')]]]],
        ['put', route('admin.scales.rename', $set->key), ['name' => 'Y']],
        ['delete', route('admin.scales.destroy', $set->key), []],
    ];

    foreach ($requests as [$method, $url, $data]) {
        test()->actingAs($user)->{$method}($url, $data)->assertForbidden();
    }

    auth()->logout();

    foreach ($requests as [$method, $url, $data]) {
        test()->{$method}($url, $data)->assertRedirect(route('login'));
    }

    expect(ScaleSet::query()->count())->toBe(2);
})->group('scales');

// --- the migration's and seeder's built-ins (criterion 1, 3) ----------------

test('the two built-in scales exist, in order, and the options are read from the table', function () {
    $sets = ScaleSet::query()->orderBy('position')->get();

    expect($sets->pluck('key')->all())->toBe(['default', 'small-organisation'])
        ->and($sets->pluck('name')->all())->toBe(['Standard', 'Small organisation'])
        ->and($sets->every(fn (ScaleSet $s): bool => $s->is_builtin))->toBeTrue()
        ->and(ScaleSets::options())->toBe([
            ['value' => 'default', 'label' => 'Standard'],
            ['value' => 'small-organisation', 'label' => 'Small organisation'],
        ]);
})->group('scales');

test('re-seeding does not undo an edit to a built-in scale\'s position or add duplicates', function () {
    test()->seed(SystemScaleSeeder::class);

    expect(ScaleSet::query()->count())->toBe(2);
})->group('scales');

// --- create (criterion 5) ---------------------------------------------------

test('a new scale is a complete copy of the one it starts from, and is listed after the built-ins', function () {
    $set = makeAcme('Acme', 'small-organisation');

    expect($set->is_builtin)->toBeFalse()
        ->and($set->key)->toBe('acme');

    foreach (['impact', 'likelihood', 'strength'] as $kind) {
        $new = adminScaleBands('acme', $kind);
        $source = adminScaleBands('small-organisation', $kind);

        expect($new)->toBe($source);

        $row = Scale::query()->where('set_key', 'acme')->where('kind', $kind)->firstOrFail();
        expect($row->is_system)->toBeTrue()
            ->and($row->assessment_id)->toBeNull()
            ->and($row->name)->toBe(ucfirst($kind).' (Acme)');
    }

    expect(array_column(ScaleSets::options(), 'value'))->toBe(['default', 'small-organisation', 'acme']);
})->group('scales');

test('a duplicate name is refused, whatever its case, and a name that slugs to an existing key gets its own key', function () {
    makeAcme('Acme');

    test()->actingAs(adminScalesActor())
        ->post(route('admin.scales.store'), ['name' => 'acme', 'from' => 'default'])
        ->assertSessionHasErrors('name');

    $second = makeAcme('Acme!');

    expect($second->key)->toBe('acme-2')
        ->and(ScaleSet::query()->count())->toBe(4);
})->group('scales');

test('no scale can take the key the dropdown uses for Custom', function () {
    $set = makeAcme('Custom');

    expect($set->key)->not->toBe('custom')
        ->and($set->name)->toBe('Custom');
})->group('scales');

test('a new scale cannot start from a scale that does not exist', function () {
    test()->actingAs(adminScalesActor())
        ->post(route('admin.scales.store'), ['name' => 'Acme', 'from' => 'nope'])
        ->assertSessionHasErrors('from');

    expect(ScaleSet::query()->where('name', 'Acme')->exists())->toBeFalse();
})->group('scales');

// --- save (criteria 6, 7, 8) -------------------------------------------------

test('saving a scale writes all three kinds or none', function () {
    $set = makeAcme();

    $impact = adminScaleBands('acme', 'impact');
    $impact[0]['label'] = 'Fine';
    $likelihood = adminScaleBands('acme', 'likelihood');
    // Breaks contiguity: the first band must start at zero.
    $likelihood[0]['lower_bound'] = 0.5;

    test()->actingAs(adminScalesActor())
        ->put(route('admin.scales.update', $set->key), ['scales' => [
            'impact' => ['bands' => $impact],
            'likelihood' => ['bands' => $likelihood],
        ]])
        ->assertSessionHasErrors("bands_{$set->key}");

    // The impact bands, valid on their own, were not written either.
    expect(adminScaleBands('acme', 'impact')[0]['label'])->not->toBe('Fine');

    $likelihood[0]['lower_bound'] = 0.0;

    test()->actingAs(adminScalesActor())
        ->put(route('admin.scales.update', $set->key), ['scales' => [
            'impact' => ['bands' => $impact],
            'likelihood' => ['bands' => $likelihood],
        ]])
        ->assertRedirect(route('admin.defaults.edit'));

    expect(adminScaleBands('acme', 'impact')[0]['label'])->toBe('Fine');
})->group('scales');

test('saving one scale does not touch another', function () {
    $set = makeAcme();
    $before = adminScaleBands('default', 'impact');

    $bands = adminScaleBands('acme', 'impact');
    $bands[0]['label'] = 'Only in Acme';

    test()->actingAs(adminScalesActor())
        ->put(route('admin.scales.update', $set->key), ['scales' => ['impact' => ['bands' => $bands]]])
        ->assertRedirect();

    expect(adminScaleBands('default', 'impact'))->toBe($before)
        ->and(adminScaleBands('acme', 'impact')[0]['label'])->toBe('Only in Acme');
})->group('scales');

test('a payload with no real kind is refused and writes nothing', function () {
    $set = makeAcme();
    $before = adminScaleBands('acme', 'impact');

    test()->actingAs(adminScalesActor())
        ->put(route('admin.scales.update', $set->key), ['scales' => ['colour' => ['bands' => $before]]])
        ->assertSessionHasErrors('scales');

    expect(adminScaleBands('acme', 'impact'))->toBe($before);
})->group('scales');

test('saving a scale first gives an assessment still on its rows its own copy, and a new assessment starts from the new bands', function () {
    $set = makeAcme();
    $owner = User::factory()->create();
    $assessment = app(AssessmentCreator::class)->create($owner, 'On Acme', null, 'acme');

    $level = Scale::query()->findOrFail($assessment->impact_scale_id)->levels->sortBy('ordinal')->first();
    Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'impact_mode' => 'qualitative',
        'impact_amount' => null,
        'impact_level_id' => $level->id,
    ]);
    $originalLabel = $level->label;

    $bands = adminScaleBands('acme', 'impact');
    $bands[0]['label'] = 'Changed in Acme';

    test()->actingAs(adminScalesActor())
        ->put(route('admin.scales.update', $set->key), ['scales' => ['impact' => ['bands' => $bands]]])
        ->assertRedirect();

    $own = Scale::query()->findOrFail($assessment->fresh()->impact_scale_id);

    expect($own->is_system)->toBeFalse()
        ->and($own->levels->sortBy('ordinal')->first()->label)->toBe($originalLabel)
        ->and($assessment->fresh()->scale_set_key)->toBe('acme');

    $next = app(AssessmentCreator::class)->create($owner, 'Next on Acme', null, 'acme');

    expect(Scale::query()->findOrFail($next->impact_scale_id)->levels->sortBy('ordinal')->first()->label)
        ->toBe('Changed in Acme');
})->group('scales');

// --- rename and built-ins (criterion 9) ---------------------------------------

test('a custom scale can be renamed, its scale rows with it; a built-in cannot', function () {
    $set = makeAcme();

    test()->actingAs(adminScalesActor())
        ->put(route('admin.scales.rename', $set->key), ['name' => 'Acme Corp'])
        ->assertRedirect();

    expect($set->fresh()->name)->toBe('Acme Corp')
        ->and(Scale::query()->where('set_key', 'acme')->where('kind', 'impact')->value('name'))->toBe('Impact (Acme Corp)')
        ->and(ScaleSets::label('acme'))->toBe('Acme Corp');

    test()->actingAs(adminScalesActor())
        ->put(route('admin.scales.rename', 'default'), ['name' => 'Renamed'])
        ->assertSessionHasErrors('name_default');

    expect(ScaleSet::query()->where('key', 'default')->value('name'))->toBe('Standard');
})->group('scales');

test('a built-in scale cannot be deleted', function () {
    foreach (['default', 'small-organisation'] as $key) {
        test()->actingAs(adminScalesActor())
            ->delete(route('admin.scales.destroy', $key))
            ->assertSessionHasErrors("bands_{$key}");

        expect(ScaleSet::query()->where('key', $key)->exists())->toBeTrue();
    }
})->group('scales');

// --- delete (criterion 10) -----------------------------------------------------

test('deleting a scale in use leaves assessments with identical private copies, labelled Custom', function () {
    $set = makeAcme();
    $owner = User::factory()->create();
    $assessment = app(AssessmentCreator::class)->create($owner, 'On Acme', null, 'acme');

    $level = Scale::query()->findOrFail($assessment->impact_scale_id)->levels->sortBy('ordinal')->get(1);
    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'impact_mode' => 'qualitative',
        'impact_amount' => null,
        'impact_level_id' => $level->id,
    ]);
    $labelsBefore = Scale::query()->findOrFail($assessment->impact_scale_id)->levels->sortBy('ordinal')->pluck('label')->all();

    test()->actingAs(adminScalesActor())
        ->delete(route('admin.scales.destroy', $set->key))
        ->assertRedirect(route('admin.defaults.edit'))
        ->assertSessionHas('success');

    $assessment->refresh();
    $own = Scale::query()->findOrFail($assessment->impact_scale_id);

    expect(ScaleSet::query()->where('key', 'acme')->exists())->toBeFalse()
        ->and(Scale::query()->where('set_key', 'acme')->exists())->toBeFalse()
        ->and($assessment->scale_set_key)->toBeNull()
        ->and($own->is_system)->toBeFalse()
        ->and($own->assessment_id)->toBe($assessment->id)
        ->and($own->levels->sortBy('ordinal')->pluck('label')->all())->toBe($labelsBefore)
        // The stored value still names a band, now on the copy.
        ->and(ScaleLevel::query()->findOrFail($risk->fresh()->impact_level_id)->scale_id)->toBe($own->id);
})->group('scales');

test('deleting counts assessments, not the scales each one was following', function () {
    $set = makeAcme();
    $owner = User::factory()->create();
    app(AssessmentCreator::class)->create($owner, 'One', null, 'acme');
    app(AssessmentCreator::class)->create($owner, 'Two', null, 'acme');

    // Each follows all three rows, so counting per scale would say 6.
    $response = test()->actingAs(adminScalesActor())->delete(route('admin.scales.destroy', $set->key));

    expect(session('success'))->toContain('2 assessment(s)')
        ->and(session('success'))->not->toContain('6 assessment');
    $response->assertRedirect();
})->group('scales');

test('deleting a scale nothing uses just removes it', function () {
    $set = makeAcme();

    test()->actingAs(adminScalesActor())
        ->delete(route('admin.scales.destroy', $set->key))
        ->assertRedirect();

    expect(ScaleSet::query()->count())->toBe(2)
        ->and(ScaleLevel::query()->whereIn('scale_id', Scale::query()->where('set_key', 'acme')->select('id'))->exists())->toBeFalse();
})->group('scales');

// --- the page (criteria 3, 11) ---------------------------------------------------

test('the Defaults tab sends every scale, built-ins first, each with all three kinds', function () {
    makeAcme('Zulu');
    makeAcme('Acme');

    $props = test()->actingAs(adminScalesActor())
        ->get(route('admin.defaults.edit'))
        ->viewData('page')['props'];

    expect(array_column($props['scaleSets'], 'key'))->toBe(['default', 'small-organisation', 'acme', 'zulu'])
        ->and(array_column($props['scaleSets'], 'is_builtin'))->toBe([true, true, false, false])
        ->and(array_keys($props['scaleSets'][2]['scales']))->toEqualCanonicalizing(['impact', 'likelihood', 'strength']);
})->group('scales');

test('without a Standard scale an assessment cannot be created, and the error says how to fix it', function () {
    Scale::query()->where('set_key', 'default')->where('kind', 'strength')->each(function (Scale $s): void {
        ScaleLevel::query()->where('scale_id', $s->id)->delete();
        $s->delete();
    });

    expect(fn () => app(AssessmentCreator::class)->create(User::factory()->create(), 'X'))
        ->toThrow(RuntimeException::class, 'SystemScaleSeeder');
})->group('scales');

test('an erase repoints a calibrated assessment at the Standard scale, not an arbitrary one', function () {
    makeAcme();
    $owner = User::factory()->create();
    $assessment = app(AssessmentCreator::class)->create($owner, 'Calibrated', null, 'acme');

    $bands = adminScaleBands('acme', 'impact');
    $bands[0]['label'] = 'Mine';
    test()->actingAs($owner)
        ->put(route('assessments.scales.update', $assessment), ['scales' => ['impact' => ['bands' => $bands]]])
        ->assertRedirect();

    $assessment->delete();

    $bindings = [];
    DB::listen(function ($query) use (&$bindings): void {
        if (str_starts_with($query->sql, 'update `assessments` set `impact_scale_id`')) {
            $bindings = $query->bindings;
        }
    });

    app(AssessmentEraser::class)->erase($assessment->fresh() ?? Assessment::withTrashed()->findOrFail($assessment->id));

    $standard = ScaleSets::resolve('default');

    // The three ids it was repointed at are Standard's, in kind order. With
    // several scales present an unordered pick would be arbitrary.
    expect(Assessment::withTrashed()->find($assessment->id))->toBeNull()
        ->and($bindings)->toContain($standard['impact'], $standard['likelihood'], $standard['strength']);
})->group('scales');
