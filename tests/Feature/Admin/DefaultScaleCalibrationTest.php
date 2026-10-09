<?php

/**
 * `instance_settings.default_scale_calibration` — a reference to a seeded
 * system scale set, and the thing a new assessment was created from until
 * 2.5.1 removed it.
 *
 * REMOVED (2.5.1). The W87 Defaults tab made the default set's bands editable
 * directly, after which this select was a second, older answer to "what does a
 * new assessment start with", naming a set nobody could see from the screen
 * that named it. There is one default set now. The W77 guards about seeding
 * and resolving sets below still hold — `set_key` and the second seeded set
 * both remain — and the four that were about the setting are rewritten as
 * guards on its *absence*, the way W80 treated `ReportOptions::$figures`: not
 * as a resolved default, because a reintroduced setting defaulting to
 * `default` would pass "new assessments use the default set" while being the
 * exact dead control that was removed.
 *
 * FIXED (W77). These four tests were W75's `DEFECT:` pins, asserting that the
 * "Default scale calibration" field on the admin settings page was a
 * free-text box that did nothing: whatever an admin typed was validated
 * (`required|string|max:64`), written to a varchar(64) defaulting to the
 * literal string `default`, echoed back on the next render, and never
 * consulted again. `AssessmentCreator` picked an assessment's three scales by
 * querying for `is_system = 1, assessment_id IS NULL` and ignored the setting
 * entirely. PLAN-2.4.md D36 called it "a text box that does nothing".
 *
 * They are rewritten in place rather than deleted, so that the reasoning
 * survives and the fix cannot be quietly reverted — the treatment W24 gave
 * the security pins and W68 gave the observer pin.
 *
 * ## What the fix was, and the trap it had to clear first
 *
 * The obvious first step — seed a second system set — was actively
 * dangerous, and W75 confirmed it empirically: with two sets seeded the whole
 * suite stayed **green** while assessment creation picked an arbitrary
 * calibration. Three things had to change together:
 *
 * 1. **`scales.set_key`** (W77 migration). A set was an implicit trio of rows
 *    sharing `is_system = 1, assessment_id IS NULL` and nothing else; nothing
 *    grouped or named one, so nothing could select between two.
 * 2. **`SystemScaleSeeder::seedScale()`**, whose `firstOrCreate` keyed on
 *    (`kind`, `is_system`, `assessment_id`) — exactly what two system sets
 *    share — and so could not have seeded a second set at all. It would have
 *    matched the first set's row and returned it under the second set's name.
 * 3. **`AssessmentCreator::systemScales()`**, which did `pluck('id', 'kind')`
 *    over every system scale. With two sets, two rows carry
 *    `kind = 'impact'`, `pluck` silently keeps whichever the database
 *    returned last, and every new assessment gets an arbitrary calibration
 *    with nothing raised anywhere. That is the failure mode CLAUDE.md's
 *    identity-column warning describes — green suite, broken feature —
 *    arriving by a different route.
 *
 * ## The trade-off the fix carries
 *
 * `default_scale_calibration` has stopped being an inert string and become a
 * reference, so a set an admin selects and someone later deletes must not
 * break assessment creation. The fallback to the `default` set is therefore
 * part of the fix rather than an afterthought, and it is pinned below. The
 * admin-facing half is the `in:` rule, which refuses to *store* a dangling
 * reference in the first place.
 */

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Scales\ScaleSets;
use App\Domain\Structure\AssessmentCreator;
use App\Models\Scale;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // The real seeder, not factories: this is a test about what a deployment
    // actually installs, and every other test in the suite builds throwaway
    // scales that would hide the "how many sets are there" half of it.
    $this->seed(SystemScaleSeeder::class);
});

