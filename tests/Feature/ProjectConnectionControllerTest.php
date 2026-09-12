<?php

namespace Tests\Feature;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectConnectionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_create_update_list_and_delete_connection(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 1]);
        [$primary, $secondary] = $this->createInstances($project);
        Sanctum::actingAs($user);

        $createResponse = $this->postJson("/api/projects/{$project->id}/connections", [
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'mortise_tenon',
            'label' => 'Шип царги',
            'parameters' => ['tenon_width' => 35, 'tenon_thickness' => 12, 'tenon_length' => 25],
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('data.type', 'mortise_tenon')
            ->assertJsonPath('data.parameters.tenon_width', 35)
            ->assertJsonPath('data.is_verified', false);

        $connectionId = $createResponse->json('data.id');

        $this->getJson("/api/projects/{$project->id}/connections")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Шип царги');

        $this->patchJson("/api/projects/{$project->id}/connections/{$connectionId}", [
            'is_verified' => true,
            'note' => 'Проверено в сборке',
        ])->assertOk()->assertJsonPath('data.is_verified', true);

        $this->deleteJson("/api/projects/{$project->id}/connections/{$connectionId}")->assertNoContent();

        $this->assertDatabaseCount('project_connections', 0);
        $this->assertSame(4, $project->refresh()->revision);
    }

    public function test_connection_requires_two_distinct_instances_from_same_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        [$primary] = $this->createInstances($project);
        $foreignProject = Project::factory()->for($user)->create();
        [$foreign] = $this->createInstances($foreignProject);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/connections", [
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $primary->id,
            'type' => 'butt',
        ])->assertUnprocessable()->assertJsonValidationErrors('secondary_instance_id');

        $this->postJson("/api/projects/{$project->id}/connections", [
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $foreign->id,
            'type' => 'butt',
        ])->assertUnprocessable()->assertJsonValidationErrors('secondary_instance_id');

        $this->assertDatabaseCount('project_connections', 0);
    }

    public function test_reverse_duplicate_connection_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        [$primary, $secondary] = $this->createInstances($project);
        $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'butt',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/connections", [
            'primary_instance_id' => $secondary->id,
            'secondary_instance_id' => $primary->id,
            'type' => 'half_lap',
        ])->assertUnprocessable()->assertJsonValidationErrors('secondary_instance_id');

        $this->assertDatabaseCount('project_connections', 1);
    }

    public function test_connection_from_another_project_is_not_found(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $foreignProject = Project::factory()->for($user)->create();
        [$primary, $secondary] = $this->createInstances($foreignProject);
        $connection = $foreignProject->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'butt',
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/projects/{$project->id}/connections/{$connection->id}")->assertNotFound();
    }

    public function test_dowel_machining_splits_shared_part_and_creates_applied_drills_once(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 1]);
        $sharedPart = PartDefinition::factory()->for($project)->create([
            'length' => 600,
            'width' => 80,
            'thickness' => 40,
            'operations' => [],
        ]);
        $secondaryPart = PartDefinition::factory()->for($project)->create([
            'length' => 500,
            'width' => 80,
            'thickness' => 40,
            'operations' => [],
        ]);
        $primary = PartInstance::factory()->for($project)->for($sharedPart)->create();
        $unrelatedCopy = PartInstance::factory()->for($project)->for($sharedPart)->create(['position_y' => 200]);
        $secondary = PartInstance::factory()->for($project)->for($secondaryPart)->create(['position_x' => 600]);
        $connection = $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'dowel',
            'parameters' => [
                'primary_face' => 'end',
                'secondary_face' => 'start',
                'dowel_diameter' => 8,
                'dowel_count' => 2,
                'dowel_depth' => 25,
            ],
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining");

        $response
            ->assertOk()
            ->assertJsonPath('data.machining_status', 'generated')
            ->assertJsonCount(4, 'data.generated_operations');
        $primary->refresh();
        $secondary->refresh();
        $this->assertNotSame($sharedPart->id, $primary->part_definition_id);
        $this->assertSame($sharedPart->id, $unrelatedCopy->refresh()->part_definition_id);
        $this->assertCount(2, $primary->partDefinition->operations);
        $this->assertCount(2, $secondary->partDefinition->operations);
        $this->assertSame('drill', $primary->partDefinition->operations[0]['type']);
        $this->assertSame('applied', $primary->partDefinition->operations[0]['status']);
        $this->assertSame([], $sharedPart->refresh()->operations);
        $this->assertSame(2, $project->refresh()->revision);

        $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")
            ->assertOk()
            ->assertJsonCount(4, 'data.generated_operations');

        $this->assertCount(2, $primary->partDefinition->refresh()->operations);
        $this->assertSame(2, $project->refresh()->revision);
    }

    public function test_half_lap_machining_creates_centered_groove_on_each_part(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $primaryPart = PartDefinition::factory()->for($project)->create(['length' => 600, 'width' => 100, 'thickness' => 40]);
        $secondaryPart = PartDefinition::factory()->for($project)->create(['length' => 400, 'width' => 80, 'thickness' => 30]);
        $primary = PartInstance::factory()->for($project)->for($primaryPart)->create();
        $secondary = PartInstance::factory()->for($project)->for($secondaryPart)->create();
        $connection = $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'half_lap',
            'parameters' => ['primary_face' => 'top', 'secondary_face' => 'bottom', 'depth_ratio' => 0.5],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")
            ->assertOk()
            ->assertJsonPath('data.machining_status', 'generated');

        $primaryOperation = $primaryPart->refresh()->operations[0];
        $secondaryOperation = $secondaryPart->refresh()->operations[0];
        $this->assertSame('groove', $primaryOperation['type']);
        $this->assertSame(400.0, (float) $primaryOperation['groove_length']);
        $this->assertSame(80.0, (float) $primaryOperation['width']);
        $this->assertSame(20.0, (float) $primaryOperation['depth']);
        $this->assertSame(15.0, (float) $secondaryOperation['depth']);
    }

    public function test_mortise_and_tenon_machining_removes_tenon_waste_and_creates_mortise(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $primaryPart = PartDefinition::factory()->for($project)->create(['length' => 600, 'width' => 80, 'thickness' => 40]);
        $secondaryPart = PartDefinition::factory()->for($project)->create(['length' => 500, 'width' => 100, 'thickness' => 50]);
        $primary = PartInstance::factory()->for($project)->for($primaryPart)->create();
        $secondary = PartInstance::factory()->for($project)->for($secondaryPart)->create();
        $connection = $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'mortise_tenon',
            'parameters' => [
                'primary_face' => 'end',
                'secondary_face' => 'start',
                'tenon_width' => 30,
                'tenon_thickness' => 10,
                'tenon_length' => 20,
            ],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")
            ->assertOk()
            ->assertJsonCount(5, 'data.generated_operations');

        $this->assertCount(4, $primaryPart->refresh()->operations);
        $this->assertCount(1, $secondaryPart->refresh()->operations);
        $this->assertSame(30.0, (float) $secondaryPart->operations[0]['groove_length']);
        $this->assertSame(10.0, (float) $secondaryPart->operations[0]['width']);
    }

    public function test_butt_connection_is_marked_as_not_requiring_machining(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 3]);
        [$primary, $secondary] = $this->createInstances($project);
        $connection = $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'butt',
            'parameters' => ['primary_face' => 'end', 'secondary_face' => 'start'],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")
            ->assertOk()
            ->assertJsonPath('data.machining_status', 'not_required')
            ->assertJsonCount(0, 'data.generated_operations');

        $this->assertSame(4, $project->refresh()->revision);
        $this->assertSame([], $primary->partDefinition->refresh()->operations);
    }

    public function test_impossible_dowel_layout_returns_422_without_splitting_parts(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        [$primary, $secondary] = $this->createInstances($project);
        $connection = $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'dowel',
            'parameters' => [
                'primary_face' => 'end',
                'secondary_face' => 'start',
                'dowel_diameter' => 100,
                'dowel_count' => 10,
                'dowel_depth' => 25,
            ],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parameters');

        $this->assertSame($primary->part_definition_id, $primary->refresh()->part_definition_id);
        $this->assertSame('pending', $connection->refresh()->machining_status);
        $this->assertSame(1, $project->refresh()->revision);
    }

    public function test_machining_connection_from_another_project_returns_404(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $foreignProject = Project::factory()->for($user)->create();
        [$primary, $secondary] = $this->createInstances($foreignProject);
        $connection = $foreignProject->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'butt',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")
            ->assertNotFound();

        $this->assertSame('pending', $connection->refresh()->machining_status);
    }

    public function test_projects_write_token_can_generate_machining_through_v1_api(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        [$primary, $secondary] = $this->createInstances($project);
        $connection = $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'butt',
        ]);
        Sanctum::actingAs($user, ['projects:write']);

        $this->postJson("/api/v1/projects/{$project->id}/connections/{$connection->id}/machining")
            ->assertOk()
            ->assertJsonPath('data.machining_status', 'not_required');

        $this->assertSame('not_required', $connection->refresh()->machining_status);
    }

    public function test_position_change_marks_machining_outdated_and_regeneration_replaces_old_operations(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 1]);
        $primaryPart = PartDefinition::factory()->for($project)->create(['length' => 600, 'width' => 80, 'thickness' => 40]);
        $secondaryPart = PartDefinition::factory()->for($project)->create(['length' => 500, 'width' => 80, 'thickness' => 40]);
        $primary = PartInstance::factory()->for($project)->for($primaryPart)->create();
        $secondary = PartInstance::factory()->for($project)->for($secondaryPart)->create();
        $connection = $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'dowel',
            'parameters' => [
                'primary_face' => 'end',
                'secondary_face' => 'start',
                'primary_center_u' => 40,
                'primary_center_v' => 20,
                'secondary_center_u' => 40,
                'secondary_center_v' => 20,
                'joint_angle' => 0,
                'dowel_diameter' => 8,
                'dowel_count' => 2,
                'dowel_depth' => 25,
                'dowel_spacing' => 16,
            ],
        ]);
        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")->assertOk();
        $oldOperationIds = collect($connection->refresh()->generated_operation_ids)->pluck('operation_id')->all();

        $this->patchJson("/api/projects/{$project->id}/connections/{$connection->id}", [
            'parameters' => ['primary_center_u' => 30, 'secondary_center_u' => 30],
        ])->assertOk()
            ->assertJsonPath('data.machining_status', 'outdated')
            ->assertJsonPath('data.is_verified', false);

        $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")
            ->assertOk()
            ->assertJsonPath('data.machining_status', 'generated');

        $connection->refresh();
        $newOperationIds = collect($connection->generated_operation_ids)->pluck('operation_id')->all();
        $allOperations = collect($primaryPart->refresh()->operations)->merge($secondaryPart->refresh()->operations);
        $this->assertEmpty(array_intersect($oldOperationIds, $newOperationIds));
        $this->assertEmpty($allOperations->whereIn('id', $oldOperationIds));
        $this->assertSame([22.0, 38.0], collect($primaryPart->operations)->pluck('center_u')->map(fn ($value): float => (float) $value)->all());
        $this->assertSame(4, $project->refresh()->revision);
    }

    public function test_generated_machining_can_be_removed_without_deleting_connection(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 1]);
        $primaryPart = PartDefinition::factory()->for($project)->create(['length' => 600, 'width' => 80, 'thickness' => 40]);
        $secondaryPart = PartDefinition::factory()->for($project)->create(['length' => 500, 'width' => 80, 'thickness' => 40]);
        $primary = PartInstance::factory()->for($project)->for($primaryPart)->create();
        $secondary = PartInstance::factory()->for($project)->for($secondaryPart)->create();
        $connection = $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'half_lap',
            'parameters' => [
                'primary_face' => 'top',
                'secondary_face' => 'bottom',
                'joint_length' => 50,
                'joint_width' => 30,
                'depth_ratio' => 0.5,
            ],
        ]);
        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")->assertOk();

        $this->deleteJson("/api/projects/{$project->id}/connections/{$connection->id}/machining")
            ->assertOk()
            ->assertJsonPath('data.machining_status', 'pending')
            ->assertJsonCount(0, 'data.generated_operations');

        $this->assertSame([], $primaryPart->refresh()->operations);
        $this->assertSame([], $secondaryPart->refresh()->operations);
        $this->assertModelExists($connection);
        $this->assertSame(3, $project->refresh()->revision);
    }

    public function test_update_rejects_unrecognized_connection_parameter(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        [$primary, $secondary] = $this->createInstances($project);
        $connection = $project->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'butt',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/projects/{$project->id}/connections/{$connection->id}", [
            'parameters' => ['unexpected' => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('parameters');

        $this->assertSame('pending', $connection->refresh()->machining_status);
    }

    /** @return array{PartInstance, PartInstance} */
    private function createInstances(Project $project): array
    {
        $part = PartDefinition::factory()->for($project)->create();

        return [
            PartInstance::factory()->for($project)->for($part)->create(),
            PartInstance::factory()->for($project)->for($part)->create(['position_x' => 100]),
        ];
    }
}
