<?php

namespace Database\Factories;

use App\Models\PartDefinition;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartDefinition>
 */
class PartDefinitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement(['Ножка стола', 'Столешница', 'Царга']),
            'material' => fake()->randomElement(['Дуб', 'Ясень', 'Берёзовая фанера']),
            'length' => fake()->numberBetween(400, 1600),
            'width' => fake()->numberBetween(40, 800),
            'thickness' => fake()->numberBetween(18, 80),
            'grain_axis' => 'length',
            'operations' => [],
        ];
    }
}
