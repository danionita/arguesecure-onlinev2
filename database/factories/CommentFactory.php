<?php

namespace Database\Factories;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Comment> */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'body' => $this->faker->paragraph(),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => ['resolved_at' => now()]);
    }
}
