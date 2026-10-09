<?php

namespace Database\Factories;

use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Base factory shared by RootNodeFactory/RiskFactory/AttackFactory/
 * DefenceFactory. Deliberately has no `assessment_id` default: a node
 * always belongs to a specific assessment and guessing one via a nested
 * `Assessment::factory()` would let AssessmentFactory's own root-node
 * creation collide with it. Callers must pass `assessment_id` explicitly
 * or use `->for($assessment)`.
 *
 * @template TModel of Node
 *
 * @extends Factory<TModel>
 */
class NodeFactory extends Factory
{
    protected $model = Node::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'description' => null,
            'position' => 0,
        ];
    }
}
