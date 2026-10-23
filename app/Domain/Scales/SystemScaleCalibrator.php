<?php

namespace App\Domain\Scales;

use App\Domain\Structure\ScaleValidationException;
use App\Domain\Structure\ScaleValidator;
use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Observers\Concerns\TriggersRecalculation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies a calibration to a **shared system scale** — the admin Defaults tab
 * (W87, PLAN-2.5.md D47).
 *
 * ## Why this is not `ScaleCalibrator`
 *
 * `ScaleCalibrator` exists to *avoid* editing a system scale: its whole design
 * is clone-on-edit, because one assessment changing a shared scale would move
 * every figure in every other assessment using it. That is the right rule for
 * an assessment's editor and the wrong one for this screen, where editing the
 * shared row **is the request**. Reusing it would have meant a flag that turns
 * off the safety property the class is named for, which is worse than two
 * classes whose difference is stated.
 *
 * ## The blast radius is real and is the point
 *
 * D47: "Defaults are about the *content* a new assessment starts with." They
 * are also what every assessment that has never calibrated is still pointing
 * at — `is_system` is exactly the `uncalibrated_scales` flag — so an edit here
 * moves those assessments' figures too. That is not a side effect this class
 * hides: `ScaleLevelObserver` bumps `calc_version` for every one of them, which
 * is its existing deliberate behaviour, and the admin screen says so in words
 * above the editor.
 *
 * An assessment that has calibrated has its own copy and is untouched. That is
 * the guarantee `ScaleCalibrator::cloneForAssessment()` bought, and it is what
 * makes this screen safe enough to have at all.
 *
 * ## Removing a band clears values across every assessment
 *
 * The foreign keys are `restrictOnDelete`, so a band cannot be deleted while
 * anything points at it. On an assessment's private scale those references
 * belong to one assessment; here they may belong to many, and they are cleared
 * **unscoped** for that reason. `ScaleCalibrator::scopedReferences()` exists to
 * prevent exactly this — on an assessment's save it would be a catastrophe —
 * which is why the unscoped clear lives in a different class with this
 * paragraph attached to it rather than behind a boolean argument.
 */
