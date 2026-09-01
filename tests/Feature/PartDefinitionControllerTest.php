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
                    'status' => 'applied',
                    'enabled' => true,
                    'position' => 700,
                    'miter_angle' => 12,
                    'bevel_angle' => 8,
                    'kerf' => 3.2,
                    'cut_depth' => 24,
                    'cut_direction' => 'top_down',
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
            ->assertJsonPath('data.operations.0.status', 'applied')
            ->assertJsonPath('data.operations.0.miter_angle', 12)
            ->assertJsonPath('data.operations.0.cut_depth', 24)
            ->assertJsonPath('data.operations.0.cut_direction', 'top_down')
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

    public function test_invalid_operation_status_does_not_create_part_definition(): void
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
                'type' => 'cross_cut',
                'status' => 'finished',
                'position' => 580,
                'miter_angle' => 0,
                'bevel_angle' => 0,
                'kerf' => 3.2,
                'keep_side' => 'start',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('operations.0.status');

        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_cut_depth_and_direction_must_match_the_part(): void
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
                'type' => 'cross_cut',
                'position' => 580,
                'miter_angle' => 0,
                'bevel_angle' => 0,
                'kerf' => 3.2,
                'cut_depth' => 20,
                'cut_direction' => 'left_to_right',
                'keep_side' => 'start',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'operations.0.cut_depth',
            'operations.0.cut_direction',
        ]);

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

    public function test_valid_surface_groove_can_be_created_on_a_side_face(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Side grooved panel',
            'length' => 600,
            'width' => 300,
            'thickness' => 18,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'groove',
                'face' => 'right',
                'center_u' => 300,
                'center_v' => 9,
                'path_angle' => 0,
                'groove_length' => 240,
                'width' => 4,
                'depth' => 12,
                'blade_diameter' => 190,
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.operations.0.face', 'right')
            ->assertJsonPath('data.operations.0.center_u', 300)
            ->assertJsonPath('data.operations.0.groove_length', 240)
            ->assertJsonPath('data.operations.0.depth', 12);

        $this->assertDatabaseHas('part_definitions', [
            'project_id' => $project->id,
            'name' => 'Side grooved panel',
        ]);
    }

    public function test_surface_groove_returns_422_when_it_exceeds_the_selected_face(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Invalid side groove',
            'length' => 600,
            'width' => 300,
            'thickness' => 18,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'groove',
                'face' => 'right',
                'center_u' => 590,
                'center_v' => 9,
                'path_angle' => 0,
                'groove_length' => 100,
                'width' => 4,
                'depth' => 301,
                'blade_diameter' => 190,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'operations.0.center_u',
            'operations.0.depth',
        ]);

        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_valid_edge_roundover_can_be_created(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Rounded shelf',
            'length' => 600,
            'width' => 300,
            'thickness' => 18,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'edge_roundover',
                'edge' => 'top_left',
                'radius' => 6,
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.operations.0.type', 'edge_roundover')
            ->assertJsonPath('data.operations.0.edge', 'top_left')
            ->assertJsonPath('data.operations.0.radius', 6);

        $this->assertDatabaseHas('part_definitions', [
            'project_id' => $project->id,
            'name' => 'Rounded shelf',
        ]);
    }

    public function test_edge_roundover_returns_422_when_radius_exceeds_edge_limit(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Invalid rounded shelf',
            'length' => 600,
            'width' => 300,
            'thickness' => 18,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'edge_roundover',
                'edge' => 'top_left',
                'radius' => 10,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('operations.0.radius');

        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_valid_plunge_router_path_can_be_created_on_a_side_face(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Mortised rail',
            'length' => 600,
            'width' => 80,
            'thickness' => 30,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'plunge_route',
                'face' => 'left',
                'route_mode' => 'path',
                'start_u' => 120,
                'start_v' => 15,
                'path_angle' => 0,
                'travel_length' => 240,
                'cutter_diameter' => 16,
                'cutter_profile' => 'dovetail',
                'cutter_angle' => 14,
                'depth' => 20,
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.operations.0.type', 'plunge_route')
            ->assertJsonPath('data.operations.0.route_mode', 'path')
            ->assertJsonPath('data.operations.0.face', 'left')
            ->assertJsonPath('data.operations.0.travel_length', 240)
            ->assertJsonPath('data.operations.0.cutter_diameter', 16)
            ->assertJsonPath('data.operations.0.cutter_profile', 'dovetail')
            ->assertJsonPath('data.operations.0.cutter_angle', 14)
            ->assertJsonPath('data.operations.0.depth', 20);
    }

    public function test_plunge_router_returns_422_when_path_or_depth_exceeds_the_selected_face(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Invalid mortise',
            'length' => 600,
            'width' => 80,
            'thickness' => 30,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'plunge_route',
                'face' => 'left',
                'route_mode' => 'path',
                'start_u' => 570,
                'start_v' => 15,
                'path_angle' => 0,
                'travel_length' => 50,
                'cutter_diameter' => 12,
                'cutter_profile' => 'straight',
                'cutter_angle' => 0,
                'depth' => 90,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'operations.0.start_u',
            'operations.0.depth',
        ]);

        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_point_plunge_requires_zero_travel_length(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Invalid plunge',
            'length' => 600,
            'width' => 80,
            'thickness' => 30,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'plunge_route',
                'face' => 'top',
                'route_mode' => 'point',
                'start_u' => 300,
                'start_v' => 40,
                'path_angle' => 0,
                'travel_length' => 20,
                'cutter_diameter' => 8,
                'cutter_profile' => 'straight',
                'cutter_angle' => 0,
                'depth' => 10,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('operations.0.travel_length');
    }

    public function test_valid_v_groove_plunge_can_be_created(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'V grooved panel',
            'length' => 600,
            'width' => 300,
            'thickness' => 18,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'plunge_route',
                'face' => 'top',
                'route_mode' => 'path',
                'start_u' => 100,
                'start_v' => 150,
                'path_angle' => 0,
                'travel_length' => 300,
                'cutter_diameter' => 20,
                'cutter_profile' => 'v_groove',
                'cutter_angle' => 90,
                'depth' => 8,
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.operations.0.cutter_profile', 'v_groove')
            ->assertJsonPath('data.operations.0.cutter_angle', 90)
            ->assertJsonPath('data.operations.0.depth', 8);
    }

    public function test_v_groove_plunge_returns_422_when_depth_exceeds_cutter_profile(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Invalid V groove',
            'length' => 600,
            'width' => 300,
            'thickness' => 30,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'plunge_route',
                'face' => 'top',
                'route_mode' => 'point',
                'start_u' => 300,
                'start_v' => 150,
                'path_angle' => 0,
                'travel_length' => 0,
                'cutter_diameter' => 12,
                'cutter_profile' => 'v_groove',
                'cutter_angle' => 90,
                'depth' => 10,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('operations.0.depth');

        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_valid_through_hole_can_be_created_on_a_side_face(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Drilled rail',
            'length' => 600,
            'width' => 80,
            'thickness' => 30,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'drill',
                'face' => 'left',
                'center_u' => 300,
                'center_v' => 15,
                'diameter' => 8,
                'depth' => 80,
                'through' => true,
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.operations.0.type', 'drill')
            ->assertJsonPath('data.operations.0.face', 'left')
            ->assertJsonPath('data.operations.0.diameter', 8)
            ->assertJsonPath('data.operations.0.through', true);
    }

    public function test_drill_returns_422_when_hole_or_depth_exceeds_the_selected_face(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/parts", [
            'name' => 'Invalid drilled rail',
            'length' => 600,
            'width' => 80,
            'thickness' => 30,
            'grain_axis' => 'length',
            'operations' => [[
                'type' => 'drill',
                'face' => 'left',
                'center_u' => 598,
                'center_v' => 15,
                'diameter' => 8,
                'depth' => 81,
                'through' => false,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'operations.0.center_u',
            'operations.0.depth',
        ]);

        $this->assertDatabaseCount('part_definitions', 0);
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
