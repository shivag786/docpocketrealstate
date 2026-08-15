<?php

namespace Database\Factories;

use App\Enums\PropertyStatus;
use App\Models\Project;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'property_code' => 'PLOT-'.(++self::$sequence),
            'details' => fake()->sentence(),
            'status' => PropertyStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => PropertyStatus::Inactive]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }
}
