<?php

namespace Database\Factories;

use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTemplate>
 */
class ProjectTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'base_dimensions' => ['width' => 1000, 'depth' => 600, 'height' => 750],
            'snapshot' => ['origin' => ['x' => 0, 'y' => 0, 'z' => 0], 'groups' => [], 'parts' => []],
            'schema_version' => 1,
        ];
    }
}
