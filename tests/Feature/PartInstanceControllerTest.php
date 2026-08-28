<?php

namespace Tests\Feature;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartInstanceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_quantity_creates_linked_instances_and_returns_201(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $partDefinition = PartDefinition::factory()->for($project)->create(['width' => 60]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/parts/{$partDefinition->id}/instances", [
            'quantity' => 4,
            'position_x' => 100,
            'position_z' => 50,
        ]);

        $response
            ->assertCreated()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.position.z', 50)
            ->assertJsonPath('data.1.position.y', 160)
            ->assertJsonPath('data.1.position.z', 50)
            ->assertJsonPath('data.3.part_definition_id', $partDefinition->id);

        $this->assertDatabaseCount('part_instances', 4);
    }

    public function test_owner_can_update_instance_transform(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $partDefinition = PartDefinition::factory()->for($project)->create();
        $partInstance = PartInstance::factory()->for($project)->for($partDefinition)->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/projects/{$project->id}/instances/{$partInstance->id}", [
            'position_x' => 450,
            'rotation_z' => 12,
            'mirrored' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.position.x', 450)
            ->assertJsonPath('data.rotation.z', 12)
            ->assertJsonPath('data.mirrored', true);

        $this->assertDatabaseHas('part_instances', [
            'id' => $partInstance->id,
            'position_x' => 450,
            'mirrored' => true,
        ]);
    }

    public function test_instance_of_another_project_returns_404(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $foreignInstance = PartInstance::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/projects/{$project->id}/instances/{$foreignInstance->id}", [
            'position_x' => 100,
        ])->assertNotFound();
    }

    public function test_owner_can_delete_instance(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $partDefinition = PartDefinition::factory()->for($project)->create();
        $partInstance = PartInstance::factory()->for($project)->for($partDefinition)->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/projects/{$project->id}/instances/{$partInstance->id}");

        $response->assertNoContent();
        $this->assertModelMissing($partInstance);
    }
}
