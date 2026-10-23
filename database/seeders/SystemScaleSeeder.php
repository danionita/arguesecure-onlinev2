<?php

namespace Database\Seeders;

use App\Domain\Scales\ScaleSets;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\ScaleSet;
use Illuminate\Database\Seeder;

/**
 * Seeds the shared system scale sets (schema.md §3.2: impact, likelihood,
 * strength; `is_system = 1`, `assessment_id = NULL`).
 *
 * schema.md §3.2 says these belong in "a seeder, not a migration", but no
 * prior work package actually wrote one — every existing test builds its
 * own throwaway copy via `Scale::factory()->systemImpact()` etc.
 * (database/factories/ScaleFactory.php), so a real `db:seed` run has never
 * produced a persistent, is_system=1 row. W22 needs real system scales to
 * exist (both to calibrate the assessments TemplateAssessmentSeeder
 * builds, and to resolve the level codes CatalogLibrarySeeder's entries
 * store), so this fills that gap. It is plain Eloquent row creation from
 * the same bands ScaleFactory already encodes — no new business rule, and
 * nothing that belongs in app/Domain.
 *
 * ## Two sets, from W77
 *
 * There is now a second set, `small-organisation`, so that
 * `instance_settings.default_scale_calibration` is a choice rather than a
 * text box with one legal answer (PLAN-2.4.md D36). It is a **different
 * calibration, not a copy**: same qualitative vocabulary, different numbers
 * behind it.
 *
 * Keeping the level *codes* identical across sets is deliberate. A
 * calibration is a statement about what "moderate" is worth, not about which
 * words exist; and the catalogue fixtures and
 * Database\Seeders\Support\AssessmentFixtureValidator reference levels by
 * code, so a set that renamed its bands would silently stop resolving them.
 *
 * ## Idempotency
 *
 * A scale is looked up by (`kind`, `is_system`, `assessment_id`, `set_key`)
 * — running `db:seed` twice never duplicates a scale or its levels. Before
 * W77 the lookup omitted `set_key`, which is exactly what a second set
 * shares with the first, so this seeder could not have written one at all:
 * `firstOrCreate` would have matched the default set's row and returned it
 * under the second set's name.
 *
 * The lookup is a `first()`-then-`save()` rather than a `firstOrCreate()`
 * because `set_key` is an identity column deliberately absent from
 * `Scale::$fillable`. A `firstOrCreate()` carrying it would match correctly
 * and then, on the create path, have Eloquent drop the value silently and
 * write a system trio belonging to no set — the failure mode CLAUDE.md
 * describes, and one this very file caused once already in W22.
 */
class SystemScaleSeeder extends Seeder
{
    /**
     * Seeds every set and returns the default one.
     *
     * The return value is the **default** set specifically: SampleContentSeeder
     * and TemplateAssessmentSeeder calibrate the assessments they build from
     * it, and the fixtures they load are written against its bands.
     *
     * @return array{impact: Scale, likelihood: Scale, strength: Scale}
     */
    public function run(): array
    {
        $default = $this->seedSet(ScaleSets::DEFAULT_KEY, $this->defaultBands());
        $this->seedName(ScaleSets::DEFAULT_KEY, 'Standard', 0);

        $this->seedSet(ScaleSets::SMALL_ORGANISATION_KEY, $this->smallOrganisationBands());
        $this->seedName(ScaleSets::SMALL_ORGANISATION_KEY, 'Small organisation', 1);

        return $default;
    }

