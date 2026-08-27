<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
    }

    public function test_index_returns_only_projects_owned_by_user(): void
    {
        $user = User::factory()->create();
        $ownedProject = Project::factory()->for($user)->create(['name' => 'Мой стол']);
        Project::factory()->create(['name' => 'Чужой шкаф']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/projects');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedProject->id)
            ->assertJsonMissing(['name' => 'Чужой шкаф']);
    }

    public function test_valid_payload_creates_project_and_returns_201(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/projects', [
            'name' => 'Книжный шкаф',
            'description' => 'Берёзовая фанера 18 мм',
            'user_id' => User::factory()->create()->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Книжный шкаф');

        $this->assertDatabaseHas('projects', [
            'user_id' => $user->id,
            'name' => 'Книжный шкаф',
        ]);
    }

    public function test_invalid_payload_does_not_create_project(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/projects', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_project_of_another_user_returns_404(): void
    {
        $user = User::factory()->create();
        $foreignProject = Project::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/projects/{$foreignProject->id}")->assertNotFound();
    }

    public function test_owner_can_update_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['name' => 'Черновик']);
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/projects/{$project->id}", [
            'name' => 'Обеденный стол',
            'scene_data' => ['version' => 1, 'objects' => []],
        ]);

        $response->assertOk()->assertJsonPath('data.name', 'Обеденный стол');
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Обеденный стол']);
    }

    public function test_owner_can_delete_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/projects/{$project->id}");

        $response->assertNoContent();
        $this->assertModelMissing($project);
    }
}
