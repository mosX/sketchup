<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTokenControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_creates_project_scoped_api_key_and_receives_plain_token_once(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/api-tokens', [
            'name' => 'Workshop agent',
            'abilities' => ['projects:read', 'projects:write'],
            'project_id' => $project->id,
            'expires_in_days' => 14,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Workshop agent')
            ->assertJsonPath('data.abilities.2', "project:{$project->id}")
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Workshop agent',
        ]);
        $this->assertNotSame('', $response->json('token'));
    }

    public function test_agent_key_cannot_create_more_api_keys(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['projects:write']);

        $this->postJson('/api/v1/api-tokens', [
            'name' => 'Nested key',
            'abilities' => ['projects:write'],
        ])->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_owner_creates_global_api_key_that_can_access_and_create_projects(): void
    {
        $user = User::factory()->create();
        $existingProject = Project::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/api-tokens', [
            'name' => 'Global workshop agent',
            'abilities' => ['projects:read', 'projects:write'],
            'expires_in_days' => 30,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.abilities', ['projects:read', 'projects:write'])
            ->assertJsonMissingPath('data.project_id')
            ->assertJsonStructure(['token']);

        $plainTextToken = $response->json('token');

        $this->withToken($plainTextToken)
            ->getJson("/api/v1/projects/{$existingProject->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $existingProject->id);

        $this->withToken($plainTextToken)
            ->postJson('/api/v1/projects', ['name' => 'Created by global agent'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Created by global agent');

        $this->assertDatabaseHas('projects', [
            'user_id' => $user->id,
            'name' => 'Created by global agent',
        ]);
    }

    public function test_project_scoped_key_returns_404_for_another_owned_project(): void
    {
        $user = User::factory()->create();
        $allowedProject = Project::factory()->for($user)->create();
        $otherProject = Project::factory()->for($user)->create();
        $token = $user->createToken('Scoped agent', ['projects:read', "project:{$allowedProject->id}"])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/projects/{$otherProject->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->getJson("/api/v1/projects/{$allowedProject->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $allowedProject->id);

        $this->withToken($token)
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $allowedProject->id);

        $this->withToken($token)
            ->postJson('/api/v1/projects', ['name' => 'Out of scope'])
            ->assertForbidden();

        $this->withToken($token)
            ->getJson("/api/projects/{$otherProject->id}")
            ->assertNotFound();
    }
}
