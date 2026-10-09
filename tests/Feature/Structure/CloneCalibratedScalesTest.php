<?php

use App\Domain\Structure\AssessmentCloner;
use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/*
 * FIXED (W78): AssessmentCloner::cloneScaleIfCustom() mass-assigned both of
 * its identity columns — `assessment_id` on Scale and `scale_id` on
 * ScaleLevel — each of which is deliberately absent from its model's
 * `$fillable`, and each of whose model docblocks says so in as many words.
 * Eloquent dropped both silently and the level insert then died:
 *
 *     SQLSTATE[HY000]: General error: 1364
 *     Field 'scale_id' doesn't have a default value
 *
 * Measured by W77; fixed by W78 under an explicit scope addition, because
 * W78 was already inside this file with the identity-column rule in hand and
 * the file is better touched once than twice.
 *
 * ## Why it survived this long
 *
 * An assessment whose scales have been calibrated could not be cloned at
 * all — so it could not be published as a template and instantiated either.
 * The transaction rolled back cleanly, which makes this a dead feature
 * rather than corrupted data, and dead features are quiet.
 *
 * Nothing caught it because every seeded fixture uses *system* scales, and
 * cloneScaleIfCustom() returns the shared scale id early for those without
 * ever reaching the mass-assignment below. W78's own reference-copying work
 * ran entirely over those fixtures and stayed green over this defect for
 * exactly that reason. Closing that coverage gap is the point of this file,
 * not a side effect of it: the assessments here are deliberately calibrated.
 *
 * This is the third and fourth time this same trap has fired in this one
 * class — see tests/Feature/Catalog/AssessmentClonerTest.php's header for
 * the first two (cloneTags() in W6, cloneSubtree()'s RiskAttack in W6).
 *
 * ## Note on file placement
 *
 * W77 pinned this defect at tests/Feature/Structure/ClonedCustomScaleTest.php
 * on its own branch. That path is inside W78's ownership but the file is not
 * in W78's lineage (W78 branched from W76), so this deliberately takes a
 * different name to keep the integration merge clean. The two want
 * reconciling into one file at integration.
 */

/**
 * An assessment with all three scales calibrated — assessment-owned copies
 * rather than the shared system scales.
 *
 * @return array{0: Assessment, 1: array<string, Scale>}
 */
function w78CalibratedAssessment(): array
{
    $assessment = Assessment::factory()->create();

    $bands = [
        'impact' => [
            ['trivial', 'Trivial', 0, 5_000, 1_000],
            ['serious', 'Serious', 5_000, 250_000, 40_000],
            ['ruinous', 'Ruinous', 250_000, null, 900_000],
        ],
        'likelihood' => [
            ['never', 'Practically never', 0, 0.1, 0.02],
            ['occasional', 'Occasional', 0.1, 3, 0.8],
            ['constant', 'Constant', 3, null, 12],
        ],
        'strength' => [
            ['token', 'Token', 0, 0.3, 0.15],
            ['solid', 'Solid', 0.3, 0.9, 0.6],
            ['total', 'Total', 0.9, 1, 0.95],
        ],
    ];

    $units = ['impact' => 'currency', 'likelihood' => 'rate_per_year', 'strength' => 'fraction'];
    $scales = [];

    foreach ($bands as $kind => $levels) {
        // The factory force-fills, so it can set the identity columns the
        // service under test must not mass-assign.
        $scale = Scale::factory()->create([
            'assessment_id' => $assessment->id,
            'kind' => $kind,
            'name' => "Calibrated {$kind}",
            'unit' => $units[$kind],
            'is_system' => false,
        ]);

        foreach ($levels as $index => [$code, $label, $lower, $upper, $representative]) {
            ScaleLevel::factory()->create([
                'scale_id' => $scale->id,
                'ordinal' => $index + 1,
                'code' => $code,
                'label' => $label,
                'lower_bound' => $lower,
                'upper_bound' => $upper,
                'representative' => $representative,
            ]);
        }

        $scales[$kind] = $scale;
    }

    $assessment->impact_scale_id = $scales['impact']->id;
    $assessment->likelihood_scale_id = $scales['likelihood']->id;
    $assessment->strength_scale_id = $scales['strength']->id;
    $assessment->save();

    return [$assessment->fresh(), $scales];
}

test('FIXED (W78): an assessment with calibrated scales can be cloned at all', function () {
    [$source] = w78CalibratedAssessment();

    // The whole defect in one assertion: this threw QueryException 1364
    // before the fix, so "new from template" was dead for any assessment
    // anyone had actually calibrated.
    $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

    expect($clone->exists)->toBeTrue()
        ->and($clone->id)->not->toBe($source->id);
});