test('FIXED (W77): more than one system scale set exists, so the select is a real choice', function () {
    $system = Scale::query()
        ->whereNull('assessment_id')
        ->where('is_system', true)
        ->get();

    // Was: three rows, one set, and D36's select would have had one option —
    // "the same non-control in a different shape".
    expect(ScaleSets::seededKeys())
        ->toBe([ScaleSets::DEFAULT_KEY, ScaleSets::SMALL_ORGANISATION_KEY]);
    expect($system)->toHaveCount(6);

    foreach (ScaleSets::seededKeys() as $key) {
        expect(
            $system->where('set_key', $key)->pluck('kind')->sort()->values()->all()
        )->toBe(['impact', 'likelihood', 'strength']);
    }
});

test('FIXED (W77): the second set is a different calibration, not a copy of the first', function () {
    // D36 asks for a choice. Two sets holding identical numbers would satisfy
    // the select and none of the reason for it, so this asserts the sets
    // actually differ where a calibration is supposed to differ — the bands.
    //
    // Level *codes* are deliberately identical across sets and are not
    // asserted to differ: a calibration says what "moderate" is worth, not
    // which words exist, and the catalogue and template fixtures resolve
    // levels by code (schema.md §3.2).
    foreach (ScaleSets::KINDS as $kind) {
        $bands = collect(ScaleSets::seededKeys())->map(
            fn (string $key): array => Scale::query()
                ->where('set_key', $key)
                ->where('kind', $kind)
                ->firstOrFail()
                ->levels()
                ->pluck('representative')
                ->all()
        );

        expect($bands[0])->not->toBe($bands[1], "the two sets' {$kind} bands are identical");
    }
});

test('FIXED (W77): re-running the seeder duplicates neither set nor its levels', function () {
    // The lookup that keeps this true is the same one that had to change for
    // a second set to be seedable at all: it keyed on (kind, is_system,
    // assessment_id), which is exactly what two sets share. A regression
    // there shows up here as duplicate rows, and in resolve() as a throw.
    $this->seed(SystemScaleSeeder::class);
    $this->seed(SystemScaleSeeder::class);

    $system = Scale::query()->whereNull('assessment_id')->where('is_system', true)->get();

    expect($system)->toHaveCount(6);
    expect(ScaleSets::seededKeys())
        ->toBe([ScaleSets::DEFAULT_KEY, ScaleSets::SMALL_ORGANISATION_KEY]);

    foreach ($system as $scale) {
        expect($scale->levels()->count())->toBe(5);
    }
});

test('FIXED (W77): every seeded set satisfies the schema.md §3.2 invariants', function () {
    // A second set is a second chance to write bands that do not band. Every
    // one of these is invariant 1-5 from schema.md §3.2; invariant 5 in
    // particular is the only thing making the qualitative round trip
    // lossless, and it is silent when broken.
    foreach (Scale::query()->whereNull('assessment_id')->where('is_system', true)->get() as $scale) {
        $levels = $scale->levels()->get();
        $where = "{$scale->set_key}/{$scale->kind}";

        expect($levels->pluck('ordinal')->all())->toBe([1, 2, 3, 4, 5], "{$where}: ordinals");
        expect($levels->first()->lower_bound)->toBe(0.0, "{$where}: first band starts at 0");

        foreach ($levels as $index => $level) {
            $next = $levels[$index + 1] ?? null;

            if ($next !== null) {
                expect($level->upper_bound)->toBe($next->lower_bound, "{$where}: gap or overlap at band {$level->code}");
            } elseif ($scale->unit === 'fraction') {
                expect($level->upper_bound)->toBe(1.0, "{$where}: a fraction's top band closes at 1");
            } else {
                expect($level->upper_bound)->toBeNull("{$where}: the top band is open");
            }

            expect($level->representative)->toBeGreaterThanOrEqual($level->lower_bound, "{$where}: {$level->code} representative below its band");

            if ($level->upper_bound !== null && $next !== null) {
                expect($level->representative)->toBeLessThan($level->upper_bound, "{$where}: {$level->code} representative above its band");
            }
        }
    }
});

