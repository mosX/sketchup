<?php

namespace App\Mcp\Tools;

class AssemblyInstructionTools
{
    /** @return list<WoodworkingApiTool> */
    public static function all(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $ids = ['type' => 'array', 'items' => $id, 'maxItems' => 1000];
        $object = fn (array $properties, array $required): array => [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
        ];
        $coordinate = ['type' => 'number', 'minimum' => -10000000, 'maximum' => 10000000];
        $point = $object(array_fill_keys(['x', 'y', 'z'], $coordinate), ['x', 'y', 'z']);
        $camera = $object(['position' => $point, 'target' => $point], ['position', 'target']);
        $comment = ['type' => ['string', 'null'], 'maxLength' => 5000];
        $step = $object([
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'instruction' => $comment,
            'notes' => $comment,
            'instance_ids' => [...$ids, 'description' => 'Project instance IDs added or handled by this step. Empty for an instruction-only step.'],
            'highlight_instance_ids' => [...$ids, 'type' => ['array', 'null'], 'description' => 'Highlight these project instances. Null highlights all current-step parts; an empty array highlights none.'],
            'fastener_ids' => [...$ids, 'type' => ['array', 'null'], 'description' => 'Installed project fastener IDs to use. Null assigns fasteners automatically when their linked parts are present; an empty array lists no fasteners.'],
            'camera' => [...$camera, 'type' => ['object', 'null'], 'description' => 'Camera position and target in project world coordinates, millimeters and Z-up. Null frames the current step automatically.'],
        ], ['title', 'instance_ids']);
        $path = '/projects/{project_id}/assembly-instructions';

        return [
            new WoodworkingApiTool(
                'woodworking_check_assembly_sequence',
                'Check saved instructions for fasteners scheduled before their parts, linked hardware omitted from all steps, missing instances and stale IDs. The same automatic fastener scheduling is used by the editor. Step indexes are zero-based.',
                'GET', $path.'/check', $object(['project_id' => $id, 'expected_revision' => $id], ['project_id']),
            ),
            new WoodworkingApiTool(
                'woodworking_get_assembly_instructions',
                'Read saved assembly steps, a starter sequence based on the current groups, and the revision. Use woodworking_get_project for part instance and installed fastener IDs.',
                'GET', $path, $object(['project_id' => $id], ['project_id']),
            ),
            new WoodworkingApiTool(
                'woodworking_update_assembly_instructions',
                'Preview or replace the entire ordered assembly instruction. Read the current steps first and keep every step you want to retain. Insert, remove, split, combine or reorder steps in the array. An empty array clears the saved sequence. Defaults to preview; use dry_run=false and the returned revision to commit.',
                'PUT', $path, $object([
                    'project_id' => $id,
                    'steps' => ['type' => 'array', 'maxItems' => 200, 'items' => $step],
                    'expected_revision' => $id,
                    'dry_run' => ['type' => 'boolean', 'default' => true],
                ], ['project_id', 'steps']),
            ),
        ];
    }
}
