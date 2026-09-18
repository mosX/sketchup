<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectVersion>
 */
class ProjectVersionFactory extends Factory
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
            'label' => fake()->words(3, true),
            'revision' => 1,
            'snapshot' => ['schema_version' => 1, 'project' => ['name' => 'Version', 'description' => null, 'scene_data' => ['version' => 1, 'objects' => []], 'script_data' => null], 'parts' => [], 'groups' => [], 'instances' => [], 'connections' => []],
        ];
    }
}
