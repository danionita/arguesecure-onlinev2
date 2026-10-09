<?php

namespace Database\Factories;

use App\Models\RootNode;

/** @extends NodeFactory<RootNode> */
class RootNodeFactory extends NodeFactory
{
    protected $model = RootNode::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            ...parent::definition(),
            'title' => $this->faker->company().' security assessment',
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