    /**
     * The original system default (schema.md §3.2), unchanged by W77.
     *
     * @return array{impact: array{name: string, unit: string, bands: list<array{0: string, 1: string, 2: float, 3: ?float, 4: float}>}, likelihood: array{name: string, unit: string, bands: list<array{0: string, 1: string, 2: float, 3: ?float, 4: float}>}, strength: array{name: string, unit: string, bands: list<array{0: string, 1: string, 2: float, 3: ?float, 4: float}>}}
     */
    private function defaultBands(): array
    {
        return [
            'impact' => [
                'name' => 'Impact (system default)',
                'unit' => 'currency',
                'bands' => [
                    ['negligible', 'Negligible', 0, 1_000, 250],
                    ['minor', 'Minor', 1_000, 10_000, 3_162],
                    ['moderate', 'Moderate', 10_000, 100_000, 31_623],
                    ['major', 'Major', 100_000, 1_000_000, 316_228],
                    ['severe', 'Severe', 1_000_000, null, 3_000_000],
                ],
            ],
            'likelihood' => [
                'name' => 'Likelihood (system default)',
                'unit' => 'rate_per_year',
                'bands' => [
                    ['rare', 'Rare', 0, 0.02, 0.005],
                    ['unlikely', 'Unlikely', 0.02, 0.2, 0.06],
                    ['possible', 'Possible', 0.2, 1, 0.45],
                    ['likely', 'Likely', 1, 10, 3.16],
                    ['frequent', 'Frequent', 10, null, 30],
                ],
            ],
            'strength' => [
                'name' => 'Strength (system default)',
                'unit' => 'fraction',
                'bands' => [
                    ['weak', 'Weak', 0, 0.2, 0.1],
                    ['limited', 'Limited', 0.2, 0.5, 0.35],
                    ['moderate', 'Moderate', 0.5, 0.8, 0.65],
                    ['strong', 'Strong', 0.8, 0.95, 0.875],
                    ['very_strong', 'Very strong', 0.95, 1, 0.975],
                ],
            ],
        ];
    }

    /**
     * A calibration for an organisation two orders of magnitude smaller than
     * the one the default set assumes (W77, PLAN-2.4.md D36).
     *
     * Why a real assessor would pick it, kind by kind:
     *
     * - **Impact.** The default set calls anything under €1,000 "negligible"
     *   and needs a million-euro loss before it says "severe". For a ten-person
     *   firm that compresses every loss it will ever actually suffer into the
     *   bottom two bands, and a scale whose top three bands are unreachable
     *   cannot discriminate between the risks it is being used to rank. These
     *   bands are the same shape shifted down by two decades: 0→100 up to
     *   100k→∞.
     * - **Likelihood.** A small estate is watched less closely and its events
     *   are counted over years, not quarters, so the useful boundaries sit
     *   lower and wider: "possible" spans half a year to twice a year rather
     *   than the default's fifth-of-a-year to yearly.
     * - **Strength.** Deliberately more pessimistic at the top. The default
     *   set will call a control 97.5% effective; a small organisation's
     *   controls are rarely monitored, tested or staffed well enough to earn
     *   that, and overstating the top band is the one direction in which an
     *   error understates residual risk (calculation-engine.md's safe-direction
     *   rule).
     *
     * Every representative follows the §3.2.1 generation rule — geometric mean
     * within a closed currency or rate band, `upper / 4` where the lower bound
     * is 0, `lower × 3` for an open top band, and the arithmetic midpoint for
     * fractions — so the qualitative → numeric → qualitative round trip stays
     * lossless (invariant 5).
     *
     * @return array{impact: array{name: string, unit: string, bands: list<array{0: string, 1: string, 2: float, 3: ?float, 4: float}>}, likelihood: array{name: string, unit: string, bands: list<array{0: string, 1: string, 2: float, 3: ?float, 4: float}>}, strength: array{name: string, unit: string, bands: list<array{0: string, 1: string, 2: float, 3: ?float, 4: float}>}}
     */
    private function smallOrganisationBands(): array
    {
        return [
            'impact' => [
                'name' => 'Impact (small organisation)',
                'unit' => 'currency',
                'bands' => [
                    ['negligible', 'Negligible', 0, 100, 25],
                    ['minor', 'Minor', 100, 1_000, 316],
                    ['moderate', 'Moderate', 1_000, 10_000, 3_162],
                    ['major', 'Major', 10_000, 100_000, 31_623],
                    ['severe', 'Severe', 100_000, null, 300_000],
                ],
            ],
            'likelihood' => [
                'name' => 'Likelihood (small organisation)',
                'unit' => 'rate_per_year',
                'bands' => [
                    ['rare', 'Rare', 0, 0.1, 0.025],
                    ['unlikely', 'Unlikely', 0.1, 0.5, 0.224],
                    ['possible', 'Possible', 0.5, 2, 1],
                    ['likely', 'Likely', 2, 12, 4.899],
                    ['frequent', 'Frequent', 12, null, 36],
                ],
            ],
            'strength' => [
                'name' => 'Strength (small organisation)',
                'unit' => 'fraction',
                'bands' => [
                    ['weak', 'Weak', 0, 0.1, 0.05],
                    ['limited', 'Limited', 0.1, 0.3, 0.2],
                    ['moderate', 'Moderate', 0.3, 0.6, 0.45],
                    ['strong', 'Strong', 0.6, 0.85, 0.725],
                    ['very_strong', 'Very strong', 0.85, 1, 0.925],
                ],
            ],
        ];
    }

