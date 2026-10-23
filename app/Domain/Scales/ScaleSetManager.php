<?php

namespace App\Domain\Scales;

use App\Domain\Structure\ScaleValidationException;
use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\ScaleSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The admin's side of scales (W126, D92-D94): create, rename, save, delete.
 *
 * Built-in scales (`is_builtin`) can be edited and never renamed or deleted:
 * `AssessmentCreator` falls back to `default` and the docs and tests name
 * both.
 */
final class ScaleSetManager
{
    public function __construct(private readonly SystemScaleCalibrator $system) {}

    /**
     * A custom scale copied from an existing one, so it is complete and valid
     * from its first second.
     *
     * @throws InvalidArgumentException the source does not exist as a complete scale
     */
    public function create(string $name, string $fromKey): ScaleSet
    {
        $source = ScaleSets::resolve($fromKey);

        if ($source === null) {
            throw new InvalidArgumentException("No complete scale '{$fromKey}' to copy.");
        }

        return DB::transaction(function () use ($name, $source): ScaleSet {
            $key = $this->uniqueKey($name);

            $set = new ScaleSet(['name' => $name]);
            $set->key = $key;
            $set->is_builtin = false;
            $set->position = 0;
            $set->save();

            foreach (ScaleSets::KINDS as $kind) {
                $from = Scale::query()->with('levels')->findOrFail($source[$kind]);

                $scale = new Scale([
                    'kind' => $kind,
                    'name' => ucfirst($kind)." ({$name})",
                    'unit' => $from->unit,
                    'is_system' => true,
                ]);
                // Identity columns, assigned as properties (CLAUDE.md).
                $scale->set_key = $key;
                $scale->save();

                foreach ($from->levels as $level) {
                    $copy = new ScaleLevel([
                        'ordinal' => $level->ordinal,
                        'code' => $level->code,
                        'label' => $level->label,
                        'lower_bound' => $level->lower_bound,
                        'upper_bound' => $level->upper_bound,
                        'representative' => $level->representative,
                    ]);
                    $copy->scale_id = $scale->id;
                    $copy->save();
                }
            }

            return $set;
        });
    }

    public function rename(ScaleSet $set, string $name): void
    {
        if ($set->is_builtin) {
            throw new InvalidArgumentException('A built-in scale cannot be renamed.');
        }

        DB::transaction(function () use ($set, $name): void {
            $set->name = $name;
            $set->save();

            Scale::query()->whereNull('assessment_id')->where('is_system', true)->where('set_key', $set->key)
                ->get()->each(function (Scale $scale) use ($name): void {
                    $scale->name = ucfirst($scale->kind)." ({$name})";
                    $scale->save();
                });
        });
    }

    /**
     * Saves bands for one to three kinds of a scale, all or nothing.
     *
     * @param  array<string, list<array<string, mixed>>>  $bandsByKind
     *
     * @throws ScaleValidationException
     */
    public function save(ScaleSet $set, array $bandsByKind): void
    {
        DB::transaction(function () use ($set, $bandsByKind): void {
            foreach (ScaleCalibrator::kinds() as $kind) {
                if (! isset($bandsByKind[$kind])) {
                    continue;
                }

                $scale = Scale::query()->whereNull('assessment_id')->where('is_system', true)
                    ->where('set_key', $set->key)->where('kind', $kind)->first();

                if ($scale === null) {
                    throw new ScaleValidationException("This scale has no {$kind} bands to edit.");
                }

                $this->system->calibrate($scale, $bandsByKind[$kind]);
            }
        });
    }

    /**
     * Deletes a custom scale. Assessments still on its shared rows are given
     * private, identical copies first (the FKs are RESTRICT, so the order is
     * required), and their label becomes Custom.
     *
     * @return int how many assessments kept their own copy
     */
    public function delete(ScaleSet $set): int
    {
        if ($set->is_builtin) {
            throw new InvalidArgumentException('A built-in scale cannot be deleted.');
        }

        return DB::transaction(function () use ($set): int {
            $scales = Scale::query()->whereNull('assessment_id')->where('is_system', true)->where('set_key', $set->key)->get();
            $ids = $scales->pluck('id');

            // One query for both the count and the relabel, so they cannot
            // drift: everyone still on these rows, plus everyone labelled with
            // the key. Counted as assessments, not as scales released.
            $affected = Assessment::withTrashed()->where(function ($q) use ($ids, $set): void {
                $q->where('scale_set_key', $set->key)
                    ->orWhereIn('impact_scale_id', $ids)
                    ->orWhereIn('likelihood_scale_id', $ids)
                    ->orWhereIn('strength_scale_id', $ids);
            });
            $affectedIds = (clone $affected)->pluck('id');
            $sharing = (clone $affected)->where(function ($q) use ($ids): void {
                $q->whereIn('impact_scale_id', $ids)->orWhereIn('likelihood_scale_id', $ids)->orWhereIn('strength_scale_id', $ids);
            })->count();

            foreach ($scales as $scale) {
                $this->system->release($scale);
            }

            // By id, taken before the release: afterwards the copies no longer
            // match the query that found them.
            Assessment::withTrashed()->whereIn('id', $affectedIds)->update(['scale_set_key' => null]);
            $kept = $sharing;

            foreach ($scales as $scale) {
                ScaleLevel::query()->where('scale_id', $scale->id)->delete();
                $scale->delete();
            }

            $set->delete();

            return $kept;
        });
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::slug($name);
        $base = $base === '' ? 'scale' : Str::limit($base, 50, '');
        $key = $base;
        $n = 2;

        // 'custom' is the dropdown's own value for "no scale" (AssessmentScales.vue),
        // so no scale may take it as a key.
        while ($key === 'custom' || ScaleSet::query()->where('key', $key)->exists() || Scale::query()->where('set_key', $key)->exists()) {
            $key = "{$base}-{$n}";
            $n++;
        }

        return $key;
    }
}
