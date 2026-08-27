<?php

namespace Tests\Feature;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartDefinitionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $project = Project::factory()->create();

        $this->get("/api/projects/{$project->id}/parts")
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json');
    }

    public function test_valid_payload_creates_part_definition_and_returns_201(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Ножка стола',
            'material' => 'Дуб',
            'length' => 720,
            'width' => 60,
            'thickness' => 60,
            'grain_axis' => 'length',
            'operations' => [
                [
                    'id' => 'cross-1',
                    'type' => 'cross_cut',
                    'enabled' => true,
                    'position' => 700,
                    'miter_angle' => 12,
                    'bevel_angle' => 8,
                    'kerf' => 3.2,
                    'keep_side' => 'start',
                ],
                [
                    'id' => 'groove-1',
                    'type' => 'groove',
                    'enabled' => true,
                    'face' => 'top',
                    'direction' => 'length',
                    'offset' => 30,
                    'start' => 100,
                    'end' => 300,
                    'width' => 6,
                    'depth' => 12,
                    'blade_diameter' => 190,
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ножка стола')
            ->assertJsonPath('data.dimensions.length', 720)
            ->assertJsonPath('data.operations.0.miter_angle', 12)
            ->assertJsonPath('data.operations.1.depth', 12);

        $this->assertDatabaseHas('part_definitions', [
            'project_id' => $project->id,
            'name' => 'Ножка стола',
        ]);
    }

    public function test_cross_cut_cannot_be_positioned_beyond_part_length(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Ножка стола',
            'length' => 720,
            'width' => 60,
            'thickness' => 60,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'cross_cut',
                'position' => 800,
                'miter_angle' => 12,
                'bevel_angle' => 0,
                'kerf' => 3.2,
                'keep_side' => 'start',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('operations.0.position');

        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_part_definition_of_another_project_returns_404(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $foreignPart = PartDefinition::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/projects/{$project->id}/parts/{$foreignPart->id}")->assertNotFound();
    }

    public function test_updating_definition_preserves_linked_instances(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $partDefinition = PartDefinition::factory()->for($project)->create(['length' => 700]);
        $instance = PartInstance::factory()->for($project)->for($partDefinition)->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/projects/{$project->id}/parts/{$partDefinition->id}", [
            'length' => 720,
            'operations' => [[
                'type' => 'rip_cut',
                'reference_side' => 'left',
                'start_offset' => 8,
                'end_offset' => 18,
                'bevel_angle' => 4,
                'kerf' => 3.2,
                'keep_side' => 'opposite',
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.dimensions.length', 720)
            ->assertJsonPath('data.instance_count', 1)
            ->assertJsonPath('data.instances.0.id', $instance->id);

        $this->assertDatabaseHas('part_instances', ['id' => $instance->id]);
    }

    public function test_groove_depth_cannot_exceed_part_thickness(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Панель',
            'length' => 600,
            'width' => 300,
            'thickness' => 18,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'groove',
                'face' => 'top',
                'direction' => 'length',
                'offset' => 30,
                'start' => 20,
                'end' => 500,
                'width' => 4,
                'depth' => 20,
                'blade_diameter' => 190,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('operations.0.depth');
    }

    public function test_deleting_definition_removes_its_instances(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $partDefinition = PartDefinition::factory()->for($project)->create();
        $instance = PartInstance::factory()->for($project)->for($partDefinition)->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/projects/{$project->id}/parts/{$partDefinition->id}");

        $response->assertNoContent();
        $this->assertModelMissing($partDefinition);
        $this->assertModelMissing($instance);
    }
}
