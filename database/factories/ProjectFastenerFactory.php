<?php

namespace Database\Factories;

use App\Models\CatalogMaterial;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectFastener;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectFastener> */
class ProjectFastenerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'source_instance_id' => fn (array $attributes) => PartInstance::factory()->create([
                'project_id' => $attributes['project_id'],
                'part_definition_id' => PartDefinition::factory()->create(['project_id' => $attributes['project_id']])->id,
            ])->id,
            'catalog_material_id' => fn (array $attributes) => CatalogMaterial::factory()->consumable()->create([
                'user_id' => Project::query()->findOrFail($attributes['project_id'])->user_id,
                'fastener_diameter' => 3.4,
                'fastener_length' => 25,
            ])->id,
            'face' => 'top',
            'center_u' => 20,
            'center_v' => 20,
            'sink' => 0,
            'diameter' => 3.4,
            'length' => 25,
        ];
    }
}
