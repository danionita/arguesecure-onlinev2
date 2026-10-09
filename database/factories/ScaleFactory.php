<?php

namespace Database\Factories;

use App\Models\Scale;
use App\Models\ScaleLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The three `system*` states seed exactly the default bands from
 * schema.md §3.2 (kind, unit, and every level's lower/upper/representative),
 * so tests get a realistic, invariant-passing calibration for free.
 *
 * @extends Factory<Scale>
 */
class ScaleFactory extends Factory
{
    protected $model = Scale::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assessment_id' => null,
            'kind' => 'impact',
            'name' => 'Custom impact scale',
            'unit' => 'currency',
            'is_system' => false,
        ];
    }

    public function forAssessment(int $assessmentId): static
    {
        return $this->state(fn (): array => ['assessment_id' => $assessmentId, 'is_system' => false]);
    }

    public function systemImpact(): static
    {
        return $this->state([
            'assessment_id' => null,
            'kind' => 'impact',
            'name' => 'Impact (system default)',
            'unit' => 'currency',
            'is_system' => true,
        ])->afterCreating(fn (Scale $scale) => $this->seedLevels($scale, [
            ['negligible', 'Negligible', 0, 1_000, 250],
            ['minor', 'Minor', 1_000, 10_000, 3_162],
            ['moderate', 'Moderate', 10_000, 100_000, 31_623],
            ['major', 'Major', 100_000, 1_000_000, 316_228],
            ['severe', 'Severe', 1_000_000, null, 3_000_000],
        ]));
    }

    public function systemLikelihood(): static
    {
        return $this->state([
            'assessment_id' => null,
            'kind' => 'likelihood',
            'name' => 'Likelihood (system default)',
            'unit' => 'rate_per_year',
            'is_system' => true,
        ])->afterCreating(fn (Scale $scale) => $this->seedLevels($scale, [
            ['rare', 'Rare', 0, 0.02, 0.005],
            ['unlikely', 'Unlikely', 0.02, 0.2, 0.06],
            ['possible', 'Possible', 0.2, 1, 0.45],
            ['likely', 'Likely', 1, 10, 3.16],
            ['frequent', 'Frequent', 10, null, 30],
        ]));
    }

    public function systemStrength(): static
    {
        return $this->state([
            'assessment_id' => null,
            'kind' => 'strength',
            'name' => 'Strength (system default)',
            'unit' => 'fraction',
            'is_system' => true,
        ])->afterCreating(fn (Scale $scale) => $this->seedLevels($scale, [
            ['weak', 'Weak', 0, 0.2, 0.1],
            ['limited', 'Limited', 0.2, 0.5, 0.35],
            ['moderate', 'Moderate', 0.5, 0.8, 0.65],
            ['strong', 'Strong', 0.8, 0.95, 0.875],
            ['very_strong', 'Very strong', 0.95, 1, 0.975],
        ]));
    }

    /** @param  list<array{0: string, 1: string, 2: float, 3: ?float, 4: float}>  $bands */
    private function seedLevels(Scale $scale, array $bands): void
    {
        foreach ($bands as $index => [$code, $label, $lower, $upper, $representative]) {
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
    }
}
