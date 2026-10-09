<?php

namespace Database\Factories;

use App\Models\Scale;
use App\Models\ScaleLevel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ScaleLevel> */
class ScaleLevelFactory extends Factory
{
    protected $model = ScaleLevel::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $label = ucfirst($this->faker->unique()->word());

        return [
            'scale_id' => Scale::factory(),
            'ordinal' => 1,
            'code' => Str::slug($label, '_'),
            'label' => $label,
            'color' => '#9CA3AF',
            'lower_bound' => 0,
            'upper_bound' => 100,
            'representative' => 50,
        ];
    }
}
