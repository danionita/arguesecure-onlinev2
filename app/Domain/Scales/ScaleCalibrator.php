<?php

namespace App\Domain\Scales;

use App\Domain\Structure\ScaleValidationException;
use App\Domain\Structure\ScaleValidator;
use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Observers\Concerns\TriggersRecalculation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Applies a calibration to one of an assessment's three scales (W65, ui.md §8,
 * schema.md §3.2).
 *
 * The last specified-but-unbuilt feature. Until now the engine could emit an
 * `uncalibrated_scales` advisory that nobody could act on, and every package
 * that wanted a scale edited had to work around its absence.
 *
 * ## Clone on edit, and this is the whole reason the class exists
 *
 * A system scale is `assessment_id = NULL, is_system = 1` and is **shared by
 * every assessment on the instance**. Editing one in place would silently
 * rewrite every figure in every other assessment calibrated against it —
 * exactly the legacy global-tags defect schema.md §3.11 records, except that
 * this one changes money. So the first edit to a system scale copies it into
 * the assessment, repoints that assessment's foreign key, and edits the copy.
 * Nothing else is touched, and `ScaleLevelObserver`'s deliberately blunt
 * "bump every assessment using this scale" stays correct because after this it
 * has one assessment to bump.
 *
 * ## Existing values are re-pointed, never orphaned
 *
 * `nodes.impact_level_id`, `nodes.likelihood_level_id` and
 * `attack_defence.strength_level_id` hold `scale_levels.id`. Cloning a scale
 * makes every one of those ids point into a scale the assessment no longer
 * uses — and `AssessmentGraphLoader` only loads levels for the scales the
 * assessment *does* use, so those values would silently read as missing and
 * drop out of the total. Understating risk, invisibly. So bands are matched
 * **by code** across the edit, and every reference is moved to the new row for
 * the same code.
 *
 * A band the user *removes* has no new row to move to. Its references are
 * cleared and **counted** (`clearedValues`), leaving the node in the legal
 * "qualitative, nothing chosen yet" state the schema already permits
 * (`chk_nodes_impact_mode`) and the engine already treats as missing. The
 * count is reported to the person who pressed save, because they have just
 * emptied a value someone else may have spent a workshop arriving at.
 *
 * The foreign keys are `restrictOnDelete`, so clearing first is not a courtesy
 * — a delete that skipped it would fail on the constraint. That is the schema
 * refusing to let this go wrong quietly, and it is doing its job.
 *
 * ## One version bump, not one per band
 *
 * Every `ScaleLevel` write fires `ScaleLevelObserver`, which bumps
 * `calc_version` for every assessment using that scale. A five-band edit is
 * five saves. `TriggersRecalculation::coalesce()` (W64) collapses them into one
 * bump per assessment — safe because `calc_version` is a cache key rather than
 * a counter anyone reads the value of.
 */
final class ScaleCalibrator
{
    /**
     * The three kinds, and the column on `assessments` each one is held by.
     *
     * A fixed map, looked up by an exact key match, rather than
     * `"{$kind}_scale_id"`. Building a column name from request input is a
     * legacy defect this codebase forbids by name (CLAUDE.md, security.md
     * defect #7), and the fact that the interpolation would *happen* to be
     * safe here for the three valid values is not the point — it stops being
     * safe the moment the caller stops validating, which is a change nobody
     * would connect to this line.
     */
    private const SCALE_COLUMNS = [
        'impact' => 'impact_scale_id',
        'likelihood' => 'likelihood_scale_id',
        'strength' => 'strength_scale_id',
    ];

    /**
     * Which reference column each kind's levels are pointed at from, and on
     * which table.
     *
     * @var array<string, array{table: string, column: string}>
     */
    private const REFERENCE_COLUMNS = [
        'impact' => ['table' => 'nodes', 'column' => 'impact_level_id'],
        'likelihood' => ['table' => 'nodes', 'column' => 'likelihood_level_id'],
        'strength' => ['table' => 'attack_defence', 'column' => 'strength_level_id'],
    ];

    public function __construct(private readonly ScaleValidator $validator) {}