test('FIXED (W78): a cloned custom scale is owned by the clone, not shared with the source', function () {
    [$source, $scales] = w78CalibratedAssessment();

    $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

    foreach (['impact', 'likelihood', 'strength'] as $kind) {
        $column = "{$kind}_scale_id";
        $clonedScale = Scale::findOrFail($clone->{$column});

        // A fresh scale, not the source's: the point of cloning a custom
        // calibration is that editing the copy cannot reach the original.
        expect($clonedScale->id)->not->toBe($scales[$kind]->id, "{$kind}: clone reused the source's scale row");

        // The identity column the defect dropped. Asserting the *value*,
        // not merely that it is non-null — a clone pointing at the source
        // assessment would be non-null and wrong.
        expect($clonedScale->assessment_id)->toBe($clone->id, "{$kind}: cloned scale is not owned by the clone");
        expect($clonedScale->is_system)->toBeFalse("{$kind}: cloned scale claims to be a system scale");
    }
});

test('FIXED (W78): every level of a cloned scale survives with its bands intact', function () {
    [$source, $scales] = w78CalibratedAssessment();

    $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

    $bandsOf = fn (int $scaleId): array => ScaleLevel::query()
        ->where('scale_id', $scaleId)
        ->orderBy('ordinal')
        ->get()
        ->map(fn (ScaleLevel $l): array => [
            'ordinal' => $l->ordinal,
            'code' => $l->code,
            'label' => $l->label,
            'lower_bound' => $l->lower_bound,
            'upper_bound' => $l->upper_bound,
            'representative' => $l->representative,
        ])
        ->all();

    foreach (['impact', 'likelihood', 'strength'] as $kind) {
        $clonedScaleId = (int) $clone->{"{$kind}_scale_id"};

        $expected = $bandsOf($scales[$kind]->id);

        // Three bands per kind, so this cannot pass on an empty set — which
        // is what a level insert that silently failed would leave behind.
        expect($expected)->toHaveCount(3);
        expect($bandsOf($clonedScaleId))->toBe($expected, "{$kind}: cloned levels differ from the source's");

        // And they belong to the cloned scale, not the source's — the exact
        // column Eloquent dropped.
        expect(ScaleLevel::query()->where('scale_id', $clonedScaleId)->count())->toBe(3, "{$kind}: cloned level count");
    }
});

test('FIXED (W78): a system scale is shared by reference, never copied', function () {
    // The early-return half of the method, asserted so that a future
    // "fix" cannot start deep-copying the shared system scales — which
    // would give every assessment its own drifting copy of the defaults.
    $source = Assessment::factory()->create();
    $before = Scale::query()->count();

    $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

    expect($clone->impact_scale_id)->toBe($source->impact_scale_id)
        ->and($clone->likelihood_scale_id)->toBe($source->likelihood_scale_id)
        ->and($clone->strength_scale_id)->toBe($source->strength_scale_id);

    expect(Scale::query()->count())->toBe($before, 'cloning copied a shared system scale');
});

test('FIXED (W78): a cloned custom scale belongs to no system scale set', function () {
    // `scales.set_key` is W77's grouping column for system scale sets. It
    // does not exist in W78's lineage — W78 branched from W76 and W77's
    // migration is on a sibling branch, merged at integration — so this
    // assertion is guarded rather than faked, and activates the moment the
    // column lands. It is asserted at all because the consequence is
    // severe: a clone inheriting the source's set_key would look like a
    // member of a system set, and ScaleSets::resolve() would then find two
    // scales of the same kind in that set and throw on *every* assessment
    // creation using it.
    //
    // The fix needs no line to satisfy this. cloneScaleIfCustom() builds
    // the clone field by field rather than replicate()ing it, so any column
    // it does not name — set_key included — is left at its column default.
    if (! Schema::hasColumn('scales', 'set_key')) {
        expect(true)->toBeTrue();

        test()->markTestSkipped('scales.set_key arrives with W77; this assertion activates at integration.');
    }

    [$source] = w78CalibratedAssessment();

    $clone = app(AssessmentCloner::class)->cloneAssessment($source, User::factory()->create());

    foreach (['impact', 'likelihood', 'strength'] as $kind) {
        $clonedScale = Scale::findOrFail($clone->{"{$kind}_scale_id"});

        expect($clonedScale->getAttribute('set_key'))->toBeNull("{$kind}: cloned scale carried a system set_key");
    }
});
