<?php

namespace Database\Factories;

use App\Models\Defence;

/** @extends NodeFactory<Defence> */
class DefenceFactory extends NodeFactory
{
    protected $model = Defence::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            ...parent::definition(),
            'is_transfer' => false,
            'status' => 'todo',
            'capex_amount' => $this->faker->randomFloat(2, 0, 20_000),
            'opex_annual_amount' => $this->faker->randomFloat(2, 0, 10_000),
        ];
    }

    public function implemented(): static
    {
        return $this->state(fn (): array => ['status' => 'implemented']);
    }

    public function shelved(): static
    {
        return $this->state(fn (): array => ['status' => 'shelved']);
    }

    public function transfer(): static
    {
        return $this->state(fn (): array => ['is_transfer' => true]);
    }
}
