<?php

namespace Tests\Feature;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectScriptControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preview_returns_geometry_without_persisting_script_or_parts(): void
    {
        $project = $this->ownedProject();
        $this->postJson("/api/projects/{$project->id}/script/run", [...$this->payload(), 'dry_run' => true])
            ->assertOk()->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.parts.0.name', 'Panel')
            ->assertJsonPath('data.parts.0.instances.0.position.z', 12);
        $this->assertDatabaseCount('part_definitions', 0);
        $this->assertDatabaseCount('assembly_groups', 0);
        $this->assertNull($project->fresh()->script_data);
        $this->assertSame(1, $project->fresh()->revision);
    }

    public function test_apply_replace_and_undo_preserve_unrelated_parts(): void
    {
        $project = $this->ownedProject();
        $existing = PartDefinition::factory()->for($project)->create();
        $first = $this->postJson("/api/projects/{$project->id}/script/run", $this->payload())
            ->assertOk()->assertJsonPath('data.revision', 2)->json('data');
        $second = $this->postJson("/api/projects/{$project->id}/script/run", [...$this->payload(), 'expected_revision' => 2])
            ->assertOk()->assertJsonPath('data.revision', 3)->json('data');
        $this->assertDatabaseCount('part_definitions', 2);
        $this->assertDatabaseCount('part_instances', 1);
        $this->assertDatabaseCount('assembly_groups', 1);
        $this->assertModelExists($existing);
        $this->assertNotSame($first['root_group_id'], $second['root_group_id']);

        $this->postJson("/api/projects/{$project->id}/script/run", [...$first['previous'], 'expected_revision' => 3])
            ->assertOk()->assertJsonPath('data.root_group_id', null);
        $this->assertDatabaseCount('part_definitions', 1);
        $this->assertDatabaseCount('part_instances', 0);
        $this->assertDatabaseCount('assembly_groups', 0);
    }

    public function test_saving_source_does_not_change_generated_geometry(): void
    {
        $project = $this->ownedProject();
        $this->putJson("/api/projects/{$project->id}/script", ['source' => 'const length = 800;', 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->getJson("/api/projects/{$project->id}/script")->assertOk()->assertJsonPath('data.source', 'const length = 800;');
        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_invalid_reference_rolls_back_replacement_and_returns_422(): void
    {
        $project = $this->ownedProject();
        $this->postJson("/api/projects/{$project->id}/script/run", $this->payload())->assertOk();
        $before = $project->fresh()->script_data;
        $payload = $this->payload();
        $payload['commands'][1]['part_ref'] = 'missing';
        $this->postJson("/api/projects/{$project->id}/script/run", [...$payload, 'expected_revision' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('commands.2.part_ref');
        $this->assertSame($before, $project->fresh()->script_data);
        $this->assertSame(2, $project->fresh()->revision);
        $this->assertDatabaseCount('part_instances', 1);
    }

    public function test_manual_changes_block_replacement_until_detached(): void
    {
        $project = $this->ownedProject();
        $this->postJson("/api/projects/{$project->id}/script/run", $this->payload())->assertOk();
        $part = $project->partDefinitions()->firstOrFail();
        $part->update(['name' => 'Manually edited']);
        $this->postJson("/api/projects/{$project->id}/script/run", [...$this->payload(), 'expected_revision' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('commands');
        $this->postJson("/api/projects/{$project->id}/script/detach", ['source' => 'saved', 'expected_revision' => 2])
            ->assertOk()->assertJsonPath('data.has_result', false);
        $this->postJson("/api/projects/{$project->id}/script/run", [...$this->payload(), 'expected_revision' => 3])->assertOk();
        $this->assertModelExists($part);
        $this->assertSame('Manually edited', $part->fresh()->name);
        $this->assertDatabaseCount('part_definitions', 2);
    }

    public function test_external_instances_block_replacement_with_422(): void
    {
        $project = $this->ownedProject();
        $this->postJson("/api/projects/{$project->id}/script/run", $this->payload())->assertOk();
        $extra = PartInstance::factory()->for($project)->for($project->partDefinitions()->firstOrFail())->create();
        $this->postJson("/api/projects/{$project->id}/script/run", [...$this->payload(), 'expected_revision' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('commands');
        $this->assertModelExists($extra);
        $this->assertDatabaseCount('part_instances', 2);
    }

    public function test_stale_revision_returns_409_without_changes(): void
    {
        $project = $this->ownedProject();
        $project->increment('revision');
        $this->postJson("/api/projects/{$project->id}/script/run", $this->payload())->assertConflict();
        $this->putJson("/api/projects/{$project->id}/script", ['source' => 'old', 'expected_revision' => 1])->assertConflict();
        $this->assertDatabaseCount('part_definitions', 0);
        $this->assertNull($project->fresh()->script_data);
    }

    public function test_authentication_and_ownership_are_required(): void
    {
        $project = Project::factory()->create();
        $this->getJson("/api/projects/{$project->id}/script")->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(), ['projects:write']);
        $this->getJson("/api/projects/{$project->id}/script")->assertNotFound();
        $this->postJson("/api/projects/{$project->id}/script/run", $this->payload())->assertForbidden();
        $this->putJson("/api/projects/{$project->id}/script", ['source' => 'x', 'expected_revision' => 1])->assertForbidden();
        $this->postJson("/api/projects/{$project->id}/script/detach", ['source' => 'x', 'expected_revision' => 1])->assertForbidden();
    }

    public function test_read_only_token_cannot_save_or_apply_scripts(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user, ['projects:read']);
        $this->getJson("/api/projects/{$project->id}/script")->assertOk();
        $this->postJson("/api/projects/{$project->id}/script/run", $this->payload())->assertForbidden();
        $this->putJson("/api/projects/{$project->id}/script", ['source' => 'x', 'expected_revision' => 1])->assertForbidden();
    }

    public function test_script_cannot_target_existing_records_or_delete_with_422(): void
    {
        $project = $this->ownedProject();
        $payload = $this->payload();
        $payload['commands'][1]['part_id'] = 1;
        $this->postJson("/api/projects/{$project->id}/script/run", $payload)->assertUnprocessable()->assertJsonValidationErrors('commands.1.part_id');
        $payload['commands'] = [['type' => 'delete_group', 'temporary_id' => 'delete', 'group_id' => 1]];
        $this->postJson("/api/projects/{$project->id}/script/run", $payload)->assertUnprocessable()->assertJsonValidationErrors('commands.0.type');
        $this->assertDatabaseCount('part_definitions', 0);
    }

    public function test_invalid_and_malformed_operations_return_422(): void
    {
        $project = $this->ownedProject();
        $payload = $this->payload();
        $payload['commands'][0]['data']['operations'][0]['depth'] = 1000;
        $this->postJson("/api/projects/{$project->id}/script/run", $payload)->assertUnprocessable()->assertJsonValidationErrors('commands.0.data.operations.0.depth');
        $payload['commands'][0]['data']['operations'] = 'invalid';
        $this->postJson("/api/projects/{$project->id}/script/run", $payload)->assertUnprocessable()->assertJsonValidationErrors('commands.0.data.operations');
        $payload['commands'] = 'invalid';
        $this->postJson("/api/projects/{$project->id}/script/run", $payload)->assertUnprocessable()->assertJsonValidationErrors('commands');
        $this->assertDatabaseCount('part_definitions', 0);
    }

    private function ownedProject(): Project
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user, ['projects:write']);

        return $project;
    }

    public function test_generated_connections_replace_and_undo_in_one_revision(): void
    {
        $project = $this->ownedProject();
        $payload = $this->payload();
        $payload['commands'][0]['data']['operations'] = [];
        $payload['commands'][] = ['type' => 'create_instance', 'temporary_id' => 'second', 'part_ref' => 'panel', 'data' => ['position_x' => 380, 'position_z' => 12]];
        $payload['connections'] = [['primary_ref' => 'instance', 'secondary_ref' => 'second', 'type' => 'mortise_tenon', 'parameters' => ['tenon_width' => 30, 'tenon_thickness' => 10, 'tenon_length' => 20], 'generate_machining' => true]];
        $payload['parameter_values'] = ['length' => 400];
        $this->postJson("/api/projects/{$project->id}/script/run", [...$payload, 'dry_run' => true])->assertOk()->assertJsonPath('data.connections.0.machining_status', 'generated');
        $this->assertDatabaseCount('project_connections', 0);
        $first = $this->postJson("/api/projects/{$project->id}/script/run", $payload)->assertOk()->assertJsonPath('data.revision', 2)->json('data');
        $this->assertSame(2, $project->fresh()->revision);
        $this->postJson("/api/projects/{$project->id}/script/run", [...$payload, 'expected_revision' => 2])->assertOk();
        $this->assertDatabaseCount('project_connections', 1);
        $this->assertDatabaseCount('part_definitions', 2);
        $this->assertSame(['length' => 400], $project->fresh()->script_data['parameter_values']);
        $this->postJson("/api/projects/{$project->id}/script/run", [...$first['previous'], 'expected_revision' => 3])->assertOk();
        $this->assertDatabaseCount('part_definitions', 0);
        $this->assertDatabaseCount('project_connections', 0);
    }

    public function test_misaligned_script_connection_rolls_back_with_422(): void
    {
        $project = $this->ownedProject();
        $payload = $this->payload();
        $payload['commands'][] = ['type' => 'create_instance', 'temporary_id' => 'second', 'part_ref' => 'panel', 'data' => ['position_x' => 2000]];
        $payload['connections'] = [['primary_ref' => 'instance', 'secondary_ref' => 'second', 'type' => 'dowel', 'generate_machining' => true]];
        $this->postJson("/api/projects/{$project->id}/script/run", $payload)->assertUnprocessable()->assertJsonValidationErrors('connections.0');
        $this->assertDatabaseCount('part_definitions', 0);
        $this->assertSame(1, $project->fresh()->revision);
    }

    public function test_restoring_a_version_preserves_script_ownership_and_replaceability(): void
    {
        $project = $this->ownedProject();
        $this->postJson("/api/projects/{$project->id}/script/run", $this->payload())->assertOk();
        $version = $this->postJson("/api/projects/{$project->id}/versions", ['expected_revision' => 2])->assertCreated()->json('data.id');
        $this->postJson("/api/projects/{$project->id}/script/run", [...$this->payload(), 'expected_revision' => 2])->assertOk();
        $this->postJson("/api/projects/{$project->id}/versions/{$version}/restore", ['expected_revision' => 3])->assertOk();
        $this->postJson("/api/projects/{$project->id}/script/run", [...$this->payload(), 'expected_revision' => 4])->assertOk();
        $this->assertDatabaseCount('part_definitions', 1);
        $this->assertDatabaseCount('part_instances', 1);
        $this->assertDatabaseCount('assembly_groups', 1);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['source' => 'const panel = part("Panel", { length: 400, width: 200, thickness: 24 });', 'expected_revision' => 1, 'commands' => [
            ['type' => 'create_part', 'temporary_id' => 'panel', 'data' => [
                'name' => 'Panel', 'length' => 400, 'width' => 200, 'thickness' => 24, 'grain_axis' => 'length',
                'operations' => [['id' => 'hole', 'type' => 'drill', 'status' => 'applied', 'face' => 'top', 'center_u' => 100, 'center_v' => 60, 'diameter' => 10, 'depth' => 24, 'through' => true]],
            ]],
            ['type' => 'create_instance', 'temporary_id' => 'instance', 'part_ref' => 'panel', 'data' => ['position_z' => 12]],
        ]];
    }
}