test('FIXED (W77): the scales table says which set a scale belongs to', function () {
    // The grouping key the fix needed. Asserted on the model's attributes
    // rather than by reading the migration, so it is the column's presence in
    // a loaded row that this depends on.
    $scale = Scale::query()->where('is_system', true)->firstOrFail();

    expect(array_keys($scale->getAttributes()))->toContain('set_key');
    expect($scale->set_key)->not->toBeNull();
});

test('REMOVED (2.5.1): a new assessment starts from the default set, whatever else is seeded', function () {
    $owner = User::factory()->create();

    // Two complete sets are seeded, so this still guards the pluck landmine:
    // selecting by kind alone would pick one of the two impact rows by row
    // order, and half the time it would be the other set's.
    expect(ScaleSets::seededKeys())->toContain(ScaleSets::SMALL_ORGANISATION_KEY);

    $assessment = app(AssessmentCreator::class)->create($owner, 'A target');

    $ids = Scale::query()->where('set_key', ScaleSets::DEFAULT_KEY)->pluck('id', 'kind');

    expect($assessment->impact_scale_id)->toBe($ids['impact']);
    expect($assessment->likelihood_scale_id)->toBe($ids['likelihood']);
    expect($assessment->strength_scale_id)->toBe($ids['strength']);
});

test('REMOVED (2.5.1): the setting no longer exists in storage or in the settings read', function () {
    expect(Schema::hasColumn('instance_settings', 'default_scale_calibration'))->toBeFalse();
    expect(InstanceSettings::all())->not->toHaveKey('default_scale_calibration');
});

test('FIXED (W77): a set holding two scales of one kind throws rather than choosing', function () {
    // The fallback above answers "this set is not here". A set that *is*
    // here and malformed is a different thing: quietly substituting another
    // calibration for it would be the same silent-wrong-answer failure by a
    // longer route, so resolution refuses.
    $duplicate = new Scale([
        'kind' => 'impact',
        'name' => 'A second impact scale in the default set',
        'unit' => 'currency',
        'is_system' => true,
    ]);
    // Identity column, assigned as a property — CLAUDE.md.
    $duplicate->set_key = ScaleSets::DEFAULT_KEY;
    $duplicate->save();

    expect(fn () => ScaleSets::resolve(ScaleSets::DEFAULT_KEY))
        ->toThrow(RuntimeException::class, 'more than one impact scale');
});

test('FIXED (W77): an incomplete set is never offered as a choice', function () {
    // An assessment's three scale FKs are NOT NULL, so a set missing a kind
    // cannot start one and must not appear in the select.
    Scale::query()
        ->where('set_key', ScaleSets::SMALL_ORGANISATION_KEY)
        ->where('kind', 'strength')
        ->delete();

    expect(ScaleSets::seededKeys())->toBe([ScaleSets::DEFAULT_KEY]);
    expect(ScaleSets::selectableKeys())->toBe([ScaleSets::DEFAULT_KEY]);
});

test('REMOVED (2.5.1): a stale form posting the old setting saves without error and stores nothing', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/settings', [
        'default_scale_calibration' => ScaleSets::SMALL_ORGANISATION_KEY,
        'public_assessments_allowed' => true,
        'session_lifetime_minutes' => 480,
    ])->assertSessionHasNoErrors();

    expect(InstanceSettings::all())->not->toHaveKey('default_scale_calibration');

    $assessment = app(AssessmentCreator::class)->create($admin, 'A target');
    expect(Scale::query()->find($assessment->impact_scale_id)?->set_key)->toBe(ScaleSets::DEFAULT_KEY);
});

test('REMOVED (2.5.1): the settings page offers no calibration to choose', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/settings')->assertInertia(
        fn ($page) => $page
            ->missing('scaleCalibrations')
            ->missing('settings.default_scale_calibration')
    );
});