    /**
     * The built-in scale's name (W126, D93). Only created when missing, so an
     * admin's edits to a name or order are never undone by a re-seed; a
     * built-in cannot be renamed in the interface, so this only matters to a
     * hand-edited database.
     */
    private function seedName(string $key, string $name, int $position): void
    {
        if (ScaleSet::query()->where('key', $key)->exists()) {
            return;
        }

        $set = new ScaleSet(['name' => $name]);
        $set->key = $key;
        $set->is_builtin = true;
        $set->position = $position;
        $set->save();
    }

    /**
     * @param  array<string, array{name: string, unit: string, bands: list<array{0: string, 1: string, 2: float, 3: ?float, 4: float}>}>  $set
     * @return array{impact: Scale, likelihood: Scale, strength: Scale}
     */
    private function seedSet(string $setKey, array $set): array
    {
        return [
            'impact' => $this->seedScale($setKey, 'impact', $set['impact']),
            'likelihood' => $this->seedScale($setKey, 'likelihood', $set['likelihood']),
            'strength' => $this->seedScale($setKey, 'strength', $set['strength']),
        ];
    }

    /** @param  array{name: string, unit: string, bands: list<array{0: string, 1: string, 2: float, 3: ?float, 4: float}>}  $definition */
    private function seedScale(string $setKey, string $kind, array $definition): Scale
    {
        $scale = Scale::query()
            ->where('kind', $kind)
            ->where('is_system', true)
            ->whereNull('assessment_id')
            ->where('set_key', $setKey)
            ->first();

        if ($scale === null) {
            $scale = new Scale([
                'kind' => $kind,
                'name' => $definition['name'],
                'unit' => $definition['unit'],
                'is_system' => true,
            ]);
            // set_key is assigned directly rather than mass-assigned, for the
            // same reason scale_id is below: it is deliberately absent from
            // Scale::$fillable, so a create() carrying it would have the value
            // dropped in silence and write a system trio belonging to no set —
            // which nothing would then be able to select.
            $scale->set_key = $setKey;
            $scale->save();
        }

        if ($scale->levels()->exists()) {
            return $scale;
        }

        foreach ($definition['bands'] as $index => [$code, $label, $lower, $upper, $representative]) {
            // scale_id is assigned directly rather than mass-assigned:
            // ScaleLevel::$fillable deliberately omits it as an identity
            // column, so a create() carrying it silently drops the value and
            // then fails the NOT NULL constraint. This is the same trap
            // AssessmentCloner hit with Tag and RiskAttack, and the reason
            // LinkService's docblock warns about it.
            $level = new ScaleLevel([
                'ordinal' => $index + 1,
                'code' => $code,
                'label' => $label,
                'lower_bound' => $lower,
                'upper_bound' => $upper,
                'representative' => $representative,
            ]);
            $level->scale_id = $scale->id;
            $level->save();
        }

        return $scale;
    }
}
