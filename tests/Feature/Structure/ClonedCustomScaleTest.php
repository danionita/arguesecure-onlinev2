<?php

/**
 * Cloning an assessment whose scales have been calibrated.
 *
 * FIXED (W78) — was: it did not work at all. `AssessmentCloner::cloneScaleIfCustom()`
 * mass-assigns two identity columns —
 *
 *     Scale::query()->create(['assessment_id' => $target->id, ...])
 *     ScaleLevel::query()->create(['scale_id' => $clone->id, ...])
 *
 * — and both are deliberately absent from their models' `$fillable`. Eloquent
 * drops each value in silence. `scales.assessment_id` is nullable, so the
 * scale clone is written as a *system-shaped orphan* (`assessment_id` NULL);
 * `scale_levels.scale_id` is NOT NULL, so the very next insert dies:
 *
 *     SQLSTATE[HY000]: General error: 1364 Field 'scale_id' doesn't have a
 *     default value
 *
 * The transaction rolls the whole clone back, so nothing is corrupted — but
 * an assessment that has been calibrated cannot be cloned, which also means
 * it cannot be published as a template and instantiated. This is the third
 * time this exact trap has hit this exact file: CLAUDE.md records
 * AssessmentCloner breaking on it twice in W6, over `Tag` and `RiskAttack`,
 * and its warning ("this has broken three separate packages ... in two of
 * them it made the feature completely non-functional while the whole test
 * suite stayed green") describes what you are reading. The scales path was
 * never covered, so it stayed green here too.
 *
 * ## FIXED (W78) — this pin has been flipped
 *
 * W77 pinned the defect because `app/Domain/Structure/AssessmentCloner.php`
 * was outside its ownership. W78 owned that file and fixed it: both columns
 * are now assigned as properties and saved, and `set_key` is left at its
 * column default rather than copied. The two `DEFECT:` tests below are
 * rewritten in place as `FIXED (W78)` regression guards rather than deleted,
 * so the reasoning survives and the fix cannot be quietly reverted.
 *
 * The fixed behaviour is covered in depth by
 * `tests/Feature/Structure/CloneCalibratedScalesTest.php` (W78), including
 * the per-level band check and the `set_key` NULL assertion that W78 could
 * only guard behind `Schema::hasColumn()` because W77's column lived on a
 * sibling branch. On this branch both have landed and that assertion runs.
 * What is kept here is the pair of failure modes themselves, because they
 * failed *differently* and only one of them was loud — see below.
 *
 * ## The fix, and its trade-off
 *
 * Assign both columns as properties and `save()`, the way
 * `ScaleCalibrator::cloneForAssessment()` and `SystemScaleSeeder::seedScale()`
 * already do, and the way `LinkService`'s docblock documents:
 *
 *     $clone = new Scale([...]);        // no assessment_id in the array
 *     $clone->assessment_id = $target->id;
 *     $clone->save();
 *
 * The trade-off is the W77 column. A cloned scale is assessment-owned, so its
 * `set_key` must be **NULL** — it belongs to that assessment, not to a shared
 * system set. Carrying the source's `set_key` across would make the clone
 * look like a member of a system set that `ScaleSets::resolve()` would then
 * find a duplicate kind in, and every assessment creation on that set would
 * start throwing. `replicate()` is not used on this path today; if the fix
 * switches to it, `set_key` joins `AssessmentCloner::NOT_REPLICATED` — as do
 * `assessment_id` and `scale_id`, for the same reason `root_marker` is
 * already there.
 *
 * Fixing it turned the first test below red, which was the point: whoever did
 * it had to read the paragraph above and decide about `set_key` deliberately
 * rather than discovering it when assessment creation started failing on an
 * instance that had cloned a calibrated assessment. W78 decided it by
 * construction — the clone is built field by field rather than `replicate()`d,
 * so a column it does not name is left at its default. That is load-bearing,
 * not incidental: switching this path to `replicate()` later would silently
 * start carrying `set_key` across, which is why it is written down here.
 */

use App\Domain\Scales\ScaleSets;
use App\Domain\Structure\AssessmentCloner;
use App\Domain\Structure\AssessmentCreator;
use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(SystemScaleSeeder::class);
});

