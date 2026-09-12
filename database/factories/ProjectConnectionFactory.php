<?php

namespace Database\Factories;

use App\Models\PartInstance;
use App\Models\ProjectConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectConnection>
 */
class ProjectConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'primary_instance_id' => PartInstance::factory(),
            'secondary_instance_id' => function (array $attributes): int {
                $primary = PartInstance::query()->findOrFail($attributes['primary_instance_id']);

                return PartInstance::factory()
                    ->for($primary->project)
                    ->for($primary->partDefinition)
                    ->create(['position_x' => $primary->position_x + 100])
                    ->id;
            },
            'project_id' => fn (array $attributes): int => PartInstance::query()->findOrFail($attributes['primary_instance_id'])->project_id,
            'type' => 'butt',
            'label' => fake()->optional()->words(2, true),
            'parameters' => ['primary_face' => 'end', 'secondary_face' => 'start'],
            'note' => fake()->optional()->sentence(),
            'is_verified' => false,
            'machining_status' => 'pending',
            'generated_operation_ids' => [],
        ];
    }
}