final class SystemScaleCalibrator
{
    public function __construct(
        private readonly ScaleValidator $validator,
        private readonly ScaleCalibrator $assessments,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $bands  in display order; ordinals come from position
     *
     * @throws ScaleValidationException the bands break one of schema.md §3.2's five invariants
     */
    public function calibrate(Scale $scale, array $bands): CalibrationOutcome
    {
        if (! $scale->is_system || $scale->assessment_id !== null) {
            // A guard rather than a silent widening. Handed an assessment's
            // own scale this class would edit it without cloning, which is
            // correct here and wrong there; refusing is the only answer that
            // cannot be wrong by accident.
            throw new InvalidArgumentException('SystemScaleCalibrator only edits shared system scales; use ScaleCalibrator for an assessment.');
        }

        $bands = LevelCodeDeriver::assign($scale, $bands);

        // Validated before anything is written, against unsaved models, so a
        // rejected calibration touches nothing — and on a shared scale a
        // partially-applied edit would be partially applied to every
        // assessment following it.
        $this->validator->assertValid($this->draft($bands), $scale->unit);

        return DB::transaction(fn (): CalibrationOutcome => TriggersRecalculation::coalesce(
            function () use ($scale, $bands): CalibrationOutcome {
                $this->detachFollowers($scale);

                return $this->apply($scale, $bands);
            },
        ));
    }

    /**
     * Gives every assessment still pointing at this shared scale its own copy
     * of it, as it is now (W126: deleting a scale needs this on its own, with
     * no edit following).
     *
     * @return int how many assessments were moved onto their own copy
     */
    public function release(Scale $scale): int
    {
        return DB::transaction(fn (): int => TriggersRecalculation::coalesce(
            fn (): int => $this->detachFollowers($scale),
        ));
    }

    /**
     * Gives every assessment still pointing at this shared scale its own copy
     * of it, *as it is now*, before the edit (2.5.1).
     *
     * Defaults are defaults: an admin changing the default impact bands is
     * deciding what the *next* assessment starts with, not rewriting the
     * figures of every assessment that happened never to have calibrated its
     * own. Through 2.5 this screen edited the shared rows those assessments
     * were still reading, and said so in a warning box; now they are moved
     * onto copies first, so nothing they show changes.
     *
     * The copy is made by `ScaleCalibrator::calibrate()` with the scale's own
     * current bands — the same clone-and-repoint path an assessment's first
     * calibration takes, codes preserved, so every stored value lands on the
     * equivalent band of the copy and nothing is cleared. Soft-deleted
     * assessments included: one restored later must come back with the
     * figures it had.
     *
     * @return int how many assessments were moved onto their own copy
     */
    private function detachFollowers(Scale $scale): int
    {
        $column = ScaleCalibrator::scaleColumn($scale->kind);

        $current = ScaleDescriber::describe($scale->loadMissing('levels'));
        $bands = array_map(fn (array $band): array => [
            'code' => $band['code'],
            'label' => $band['label'],
            'lower_bound' => $band['lower_bound'],
            'upper_bound' => $band['upper_bound'],
            'representative' => $band['representative'],
        ], $current['bands'] ?? []);

        $moved = 0;

        Assessment::withTrashed()
            ->where($column, $scale->id)
            ->each(function (Assessment $assessment) use ($scale, $bands, &$moved): void {
                $this->assessments->calibrate($assessment, $scale->kind, $bands);
                $moved++;
            });

        return $moved;
    }

    /**
     * Unsaved models for validation only; never saved. `scale_id` is
     * deliberately absent from `$fillable`, so one of these reaching `save()`
     * would die on the NOT NULL constraint rather than write an orphan.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<ScaleLevel>
     */
    private function draft(array $bands): array
    {
        $levels = [];

        foreach ($bands as $index => $band) {
            $levels[] = new ScaleLevel([
                'ordinal' => $index + 1,
                'code' => $band['code'],
                'label' => $band['label'],
                'lower_bound' => $band['lower_bound'],
                'upper_bound' => $band['upper_bound'],
                'representative' => $band['representative'],
            ]);
        }

        return $levels;
    }

    /**
     * @param  list<array<string, mixed>>  $bands
     */
    private function apply(Scale $scale, array $bands): CalibrationOutcome
    {
        /** @var array<string, ScaleLevel> $existing */
        $existing = $scale->levels()->get()->keyBy('code')->all();

        // Ordinals are pushed clear of 1..n before any of them is rewritten.
        // Renumbering in place collides on `scale_levels_ordinal_unique` the
        // moment a band is removed or reordered — see LevelOrdinals.
        LevelOrdinals::park($scale, $existing);

        $kept = [];

        foreach ($bands as $index => $band) {
            $code = (string) $band['code'];
            $level = $existing[$code] ?? $this->makeLevel($scale, $code);

            $level->fill([
                'ordinal' => $index + 1,
                'label' => $band['label'],
                'lower_bound' => $band['lower_bound'],
                'upper_bound' => $band['upper_bound'],
                'representative' => $band['representative'],
            ]);
            $level->save();

            $kept[$code] = $level;
        }

        return new CalibrationOutcome(
            scale: $scale->fresh() ?? $scale,
            // Never. A system scale is edited in place here by definition; the
            // field is on the outcome because `ScaleCalibrator` shares the DTO.
            cloned: false,
            clearedValues: $this->dropRemovedBands($scale->kind, $existing, $kept),
        );
    }

    private function makeLevel(Scale $scale, string $code): ScaleLevel
    {
        $level = new ScaleLevel(['code' => $code]);
        // Identity column: `scale_id` is deliberately absent from
        // `ScaleLevel::$fillable`, and a `create()` carrying it is dropped in
        // silence by Eloquent before dying on NOT NULL (CLAUDE.md — this has
        // broken three packages).
        $level->scale_id = $scale->id;

        return $level;
    }

    /**
     * @param  array<string, ScaleLevel>  $existing
     * @param  array<string, ScaleLevel>  $kept
     * @return int how many stored values were emptied, across every assessment
     */
    private function dropRemovedBands(string $kind, array $existing, array $kept): int
    {
        $removed = array_diff_key($existing, $kept);

        if ($removed === []) {
            return 0;
        }

        $ids = array_map(fn (ScaleLevel $level): int => $level->id, array_values($removed));

        ['table' => $table, 'column' => $column] = ScaleCalibrator::referenceColumn($kind);

        // Unscoped, and this is the one place in the codebase where that is
        // correct: the scale is shared, so every reference to a removed band
        // belongs to an assessment following this default. Cleared before the
        // delete because the foreign keys are `restrictOnDelete`.
        $cleared = DB::table($table)->whereIn($column, $ids)->update([$column => null]);

        ScaleLevel::query()->whereIn('id', $ids)->delete();

        return $cleared;
    }
}
