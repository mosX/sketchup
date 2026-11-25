<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CapabilityControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_capabilities_describe_all_supported_woodworking_operations(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['projects:read']);

        $response = $this->getJson('/api/v1/capabilities');

        $response
            ->assertOk()
            ->assertJsonPath('data.schema_version', 24)
            ->assertJsonPath('data.assembly_instructions.sequence_check', '/api/v1/projects/{project}/assembly-instructions/check')
            ->assertJsonPath('data.assembly_check.tool', 'woodworking_check_assembly_geometry')
            ->assertJsonPath('data.project_export.formats', ['obj', 'glb', 'png'])
            ->assertJsonPath('data.assembly_instructions.camera.up_axis', 'z')
            ->assertJsonPath('data.materials.project_plan.2', 'supplies')
            ->assertJsonPath('data.fasteners.geometry_analysis', true)
            ->assertJsonPath('data.fasteners.types.1', 'nail')
            ->assertJsonPath('data.fasteners.copy.4', 'mirror_u')
            ->assertJsonPath('data.part_operations.groove.1', 'center_u')
            ->assertJsonPath('data.part_operations.edge_roundover.1', 'radius')
            ->assertJsonPath('data.part_operations.plunge_route.7', 'cutter_profile')
            ->assertJsonPath('data.part_operations.drill.3', 'diameter')
            ->assertJsonPath('data.part_operations.drill.5', 'through')
            ->assertJsonPath('data.part_operation_optional_fields.drill.0', 'bottom_diameter')
            ->assertJsonPath('data.fasteners.drilling.2', 'countersink')
            ->assertJsonPath('data.assembly_groups.supports_nested_groups', true)
            ->assertJsonPath('data.commands.4', 'create_group')
            ->assertJsonPath('data.project_analysis.manufacturing.2', 'sheet_cutting')
            ->assertJsonPath('data.project_analysis.cutting_settings.0', 'kerf_mm')
            ->assertJsonPath('data.project_templates.splits_part_variants_for_non_uniform_scaling', true)
            ->assertJsonPath('data.connections.types.2', 'mortise_tenon')
            ->assertJsonPath('data.connections.supports_machining_generation', true)
            ->assertJsonPath('data.connections.supports_machining_regeneration', true)
            ->assertJsonPath('data.part_operation_values.cutter_profile.1', 'dovetail')
            ->assertJsonPath('data.part_operation_values.face.5', 'end');
    }
}
