<?php

/**
 * `scale_levels.code` is internal, derived on create, and frozen on rename
 * (W87, PLAN-2.5.md D44).
 *
 * **This file holds the most important test in the package**, and it is worth
 * saying what would go wrong without it, because the failure is silent.
 *
 * `code` is the only stable name a band has. A custom scale is *cloned per
 * assessment* — `ScaleCalibrator::cloneForAssessment()` writes fresh
 * `ScaleLevel` rows with fresh ids — so the id of "major" is different in every
 * assessment and does not exist until the clone runs. A catalogue fixture is
 * shipped once and has to resolve in all of them, which is why
 * `catalog-iso27001-27002.json` says `"impact_level_code": "major"` and why
 * both `AssessmentCloner::resolveLevelDefault()` and
 * `CatalogInserter::resolveLevelDefault()` look a level up by code within a
 * scale. It is also `impact_level_code` / `aro_level_code` in
 * calculation-engine.md §8.3's normative payload, and the key
 * `ScaleCalibrator::repoint()` matches bands by across a clone.
 *
 * So "stop asking the user for a code" had two halves, and only one of them is
 * obvious. Removing the input is the easy half. The dangerous half is what
 * replaces it: **deriving the code again on every save** would look correct,
 * pass every test that checks the band still renders, and quietly detach every
 * catalogue entry that named the old value the first time anybody renamed a
 * band. That is the same shape as W78's silent half.
 *
 * Hence: renaming does not change the code, and a catalogue entry still
 * resolves afterwards. Both, together, or the second is not a test of the
 * first.
 */

use App\Domain\Catalog\CatalogInserter;
use App\Domain\Scales\LevelCodeDeriver;
use App\Models\Assessment;
use App\Models\CatalogEntry;
use App\Models\Risk;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

/** The bands of an assessment's current scale, in the shape the endpoint takes. */
function codeTestBands(Assessment $assessment, string $kind): array
{
    $scale = Scale::query()->findOrFail($assessment->getAttribute("{$kind}_scale_id"));

    return $scale->levels->sortBy('ordinal')->map(fn (ScaleLevel $level): array => [
        'code' => $level->code,
        'label' => $level->label,
        'lower_bound' => (float) $level->lower_bound,
        'upper_bound' => $level->upper_bound === null ? null : (float) $level->upper_bound,
        'representative' => (float) $level->representative,
    ])->values()->all();
}

function saveBands(User $actor, Assessment $assessment, string $kind, array $bands): TestResponse
{
    return test()->actingAs($actor)
        ->put(route('assessments.scales.update', [$assessment, $kind]), ['bands' => $bands]);
}

function impactLevels(Assessment $assessment): Collection
{
    return Scale::query()->findOrFail($assessment->fresh()->impact_scale_id)->levels;
}

// --- the one that matters -------------------------------------------------

