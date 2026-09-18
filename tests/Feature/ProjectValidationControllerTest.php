<?php

namespace Tests\Feature;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectValidationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_validation_returns_assembly_summary_and_actionable_warnings(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 7]);
        $usedPart = PartDefinition::factory()->for($project)->create([
            'length' => 1000,
            'width' => 500,
            'thickness' => 40,
        ]);
        PartDefinition::factory()->for($project)->create(['name' => 'Unused support']);
        PartInstance::factory()->for($project)->for($usedPart)->create([
            'position_x' => 0,
            'position_y' => 0,
            'position_z' => -10,
        ]);
        Sanctum::actingAs($user, ['projects:read']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/validate");

        $response
            ->assertOk()
            ->assertJsonPath('data.revision', 7)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.summary.part_count', 2)
            ->assertJsonPath('data.summary.instance_count', 1)
            ->assertJsonPath('data.summary.bounds.min.z', -30)
            ->assertJsonFragment(['code' => 'part_unused'])
            ->assertJsonFragment(['code' => 'instance_below_floor']);
    }

    public function test_validation_bounds_include_instance_rotation(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $part = PartDefinition::factory()->for($project)->create([
            'length' => 1000,
            'width' => 100,
            'thickness' => 50,
        ]);
        PartInstance::factory()->for($project)->for($part)->create([
            'position_z' => 0,
            'rotation_y' => 90,
        ]);
        Sanctum::actingAs($user, ['projects:read']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/validate");

        $response
            ->assertOk()
            ->assertJsonPath('data.summary.bounds.min.z', -500)
            ->assertJsonPath('data.summary.bounds.max.z', 500)
            ->assertJsonPath('data.summary.bounds_note', 'Bounds include instance rotation and are axis-aligned in project coordinates.');
    }
}
