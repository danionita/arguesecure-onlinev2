<?php

namespace Database\Factories;

use App\Models\NodeReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NodeReference> */
class NodeReferenceFactory extends Factory
{
    protected $model = NodeReference::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $year = $this->faker->numberBetween(2015, 2026);
        $sequence = $this->faker->numberBetween(1000, 99999);

        return [
            'kind' => 'cve',
            'identifier' => "CVE-{$year}-{$sequence}",
            'url' => "https://nvd.nist.gov/vuln/detail/CVE-{$year}-{$sequence}",
            'title' => $this->faker->sentence(4),
            'position' => 0,
        ];
    }
}