test('renaming a band does not change its code', function () {
    $assessment = Assessment::factory()->create();

    $bands = codeTestBands($assessment, 'impact');
    $index = collect($bands)->search(fn (array $b): bool => $b['code'] === 'moderate');
    expect($index)->not->toBeFalse();

    $bands[$index]['label'] = 'Medium';

    saveBands($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    $renamed = impactLevels($assessment)->firstWhere('code', 'moderate');

    // Frozen. If this ever reads `medium`, every catalogue fixture naming
    // `moderate` has just stopped resolving against this assessment, and
    // nothing anywhere would have said so.
    expect($renamed)->not->toBeNull()
        ->and($renamed->label)->toBe('Medium')
        ->and(impactLevels($assessment)->pluck('code'))->not->toContain('medium');
})->group('scales');

test('a catalogue entry still resolves after the band it names is renamed', function () {
    $entry = CatalogEntry::factory()->create([
        'kind' => 'risk',
        'title' => 'Data breach',
        'defaults' => ['impact_mode' => 'qualitative', 'impact_level_code' => 'moderate'],
    ]);

    $assessment = Assessment::factory()->create();

    $bands = codeTestBands($assessment, 'impact');
    $index = collect($bands)->search(fn (array $b): bool => $b['code'] === 'moderate');
    $bands[$index]['label'] = 'Medium';

    saveBands($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    // The save also *cloned* the scale, so the level's id is new. That is the
    // point of the whole design: an id could not have survived this, and the
    // code did.
    $node = app(CatalogInserter::class)->insert($entry, $assessment->fresh())['root'];

    $expected = impactLevels($assessment)->firstWhere('code', 'moderate');

    expect($node)->toBeInstanceOf(Risk::class)
        ->and($node->impact_mode)->toBe('qualitative')
        ->and($node->impact_level_id)->toBe($expected->id)
        ->and($expected->label)->toBe('Medium');
})->group('scales');

// --- deriving on create ---------------------------------------------------

test('a band the editor creates is given a code derived from its label', function () {
    $assessment = Assessment::factory()->create();

    $bands = codeTestBands($assessment, 'impact');
    // A new band, as the editor sends one: no code at all.
    $top = count($bands) - 1;
    $boundary = 20_000_000.0;
    $bands[$top]['upper_bound'] = $boundary;
    $bands[] = [
        'code' => null,
        'label' => 'Existential Risk!',
        'lower_bound' => $boundary,
        'upper_bound' => null,
        'representative' => 60_000_000.0,
    ];

    saveBands($assessment->owner, $assessment, 'impact', $bands)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(impactLevels($assessment)->pluck('code'))->toContain('existential_risk');
})->group('scales');

test('a code the scale does not have is treated as a new band, never as a rename', function () {
    $assessment = Assessment::factory()->create();

    $bands = codeTestBands($assessment, 'impact');
    $before = impactLevels($assessment)->count();

    // A hand-made request, or a stale form posting bands from a calibration
    // that has since been replaced. Accepting the string would let a caller
    // name a row it has no business naming.
    $bands[0]['code'] = 'not_a_band_here';
    $bands[0]['label'] = 'Trivial';

    saveBands($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    $codes = impactLevels($assessment)->pluck('code');

    expect($codes)->not->toContain('not_a_band_here')
        ->and($codes)->toContain('trivial')
        ->and(impactLevels($assessment)->count())->toBe($before);
})->group('scales');

test('a code that is not slug-shaped is rejected rather than derived around', function () {
    $assessment = Assessment::factory()->create();

    $bands = codeTestBands($assessment, 'impact');
    $bands[0]['code'] = 'Not A Slug';

    // The regex is what stops a hand-made request writing a code no catalogue
    // fixture could ever name. It is a 422, not a quiet re-derivation, because
    // nothing the editor sends can look like this.
    saveBands($assessment->owner, $assessment, 'impact', $bands)
        ->assertSessionHasErrors('bands.0.code');
})->group('scales');

// --- collisions, against the real unique index ----------------------------

test('a new band whose label matches an existing band gets a suffixed code', function () {
    $assessment = Assessment::factory()->create();

    $bands = codeTestBands($assessment, 'impact');
    $top = count($bands) - 1;
    $boundary = 20_000_000.0;
    $bands[$top]['upper_bound'] = $boundary;
    $bands[] = [
        'code' => null,
        // Deliberately the label of an existing band. With the code field
        // gone this is an ordinary thing to do by accident.
        'label' => 'Moderate',
        'lower_bound' => $boundary,
        'upper_bound' => null,
        'representative' => 60_000_000.0,
    ];

    saveBands($assessment->owner, $assessment, 'impact', $bands)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $codes = impactLevels($assessment)->pluck('code');

    expect($codes)->toContain('moderate')
        ->and($codes)->toContain('moderate_2')
        ->and($codes->count())->toBe($codes->unique()->count());
})->group('scales');

test('a band removed and a new one with the same label do not share a code', function () {
    $assessment = Assessment::factory()->create();

    $bands = codeTestBands($assessment, 'impact');
    $index = collect($bands)->search(fn (array $b): bool => $b['code'] === 'minor');

    // Delete "Minor" and add a band called "Minor" in the same save. Reusing
    // the code would hand the new band the deleted one's stored values and
    // every catalogue entry that named it — bindings the user thinks they have
    // just discarded.
    $bands[$index]['code'] = null;
    $bands[$index]['label'] = 'Minor';

    saveBands($assessment->owner, $assessment, 'impact', $bands)->assertRedirect();

    $levels = impactLevels($assessment);
    $recreated = $levels->firstWhere('label', 'Minor');

    expect($recreated->code)->toBe('minor_2')
        ->and($levels->pluck('code')->count())->toBe($levels->pluck('code')->unique()->count());
})->group('scales');

test('the unique index is never reached, whatever the payload claims', function () {
    $assessment = Assessment::factory()->create();

    $bands = codeTestBands($assessment, 'impact');

    // Every band carrying the same code, and every band carrying the same
    // label. `scale_levels_code_unique (scale_id, code)` would turn this into
    // a 500 partway through a save if the two-pass assignment were not there.
    foreach ($bands as $i => $_) {
        $bands[$i]['code'] = 'moderate';
        $bands[$i]['label'] = 'Same';
    }

    saveBands($assessment->owner, $assessment, 'impact', $bands)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $codes = impactLevels($assessment)->pluck('code');

    expect($codes->count())->toBe($codes->unique()->count());
})->group('scales');

// --- the deriver itself ---------------------------------------------------

test('the deriver always produces the slug shape the column and the regex require', function (string $label) {
    $code = LevelCodeDeriver::derive($label, []);

    expect($code)->toMatch('/^[a-z][a-z0-9_]*$/')
        ->and(strlen($code))->toBeLessThanOrEqual(LevelCodeDeriver::MAX_LENGTH);
})->with([
    'ordinary' => 'Moderate',
    'spaced' => 'Very strong',
    'punctuated' => 'Major — but not severe!',
    'leading digit' => '100k and above',
    'entirely punctuation' => '???',
    'accented' => 'Élevé',
    'empty' => '',
    'over-long' => 'An extremely long band label that certainly exceeds the column width',
])->group('scales');

test('the deriver suffixes rather than truncating into a collision', function () {
    $long = str_repeat('a', 40);
    $first = LevelCodeDeriver::derive($long, []);
    $second = LevelCodeDeriver::derive($long, [$first]);

    expect($first)->not->toBe($second)
        ->and(strlen($second))->toBeLessThanOrEqual(LevelCodeDeriver::MAX_LENGTH)
        ->and($second)->toMatch('/^[a-z][a-z0-9_]*$/');
})->group('scales');
