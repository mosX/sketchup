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
