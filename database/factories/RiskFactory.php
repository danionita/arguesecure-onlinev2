<?php

namespace Database\Factories;

use App\Models\Risk;

/** @extends NodeFactory<Risk> */
class RiskFactory extends NodeFactory
{
    protected $model = Risk::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            ...parent::definition(),
            'impact_mode' => 'quantitative',
            'impact_amount' => $this->faker->randomFloat(2, 1_000, 500_000),
            'impact_level_id' => null,
        ];
    }

    /** A risk valued off a scale band rather than a typed amount. */
    public function qualitative(?int $impactLevelId = null): static
    {
        return $this->state(fn (): array => [
            'impact_mode' => 'qualitative',
            'impact_amount' => null,
            'impact_level_id' => $impactLevelId,
        ]);
    }

    /** The "mode picked, value not yet typed" state — treated as missing by the calculation engine. */
    public function withoutValue(): static
    {
        return $this->state(fn (): array => [
            'impact_mode' => 'quantitative',
            'impact_amount' => null,
            'impact_level_id' => null,
        ]);
    }
}
