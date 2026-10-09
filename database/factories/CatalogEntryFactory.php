<?php

namespace Database\Factories;

use App\Models\CatalogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogEntry> */
class CatalogEntryFactory extends Factory
{
    protected $model = CatalogEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => 'risk',
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->sentence(),
            'defaults' => [],
            'is_public' => false,
        ];
    }
}
