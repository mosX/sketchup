<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WoodworkingMcpTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_mcp_requires_an_api_key(): void
    {
        $this->rpc('tools/list')->assertUnauthorized();
    }

    public function test_mcp_initializes_and_advertises_woodworking_tools(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('MCP', ['projects:read'])->plainTextToken);

        $this->rpc('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ])->assertOk()->assertJsonPath('result.serverInfo.name', 'sketchup-woodworking');
        $this->rpc('tools/list')->assertOk()->assertJsonCount(17, 'result.tools')
            ->assertJsonFragment(['name' => 'woodworking_preview_connection_machining'])
            ->assertJsonFragment(['name' => 'woodworking_apply_commands']);
    }

    public function test_write_key_creates_a_project_through_mcp(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('MCP', ['projects:write'])->plainTextToken);

        $this->callTool('woodworking_create_project', ['name' => 'MCP box'])
            ->assertOk()->assertJsonPath('result.structuredContent.data.name', 'MCP box');

        $this->assertDatabaseHas('projects', ['user_id' => $user->id, 'name' => 'MCP box']);
    }

    public function test_read_key_cannot_create_projects(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('MCP', ['projects:read'])->plainTextToken);

        $this->callTool('woodworking_create_project', ['name' => 'Denied'])
            ->assertOk()->assertJsonPath('result.isError', true);

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_scoped_key_reads_only_its_project_and_cannot_create_projects(): void
    {
        $user = User::factory()->create();
        $allowed = Project::factory()->for($user)->create();
        $other = Project::factory()->for($user)->create();
        $this->withToken($user->createToken('MCP', ['projects:write', "project:{$allowed->id}"])->plainTextToken);

        $this->callTool('woodworking_get_project', ['project_id' => $allowed->id])
            ->assertOk()->assertJsonPath('result.structuredContent.data.id', $allowed->id);
        $denied = $this->callTool('woodworking_get_project', ['project_id' => $other->id])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame(404, json_decode($denied->json('result.content.0.text'), true)['status']);
        $this->callTool('woodworking_create_project', ['name' => 'Denied'])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('projects', 2);
    }

    public function test_command_batch_defaults_to_dry_run(): void
    {
        $project = Project::factory()->create(['revision' => 1]);
        $this->withToken($project->user->createToken('MCP', ['projects:write'])->plainTextToken);

        $this->callTool('woodworking_apply_commands', [
            'project_id' => $project->id,
            'expected_revision' => 1,
            'commands' => [[
                'type' => 'create_part',
                'data' => ['name' => 'Test board', 'length' => 100, 'width' => 40, 'thickness' => 20, 'grain_axis' => 'length'],
            ]],
        ])->assertOk()->assertJsonPath('result.isError', false);

        $this->assertDatabaseCount('part_definitions', 0);
        $this->assertSame(1, $project->fresh()->revision);
    }

    private function callTool(string $name, array $arguments): TestResponse
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    private function rpc(string $method, array $parameters = []): TestResponse
    {
        return $this->postJson('/api/mcp/woodworking', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $parameters,
        ], ['Accept' => 'application/json, text/event-stream']);
    }
}
