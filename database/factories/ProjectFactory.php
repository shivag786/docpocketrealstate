<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' '.fake()->randomElement(['Greens', 'Heights', 'Enclave', 'City']),
            'location' => fake()->city(),
            'description' => fake()->sentence(),
            'status' => ProjectStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::Inactive]);
    }
}
