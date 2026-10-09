<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\RootNode;
use App\Models\Scale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Assessment> */
class AssessmentFactory extends Factory
{
    protected $model = Assessment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'owner_user_id' => User::factory(),
            'currency' => 'EUR',
            'display_mode' => 'mixed',
            'impact_scale_id' => Scale::factory()->systemImpact(),
            'likelihood_scale_id' => Scale::factory()->systemLikelihood(),
            'strength_scale_id' => Scale::factory()->systemStrength(),
            'amortization_years' => 3,
            'is_public' => false,
            'is_template' => false,
            'calc_version' => 0,
        ];
    }

    /**
     * Every assessment needs exactly one root node (nodes_root_unique).
     * Built here, explicitly, rather than via a default on NodeFactory's
     * `assessment_id` — a default there would recurse back into this
     * factory and create a second, colliding root.
     */
    /**
     * Deliberately queries fresh rather than reading `$assessment->rootNode`
     * — that magic-property access caches the relation (as null) on this
     * exact instance, and the cached null would then survive the create()
     * below for the rest of the test, silently breaking every later
     * `$assessment->rootNode` read.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Assessment $assessment): void {
            $exists = RootNode::query()->where('assessment_id', $assessment->id)->exists();

            if (! $exists) {
                RootNode::factory()->create(['assessment_id' => $assessment->id]);
            }
        });
    }

    public function public(): static
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }

    public function template(): static
    {
        return $this->state(fn (): array => ['is_template' => true]);
    }
}