/** An assessment on its own calibrated impact scale, as ScaleCalibrator leaves one. */
function assessmentWithCustomScale(User $owner): Assessment
{
    $assessment = app(AssessmentCreator::class)->create($owner, 'A calibrated target');

    $custom = new Scale([
        'kind' => 'impact',
        'name' => 'A calibrated impact scale',
        'unit' => 'currency',
        'is_system' => false,
    ]);
    // Identity column, assigned as a property — the very thing the cloner
    // fails to do.
    $custom->assessment_id = $assessment->id;
    $custom->save();

    foreach ([['negligible', 0, 500, 125], ['minor', 500, null, 1500]] as $index => [$code, $lower, $upper, $rep]) {
        $level = new ScaleLevel([
            'ordinal' => $index + 1,
            'code' => $code,
            'label' => ucfirst($code),
            'lower_bound' => $lower,
            'upper_bound' => $upper,
            'representative' => $rep,
        ]);
        $level->scale_id = $custom->id;
        $level->save();
    }

    $assessment->impact_scale_id = $custom->id;
    $assessment->save();

    return $assessment->fresh();
}

test('FIXED (W78): the loud half — a calibrated assessment clones instead of dying on scale_id', function () {
    $owner = User::factory()->create();
    $assessment = assessmentWithCustomScale($owner);

    // `scale_levels.scale_id` is NOT NULL, so the dropped identity column used
    // to surface as SQLSTATE 1364 and take the whole clone down. Asserting the
    // absence of that specific exception rather than a bare "it returns
    // something": a different QueryException here is a different bug, and this
    // guard should not absorb it.
    $clone = app(AssessmentCloner::class)->cloneAssessment($assessment, $owner);

    $clonedScale = Scale::query()->findOrFail($clone->impact_scale_id);
    expect($clonedScale->id)->not->toBe($assessment->impact_scale_id);
    expect($clonedScale->assessment_id)->toBe($clone->id);
    expect(ScaleLevel::query()->where('scale_id', $clonedScale->id)->count())->toBe(2);
});

test('FIXED (W78): the silent half — the clone owns its scale rather than orphaning it as a system one', function () {
    $owner = User::factory()->create();
    $assessment = assessmentWithCustomScale($owner);
    $before = Assessment::query()->count();

    $clone = app(AssessmentCloner::class)->cloneAssessment($assessment, $owner);

    // This is the half that was NOT loud. `scales.assessment_id` is nullable
    // (NULL means system scale), so the dropped value wrote a *wrong row*
    // rather than failing — a custom calibration silently promoted to a shared
    // system scale. It was masked only because the level insert two lines
    // later killed the transaction first, so fixing just the loud half would
    // have left this behind. Asserting the count of system-shaped orphans is
    // zero, not merely that the clone exists.
    expect(Assessment::query()->count())->toBe($before + 1);
    expect(Scale::query()->whereNull('assessment_id')->where('is_system', false)->count())->toBe(0);
    expect(Scale::query()->findOrFail($clone->impact_scale_id)->getAttribute('set_key'))->toBeNull();
});

test('cloning an assessment on shared system scales still works, and shares rather than copies them', function () {
    // The path that is exercised everywhere else, kept next to the pin so the
    // boundary is visible: a system scale is shared by id, never cloned, so
    // it never reaches the broken branch.
    $owner = User::factory()->create();
    // `fresh()`, because AssessmentCreator lets the database supply `currency`
    // and `display_mode` from their column defaults rather than writing them,
    // so the in-memory model carries nulls that replicate() would copy. Real
    // callers hand this a route-model-bound assessment, which is a fresh read.
    $assessment = app(AssessmentCreator::class)->create($owner, 'A target on system scales')->fresh();

    $clone = app(AssessmentCloner::class)->cloneAssessment($assessment, $owner);

    expect($clone->impact_scale_id)->toBe($assessment->impact_scale_id);
    expect($clone->likelihood_scale_id)->toBe($assessment->likelihood_scale_id);
    expect($clone->strength_scale_id)->toBe($assessment->strength_scale_id);
    expect(Scale::query()->findOrFail($clone->impact_scale_id)->set_key)
        ->toBe(ScaleSets::DEFAULT_KEY);
});
