<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectCommandControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dry_run_validates_batch_without_persisting_changes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 3]);
        Sanctum::actingAs($user, ['projects:write']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/commands", [
            'expected_revision' => 3,
            'dry_run' => true,
            'commands' => [$this->createLegCommand()],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.proposed_revision', 4)
            ->assertJsonPath('data.results.0.temporary_id', 'leg');

        $this->assertDatabaseCount('part_definitions', 0);
        $this->assertSame(3, $project->fresh()->revision);
    }

    public function test_valid_batch_creates_reusable_part_and_instances_atomically(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 1]);
        Sanctum::actingAs($user, ['projects:write']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/commands", [
            'expected_revision' => 1,
            'commands' => [
                $this->createLegCommand(),
                [
                    'type' => 'create_instance',
                    'temporary_id' => 'front-left-leg',
                    'part_ref' => 'leg',
                    'data' => ['position_x' => -650, 'position_y' => -300, 'rotation_y' => 12],
                ],
                [
                    'type' => 'create_instance',
                    'part_ref' => 'leg',
                    'data' => ['position_x' => 650, 'position_y' => -300, 'rotation_y' => -12],
                ],
                [
                    'type' => 'transform_instance',
                    'instance_ref' => 'front-left-leg',
                    'data' => ['position_z' => 20],
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.diagnostics.summary.part_count', 1)
            ->assertJsonPath('data.diagnostics.summary.instance_count', 2);

        $this->assertDatabaseCount('part_definitions', 1);
        $this->assertDatabaseCount('part_instances', 2);
        $this->assertDatabaseHas('part_instances', [
            'project_id' => $project->id,
            'position_x' => -650,
            'position_z' => 20,
        ]);
        $this->assertSame(2, $project->fresh()->revision);
    }

    public function test_stale_revision_returns_409_without_mutating_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 5]);
        Sanctum::actingAs($user, ['projects:write']);

        $this->postJson("/api/v1/projects/{$project->id}/commands", [
            'expected_revision' => 4,
            'commands' => [$this->createLegCommand()],
        ])
            ->assertConflict()
            ->assertJsonPath('current_revision', 5);

        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_unknown_temporary_reference_returns_422_and_rolls_back_batch(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user, ['projects:write']);

        $this->postJson("/api/v1/projects/{$project->id}/commands", [
            'expected_revision' => 1,
            'commands' => [
                $this->createLegCommand(),
                ['type' => 'create_instance', 'part_ref' => 'missing-part'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('commands.1.part_ref');

        $this->assertDatabaseCount('part_definitions', 0);
        $this->assertDatabaseCount('part_instances', 0);
    }

    public function test_read_only_token_returns_403_for_batch_commands(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user, ['projects:read']);

        $this->postJson("/api/v1/projects/{$project->id}/commands", [
            'expected_revision' => 1,
            'commands' => [$this->createLegCommand()],
        ])->assertForbidden();

        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_batch_command_accepts_surface_based_woodworking_operations(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user, ['projects:write']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/commands", [
            'expected_revision' => 1,
            'commands' => [[
                'type' => 'create_part',
                'data' => [
                    'name' => 'Edge grooved rail',
                    'material' => 'Oak',
                    'length' => 600,
                    'width' => 80,
                    'thickness' => 30,
                    'grain_axis' => 'length',
                    'operations' => [
                        [
                            'type' => 'groove',
                            'face' => 'left',
                            'center_u' => 300,
                            'center_v' => 15,
                            'path_angle' => 0,
                            'groove_length' => 240,
                            'width' => 4,
                            'depth' => 10,
                            'blade_diameter' => 190,
                        ],
                        [
                            'type' => 'edge_roundover',
                            'edge' => 'top_left',
                            'radius' => 6,
                        ],
                        [
                            'type' => 'plunge_route',
                            'face' => 'right',
                            'route_mode' => 'point',
                            'start_u' => 300,
                            'start_v' => 15,
                            'path_angle' => 0,
                            'travel_length' => 0,
                            'cutter_diameter' => 8,
                            'cutter_profile' => 'straight',
                            'cutter_angle' => 0,
                            'depth' => 12,
                        ],
                        [
                            'type' => 'drill',
                            'face' => 'start',
                            'center_u' => 40,
                            'center_v' => 15,
                            'diameter' => 8,
                            'depth' => 600,
                            'through' => true,
                        ],
                    ],
                ],
            ]],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.revision', 2);

        $this->assertDatabaseHas('part_definitions', [
            'project_id' => $project->id,
            'name' => 'Edge grooved rail',
        ]);
    }

    public function test_batch_command_creates_group_and_assigns_new_instance(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user, ['projects:write']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/commands", [
            'expected_revision' => 1,
            'commands' => [
                [
                    'type' => 'create_group',
                    'temporary_id' => 'roof',
                    'data' => ['name' => 'Крыша'],
                ],
                [
                    'type' => 'create_group',
                    'temporary_id' => 'frame',
                    'data' => ['name' => 'Каркас'],
                ],
                [
                    'type' => 'create_part',
                    'temporary_id' => 'rafter',
                    'data' => [
                        'name' => 'Стропило',
                        'material' => 'Сосна',
                        'length' => 3000,
                        'width' => 150,
                        'thickness' => 50,
                        'grain_axis' => 'length',
                        'operations' => [],
                    ],
                ],
                [
                    'type' => 'create_instance',
                    'temporary_id' => 'rafter-instance',
                    'part_ref' => 'rafter',
                    'group_ref' => 'roof',
                    'data' => ['position_z' => 2400],
                ],
                [
                    'type' => 'assign_instance_to_group',
                    'instance_ref' => 'rafter-instance',
                    'group_ref' => 'frame',
                ],
                [
                    'type' => 'update_group',
                    'group_ref' => 'roof',
                    'data' => ['name' => 'Кровля'],
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.results.0.type', 'create_group')
            ->assertJsonPath('data.results.4.type', 'assign_instance_to_group')
            ->assertJsonPath('data.results.5.type', 'update_group');

        $roofGroupId = $response->json('data.results.0.group_id');
        $frameGroupId = $response->json('data.results.1.group_id');
        $instanceId = $response->json('data.results.3.instance_id');

        $this->assertDatabaseHas('assembly_groups', [
            'id' => $roofGroupId,
            'project_id' => $project->id,
            'name' => 'Кровля',
        ]);
        $this->assertDatabaseHas('part_instances', [
            'id' => $instanceId,
            'assembly_group_id' => $frameGroupId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createLegCommand(): array
    {
        return [
            'type' => 'create_part',
            'temporary_id' => 'leg',
            'data' => [
                'name' => 'Table leg',
                'material' => 'Oak',
                'length' => 720,
                'width' => 70,
                'thickness' => 70,
                'grain_axis' => 'length',
                'operations' => [[
                    'id' => 'bottom-cut',
                    'type' => 'cross_cut',
                    'status' => 'applied',
                    'enabled' => true,
                    'position' => 700,
                    'miter_angle' => 12,
                    'bevel_angle' => 0,
                    'kerf' => 3.2,
                    'cut_depth' => 70,
                    'cut_direction' => 'top_down',
                    'keep_side' => 'start',
                ]],
            ],
        ];
    }
}
