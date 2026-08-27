<?php

namespace Database\Factories;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartInstance>
 */
class PartInstanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'part_definition_id' => PartDefinition::factory(),
            'project_id' => fn (array $attributes): int => PartDefinition::query()
                ->findOrFail($attributes['part_definition_id'])
                ->project_id,
            'position_x' => 0,
            'position_y' => 0,
            'position_z' => 0,
            'rotation_x' => 0,
            'rotation_y' => 0,
            'rotation_z' => 0,
            'mirrored' => false,
        ];
    }
}
