<?php

namespace Tests\Feature;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectFastener;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
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
        ])->assertOk()->assertJsonPath('result.serverInfo.name', 'sketchup-woodworking')
            ->assertSee('suggest_targets=true');
        $this->rpc('tools/list')->assertOk()->assertJsonCount(41, 'result.tools')
            ->assertJsonFragment(['name' => 'woodworking_check_assembly_sequence'])
            ->assertJsonFragment(['name' => 'woodworking_check_assembly_geometry'])
            ->assertJsonFragment(['name' => 'woodworking_run_project_script'])
            ->assertJsonFragment(['name' => 'woodworking_export_project'])
            ->assertJsonFragment(['name' => 'woodworking_get_assembly_instructions'])
            ->assertJsonFragment(['name' => 'woodworking_update_assembly_instructions'])
            ->assertJsonFragment(['name' => 'woodworking_create_catalog_material'])
            ->assertJsonFragment(['name' => 'woodworking_update_project_materials'])
            ->assertJsonFragment(['name' => 'woodworking_preview_connection_machining'])
            ->assertJsonFragment(['name' => 'woodworking_place_fasteners'])
            ->assertJsonFragment(['name' => 'woodworking_analyze_fasteners'])
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

    public function test_fastener_tools_preview_commit_copy_and_prepare_drilling(): void
    {
        $project = Project::factory()->create(['revision' => 1]);
        $board = PartDefinition::factory()->for($project)->create(['length' => 200, 'width' => 80, 'thickness' => 20]);
        $source = PartInstance::factory()->for($project)->for($board)->create(['position_z' => 30]);
        $second = PartInstance::factory()->for($project)->for($board)->create(['position_z' => 10]);
        $this->withToken($project->user->createToken('MCP', ['projects:write'])->plainTextToken);

        $materialId = $this->callTool('woodworking_create_catalog_fastener', [
            'name' => 'MCP screw', 'fastener_type' => 'screw', 'fastener_diameter' => 4,
            'fastener_length' => 30, 'price' => 100, 'pack_quantity' => 50,
        ])->assertOk()->assertJsonPath('result.structuredContent.data.fastener_type', 'screw')->json('result.structuredContent.data.id');
        $placement = [
            'project_id' => $project->id, 'source_instance_id' => $source->id, 'catalog_material_id' => $materialId,
            'face' => 'top', 'center_u' => 50, 'center_v' => 40, 'sink' => 0,
            'count' => 2, 'row_axis' => 'u', 'spacing' => 20,
        ];
        $this->callTool('woodworking_place_fasteners', $placement)->assertOk()
            ->assertJsonPath('result.structuredContent.revision', 1)
            ->assertJsonPath('result.structuredContent.data.1.center_u', 70);
        $this->assertDatabaseCount('project_fasteners', 0);
        Process::fake([
            '*' => Process::result(json_encode([['target_instance_id' => $second->id], ['target_instance_id' => $second->id]], JSON_THROW_ON_ERROR)),
        ]);
        $this->callTool('woodworking_place_fasteners', [...$placement, 'suggest_targets' => true])->assertOk()
            ->assertJsonPath('result.structuredContent.target_suggestions.0.target_instance_id', $second->id);
        Process::assertRan(function (PendingProcess $process) use ($source): bool {
            $payload = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);

            return $payload['preview'] === true
                && $payload['fasteners'][0]['source_instance_id'] === $source->id
                && count($payload['parts'][0]['instances']) === 2;
        });
        $this->callTool('woodworking_place_fasteners', [...$placement, 'dry_run' => false])
            ->assertOk()->assertJsonPath('result.isError', true);
        $saved = $this->callTool('woodworking_place_fasteners', [...$placement, 'dry_run' => false, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('result.structuredContent.revision', 2)->json('result.structuredContent.data');
        $ids = array_column($saved, 'id');

        $this->callTool('woodworking_copy_fastener_row', [
            'project_id' => $project->id, 'fastener_ids' => $ids, 'source_instance_id' => $second->id,
            'face' => 'top', 'mirror_u' => true,
        ])->assertOk()->assertJsonPath('result.structuredContent.data.placements.0.center_u', 150);
        $this->assertDatabaseCount('project_fasteners', 2);

        $settings = ['project_id' => $project->id, 'fastener_ids' => $ids, 'clearance_diameter' => 4.5,
            'pilot' => false, 'countersink' => true, 'countersink_diameter' => 8, 'countersink_depth' => 2];
        $this->callTool('woodworking_prepare_fastener_drilling', $settings)->assertOk()
            ->assertJsonPath('result.structuredContent.data.revision', 2)
            ->assertJsonCount(4, 'result.structuredContent.data.created_operation_ids');
        $this->callTool('woodworking_prepare_fastener_drilling', [...$settings, 'dry_run' => false, 'expected_revision' => 2])
            ->assertOk()->assertJsonPath('result.structuredContent.data.revision', 3);
        $this->assertCount(4, $source->fresh()->partDefinition->operations);
    }

    public function test_fastener_mcp_geometry_uses_the_scoped_project_snapshot(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->for($project->user)->create();
        $fastener = ProjectFastener::factory()->create(['project_id' => $project->id]);
        $this->withToken($project->user->createToken('MCP', ['projects:read', "project:{$project->id}"])->plainTextToken);
        Process::fake(['*' => Process::result('{"total":1,"issueCount":0,"unchecked":1,"issues":[]}')]);
        $this->callTool('woodworking_analyze_fasteners', ['project_id' => $other->id])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->callTool('woodworking_analyze_fasteners', ['project_id' => $project->id])
            ->assertOk()->assertJsonPath('result.structuredContent.data.total', 1);
        Process::assertRanTimes(function (PendingProcess $process) use ($fastener): bool {
            $payload = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);

            return $payload['preview'] === false
                && array_column($payload['fasteners'], 'id') === [$fastener->id];
        }, 1);
        $this->callTool('woodworking_list_material_catalog', [])
            ->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_material_tools_edit_catalog_entries_and_apply_a_revision_checked_project_plan(): void
    {
        $project = Project::factory()->create(['revision' => 1]);
        $part = PartDefinition::factory()->for($project)->create();
        $this->withToken($project->user->createToken('MCP', ['projects:write'])->plainTextToken);
        $board = ['name' => 'Pine', 'kind' => 'board', 'length_mm' => 5000, 'width_mm' => 100, 'thickness_mm' => 30, 'price' => 300];
        $stock = $this->callTool('woodworking_create_catalog_material', $board)->assertOk()
            ->assertJsonPath('result.structuredContent.data.kind', 'board')->json('result.structuredContent.data.id');
        $this->callTool('woodworking_update_catalog_material', [...$board, 'material_id' => $stock, 'price' => 350])
            ->assertOk()->assertJsonPath('result.structuredContent.data.price', '350.00');
        $glue = $this->callTool('woodworking_create_catalog_material', [
            'name' => 'Glue', 'kind' => 'consumable', 'unit' => 'ml', 'pack_quantity' => 250, 'price' => 85.50,
        ])->assertOk()->json('result.structuredContent.data.id');

        $plan = [
            'project_id' => $project->id, 'default_material_id' => $stock,
            'assignments' => [['part_id' => $part->id, 'material_id' => $stock]],
            'supplies' => [['material_id' => $glue, 'quantity' => 30.5]],
        ];
        $this->callTool('woodworking_update_project_materials', $plan)
            ->assertOk()->assertJsonPath('result.structuredContent.revision', 1)
            ->assertJsonPath('result.structuredContent.data.supplies.0.quantity', 30.5);
        $this->assertNull($project->fresh()->material_plan);
        $this->callTool('woodworking_update_project_materials', [...$plan, 'dry_run' => false, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('result.structuredContent.revision', 2);
        $this->callTool('woodworking_update_project_materials', [...$plan, 'supplies' => [], 'dry_run' => false, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->callTool('woodworking_get_project_materials', ['project_id' => $project->id])
            ->assertOk()->assertJsonPath('result.structuredContent.revision', 2)
            ->assertJsonPath('result.structuredContent.data.assignments.0.part_id', $part->id)
            ->assertJsonPath('result.structuredContent.data.supplies.0.quantity', 30.5);
    }

    public function test_assembly_instructions_can_be_previewed_saved_and_read_through_mcp(): void
    {
        $project = Project::factory()->create(['revision' => 1]);
        $this->withToken($project->user->createToken('MCP', ['projects:write', "project:{$project->id}"])->plainTextToken);
        $instruction = [
            'project_id' => $project->id,
            'steps' => [['title' => 'Check squareness', 'notes' => 'Compare both diagonals.', 'instance_ids' => []]],
        ];

        $this->callTool('woodworking_update_assembly_instructions', $instruction)
            ->assertOk()->assertJsonPath('result.structuredContent.dry_run', true);
        $this->assertNull($project->fresh()->assembly_instructions);
        $this->callTool('woodworking_update_assembly_instructions', [...$instruction, 'expected_revision' => 1, 'dry_run' => false])
            ->assertOk()->assertJsonPath('result.structuredContent.revision', 2);
        $this->callTool('woodworking_get_assembly_instructions', ['project_id' => $project->id])
            ->assertOk()->assertJsonPath('result.structuredContent.data.0.notes', 'Compare both diagonals.');
    }

    public function test_script_payload_is_previewed_and_committed_through_mcp(): void
    {
        $project = Project::factory()->create();
        $this->withToken($project->user->createToken('MCP', ['projects:write', "project:{$project->id}"])->plainTextToken);
        $api = $this->callTool('woodworking_get_script_api', [])
            ->assertOk()->assertJsonPath('result.structuredContent.data.entry_point', 'compileProjectScriptPayload(source, parameterValues)');
        $this->assertStringContainsString('export function compileProjectScriptPayload', $api->json('result.structuredContent.data.javascript'));
        $payload = [
            'project_id' => $project->id, 'source' => 'assemblyStep("Check");', 'expected_revision' => 1,
            'commands' => [], 'assembly_steps' => [['title' => 'Check', 'instance_refs' => []]],
        ];
        $this->callTool('woodworking_run_project_script', $payload)
            ->assertOk()->assertJsonPath('result.structuredContent.data.committed', false);
        $this->assertNull($project->fresh()->assembly_instructions);
        $this->callTool('woodworking_run_project_script', [...$payload, 'dry_run' => false])
            ->assertOk()->assertJsonPath('result.structuredContent.data.revision', 2);
        $this->callTool('woodworking_get_project_script', ['project_id' => $project->id])
            ->assertOk()->assertJsonPath('result.structuredContent.data.has_result', true);
    }

    public function test_exports_enforce_scope_pin_revisions_and_include_preview_image(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->for($project->user)->create();
        $part = PartDefinition::factory()->for($project)->create();
        PartInstance::factory()->for($part)->create();
        $this->withToken($project->user->createToken('MCP', ['projects:read', "project:{$project->id}"])->plainTextToken);

        $png = "\x89PNG\r\n\x1a\npreview\n";
        Process::fake([
            '*' => fn (PendingProcess $process) => Process::result(
                json_decode($process->input, true)['format'] === 'png' ? $png : 'glTFbinary',
            ),
        ]);
        $export = $this->callTool('woodworking_export_project', ['project_id' => $project->id, 'format' => 'glb'])
            ->assertOk()->assertJsonPath('result.structuredContent.mime_type', 'model/gltf-binary')
            ->assertJsonPath('result.structuredContent.revision', 1);
        $url = $export->json('result.structuredContent.download_url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'model/gltf-binary')->assertContent("glTFbinary\n");
        $this->rpc('resources/read', ['uri' => $export->json('result.structuredContent.resource_uri')])
            ->assertOk()->assertJsonPath('result.contents.0.blob', base64_encode("glTFbinary\n"))
            ->assertJsonPath('result.contents.0.mimeType', 'model/gltf-binary');
        $this->callTool('woodworking_export_project', ['project_id' => $project->id, 'format' => 'png', 'view' => 'top'])
            ->assertOk()->assertJsonPath('result.content.0.type', 'image')
            ->assertJsonPath('result.content.0.data', base64_encode($png))
            ->assertJsonPath('result.structuredContent.revision', 1);
        $this->callTool('woodworking_export_project', ['project_id' => $other->id, 'format' => 'obj'])
            ->assertOk()->assertJsonPath('result.isError', true);
        $project->increment('revision');
        $this->getJson($url)->assertConflict();
    }

    public function test_assembly_geometry_tool_reads_the_revision_and_preserves_project_scope(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->for($project->user)->create();
        $this->withToken($project->user->createToken('Geometry', ['projects:read', "project:{$project->id}"])->plainTextToken);
        Process::fake(['*' => Process::result('{"intersections":{"status":"complete","issue_count":0},"fasteners":{"total":0}}')]);

        $this->callTool('woodworking_check_assembly_geometry', ['project_id' => $project->id, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('result.structuredContent.data.revision', 1)
            ->assertJsonPath('result.structuredContent.data.intersections.status', 'complete');
        $this->callTool('woodworking_check_assembly_geometry', ['project_id' => $other->id])
            ->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_assembly_sequence_check_is_available_to_a_scoped_read_key(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->for($project->user)->create();
        $this->withToken($project->user->createToken('Sequence', ['projects:read', "project:{$project->id}"])->plainTextToken);
        Process::fake(['*' => Process::result('{"status":"no_steps","step_count":0,"issue_count":0,"issues":[]}')]);

        $this->callTool('woodworking_check_assembly_sequence', ['project_id' => $project->id, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('result.structuredContent.data.status', 'no_steps')
            ->assertJsonPath('result.structuredContent.data.revision', 1);
        $this->callTool('woodworking_check_assembly_sequence', ['project_id' => $other->id])
            ->assertOk()->assertJsonPath('result.isError', true);
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
