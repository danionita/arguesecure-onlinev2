<?php

namespace Database\Factories;

use App\Models\Attack;

/** @extends NodeFactory<Attack> */
class AttackFactory extends NodeFactory
{
    protected $model = Attack::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            ...parent::definition(),
            'likelihood_mode' => 'quantitative',
            'likelihood_aro' => $this->faker->randomFloat(6, 0, 5),
            'likelihood_level_id' => null,
        ];
    }

    public function qualitative(?int $likelihoodLevelId = null): static
    {
        return $this->state(fn (): array => [
            'likelihood_mode' => 'qualitative',
            'likelihood_aro' => null,
            'likelihood_level_id' => $likelihoodLevelId,
        ]);
    }

    public function withoutValue(): static
    {
        return $this->state(fn (): array => [
            'likelihood_mode' => 'quantitative',
            'likelihood_aro' => null,
            'likelihood_level_id' => null,
        ]);
    }
}