    /**
     * The `assessments` column holding a kind's scale id — for
     * `SystemScaleCalibrator`, which has to find every assessment still
     * following a default before it edits one (2.5.1). Exposed rather than
     * copied, for the same reason as referenceColumn().
     */
    public static function scaleColumn(string $kind): string
    {
        return self::SCALE_COLUMNS[$kind] ?? throw new \InvalidArgumentException("Unknown scale kind '{$kind}'.");
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::SCALE_COLUMNS);
    }

    /**
     * Where a kind's levels are referenced from, for callers outside this
     * class that have to clear the same references — `SystemScaleCalibrator`,
     * which edits a shared scale and so cannot scope the clear to one
     * assessment.
     *
     * Exposed rather than copied. Two maps of which column holds which kind's
     * level id is exactly the pair that would disagree after a schema change,
     * and the disagreement would show up as values silently dropping out of a
     * total rather than as an error.
     *
     * @return array{table: string, column: string}
     */
    public static function referenceColumn(string $kind): array
    {
        return self::REFERENCE_COLUMNS[$kind] ?? throw new \InvalidArgumentException("Unknown scale kind '{$kind}'.");
    }

    /**
     * @param  list<array{code: ?string, label: string, lower_bound: float, upper_bound: ?float, representative: float}>  $bands
     *                                                                                                                            in display order; ordinals are assigned from position, never taken from input, and a
     *                                                                                                                            null or unrecognised `code` is a band the editor has just created (W87, D44)
     *
     * @throws ScaleValidationException the bands break one of schema.md §3.2's five invariants
     */
    public function calibrate(Assessment $assessment, string $kind, array $bands): CalibrationOutcome
    {
        $column = self::SCALE_COLUMNS[$kind] ?? throw new \InvalidArgumentException("Unknown scale kind '{$kind}'.");

        $current = Scale::query()->findOrFail($assessment->getAttribute($column));

        $bands = LevelCodeDeriver::assign($current, $bands);

        // Validated **before** anything is written, and against unsaved models
        // so a rejected calibration touches nothing at all. A partially-applied
        // scale would leave bands with a gap in them, which is precisely the
        // state every invariant here exists to make unreachable.
        $this->validator->assertValid($this->draft($bands), $current->unit);

        return DB::transaction(fn (): CalibrationOutcome => TriggersRecalculation::coalesce(
            fn (): CalibrationOutcome => $this->apply($assessment, $kind, $column, $current, $bands),
        ));
    }

    /**
     * Unsaved `ScaleLevel` models for validation only.
     *
     * `ScaleValidator` reads models rather than arrays, so this is what lets a
     * calibration be checked before a single row is written. They are never
     * saved — `scale_id` is deliberately absent from `$fillable` and is not set
     * here, which also means one of these accidentally reaching `save()` would
     * die on the NOT NULL constraint rather than writing an orphan.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<ScaleLevel>
     */
    private function draft(array $bands): array
    {
        $levels = [];

        foreach ($bands as $index => $band) {
            $levels[] = new ScaleLevel([
                // From position, never from input. Ordinals must be contiguous
                // 1..n (invariant 1); taking them from the client would let a
                // malformed payload fail validation for a reason the user did
                // not cause and cannot see.
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
    private function apply(Assessment $assessment, string $kind, string $column, Scale $current, array $bands): CalibrationOutcome
    {
        $cloned = false;
        $scale = $current;

        if ($current->is_system) {
            $scale = $this->cloneForAssessment($assessment, $current);
            $assessment->setAttribute($column, $scale->id);
            $assessment->save();
            $cloned = true;
        }

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

        $cleared = $this->dropRemovedBands($kind, $existing, $kept);

        // Only after a clone, and only for the codes that survived it. An
        // in-place edit keeps every row id, so nothing is pointing anywhere new.
        //
        // The `+=` matters and was a defect until a test caught it. On a clone
        // the new scale starts with no levels at all, so `$existing` above is
        // empty and dropRemovedBands() finds nothing to remove — every band the
        // user dropped is invisible to it. Those values are cleared by repoint()
        // instead, on the "no new row for this code" path, and if that count
        // were discarded the message would say nothing was emptied on exactly
        // the save most likely to empty something: the first one.
        if ($cloned) {
            $cleared += $this->repoint($assessment, $kind, $current, $kept);
        }

        return new CalibrationOutcome(scale: $scale->fresh(), cloned: $cloned, clearedValues: $cleared);
    }

    /**
     * The assessment's own copy of a shared system scale.
     *
     * `assessment_id` is assigned as a **property**: it is an identity column,
     * deliberately absent from `Scale::$fillable` for exactly this reason, and
     * a `create()` carrying one is dropped silently by Eloquent before dying on
     * the NOT NULL constraint (CLAUDE.md — this has broken three packages).
     *
     * `is_system` becomes false. A copy that stayed marked as a system scale
     * would be offered to other assessments as a shared default and would keep
     * the `uncalibrated_scales` advisory showing on an assessment that has just
     * been calibrated.
     */
    private function cloneForAssessment(Assessment $assessment, Scale $system): Scale
    {
        $clone = new Scale([
            'kind' => $system->kind,
            'name' => $system->name,
            'unit' => $system->unit,
            'is_system' => false,
        ]);
        $clone->assessment_id = $assessment->id;
        $clone->save();

        return $clone;
    }

    private function makeLevel(Scale $scale, string $code): ScaleLevel
    {
        $level = new ScaleLevel(['code' => $code]);
        // Identity column, as above.
        $level->scale_id = $scale->id;

        return $level;
    }

    /**
     * Removes the bands this calibration dropped, clearing what pointed at them
     * first.
     *
     * @param  array<string, ScaleLevel>  $existing
     * @param  array<string, ScaleLevel>  $kept
     * @return int how many stored values were emptied
     */
    private function dropRemovedBands(string $kind, array $existing, array $kept): int
    {
        $removed = array_diff_key($existing, $kept);

        if ($removed === []) {
            return 0;
        }

        $ids = array_map(fn (ScaleLevel $level): int => $level->id, array_values($removed));

        ['table' => $table, 'column' => $column] = self::REFERENCE_COLUMNS[$kind];

        // Cleared before the delete, not after: the foreign keys are
        // `restrictOnDelete`, so the delete below fails outright while a
        // reference survives. The mode column is deliberately left alone — a
        // qualitative value with no level chosen is a state the schema permits
        // and the engine treats as missing, which is what this now is.
        $cleared = DB::table($table)->whereIn($column, $ids)->update([$column => null]);

        ScaleLevel::query()->whereIn('id', $ids)->delete();

        return $cleared;
    }

    /**
     * Moves every stored value from the old scale's levels to the new scale's,
     * matched by code.
     *
     * Only reachable after a clone. Without it every qualitative value on the
     * assessment would keep pointing into the system scale, which
     * `AssessmentGraphLoader` no longer loads for this assessment — so each one
     * would read as missing and silently leave the total, understating risk.
     *
     * Matched by code because a code is a band's stable name (schema.md §3.2:
     * catalogue defaults reference levels by code for the same reason). Ids are
     * per-row and mean nothing across a copy; labels are display text a user
     * may have just reworded.
     *
     * @param  array<string, ScaleLevel>  $kept  the new scale's levels, keyed by code
     * @return int how many stored values were emptied because their band is gone
     */
    private function repoint(Assessment $assessment, string $kind, Scale $old, array $kept): int
    {
        ['table' => $table, 'column' => $column] = self::REFERENCE_COLUMNS[$kind];

        $cleared = 0;

        foreach ($old->levels()->get() as $oldLevel) {
            $new = $kept[$oldLevel->code] ?? null;

            if ($new === null) {
                // The user deleted this band in the same save that triggered
                // the clone. dropRemovedBands() cannot see it — it only ever
                // looked at the *new* scale's levels, which never had this row
                // — so the values pointing here are cleared on this path
                // instead, into the same legal "nothing chosen" state, and
                // counted here so the message can still say so.
                $cleared += $this->scopedReferences($assessment, $table, $column, [$oldLevel->id])
                    ->update([$column => null]);

                continue;
            }

            $this->scopedReferences($assessment, $table, $column, [$oldLevel->id])->update([$column => $new->id]);
        }

        return $cleared;
    }

    /**
     * References to these levels **from this assessment only**.
     *
     * The scoping is the whole point, and leaving it out is the mistake this
     * method exists to prevent. A system scale is shared, so an unscoped
     * `UPDATE ... WHERE impact_level_id IN (…)` would drag every other
     * assessment's values onto this assessment's private copy — the same
     * blast radius that clone-on-edit exists to avoid, reintroduced one line
     * below the code that avoids it.
     *
     * @param  list<int>  $levelIds
     */
    private function scopedReferences(Assessment $assessment, string $table, string $column, array $levelIds): Builder
    {
        $query = DB::table($table)->whereIn($column, $levelIds);

        if ($table === 'nodes') {
            return $query->where('assessment_id', $assessment->id);
        }

        // `attack_defence` carries no assessment_id — it is reached through its
        // attack, the same way AttackDefenceObserver finds one.
        return $query->whereIn('attack_node_id', DB::table('nodes')
            ->where('assessment_id', $assessment->id)
            ->select('id'));
    }
}
