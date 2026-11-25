<?php

namespace App\Mcp\Tools;

class ScriptTools
{
    /** @return list<WoodworkingApiTool> */
    public static function all(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $text = ['type' => ['string', 'null'], 'maxLength' => 50000];
        $payload = [
            'project_id' => $id,
            'source' => $text,
            'expected_revision' => $id,
            'parameter_values' => ['type' => 'object', 'maxProperties' => 30, 'additionalProperties' => ['type' => 'number']],
        ];
        $description = 'Use woodworking_get_script_api to generate matching payloads locally.';
        $type = ['type' => 'object'];
        $list = fn (int $max): array => ['type' => 'array', 'maxItems' => $max, 'items' => $type];
        $route = '/projects/{project_id}/script';
        $tool = fn (string $name, string $description, string $method, string $path, array $properties, array $required): WoodworkingApiTool => new WoodworkingApiTool(
            'woodworking_'.$name, $description, $method, $path,
            ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
        );
        $required = ['project_id', 'source', 'expected_revision'];

        return [
            $tool('get_script_api', 'Get a standalone JavaScript ES module with compileProjectScriptPayload and runnable examples. Add project_id and expected_revision to the compiled payload before submitting it.', 'GET', '/script-api', [], []),
            $tool('get_project_script', 'Read the project script source, parameter values, render preset and revision.', 'GET', $route, ['project_id' => $id], ['project_id']),
            $tool('save_project_script', 'Save editable script source and parameters.', 'PUT', $route, $payload, $required),
            $tool('run_project_script', 'Preview or apply a compiled script atomically. '.$description.' Submit source separately so it remains editable. Use the revision from the successful dry run to apply. Reference strings identify generated parts, instances and fastener rows. Preview IDs are provisional.', 'POST', $route.'/run', [
                ...$payload,
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'commands' => [...$list(99), 'description' => 'Ordered create_part, create_group and create_instance commands.'],
                'connections' => [...$list(30), 'description' => 'primary_ref, secondary_ref, type, parameters and optional generate_machining.'],
                'fasteners' => [...$list(100), 'description' => 'ref, source_ref, target_ref or row_target_refs, placement (including catalog_material_id) and optional drilling.'],
                'materials' => [...$type, 'description' => 'default_material_id, assignments [{part_ref, material_id}], supplies [{material_id, quantity}].'],
                'assembly_steps' => [...$list(200), 'description' => 'title, instruction, notes, instance_refs, highlight_refs, fastener_refs and camera.'],
                'render_settings' => [...$type, 'description' => 'wood, finish, light, exposure, keyPower, fillPower, azimuth, elevation.'],
            ], [...$required, 'commands']),
            $tool('detach_project_script', 'Keep the current generated project data and detach it from automatic replacement. Supply the source and parameters to keep in the editor.', 'POST', $route.'/detach', $payload, $required),
        ];
    }
}
