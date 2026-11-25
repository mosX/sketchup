<?php

namespace App\Mcp\Tools;

class FastenerTools
{
    /** @return list<WoodworkingApiTool> */
    public static function all(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $nullableId = ['type' => ['integer', 'null'], 'minimum' => 1];
        $face = ['enum' => ['top', 'bottom', 'left', 'right', 'start', 'end']];
        $project = ['project_id' => $id];
        $fasteners = '/projects/{project_id}/fasteners';
        $ids = ['type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 50, 'uniqueItems' => true];
        $rowTargets = ['type' => 'array', 'items' => $nullableId, 'minItems' => 1, 'maxItems' => 50];
        $transaction = ['dry_run' => ['type' => 'boolean', 'default' => true], 'expected_revision' => $id];
        $millimeters = ['type' => 'number', 'minimum' => 0.1, 'maximum' => 10000];
        $placement = [
            ...$project,
            'source_instance_id' => $id,
            'target_instance_id' => $nullableId,
            'catalog_material_id' => $id,
            'face' => $face,
            'center_u' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
            'center_v' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
            'sink' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
        ];
        $requiredPlacement = ['project_id', 'source_instance_id', 'catalog_material_id', 'face', 'center_u', 'center_v', 'sink'];
        $catalog = [
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'kind' => ['enum' => ['consumable'], 'default' => 'consumable'],
            'price' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 100000000],
            'pack_quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
            'fastener_type' => ['enum' => ['screw', 'nail']],
            'fastener_diameter' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 30],
            'fastener_length' => ['type' => 'number', 'minimum' => 1, 'maximum' => 1000],
        ];
        $requiredCatalog = ['name', 'price', 'pack_quantity', 'fastener_type', 'fastener_diameter', 'fastener_length'];

        return [
            self::tool('list_material_catalog', 'List the account material catalog, including screws and nails. Requires a global key.', 'GET', '/materials', [], []),
            self::tool('create_catalog_fastener', 'Create a screw or nail in the account catalog. Price is per pack in UAH or null. Requires a global write key.',
                'POST', '/materials', $catalog, $requiredCatalog),
            self::tool('update_catalog_fastener', 'Update a catalog fastener and its price per pack. Existing scene placements retain their dimensions and type. Requires a global write key.',
                'PUT', '/materials/{material_id}', [...$catalog, 'material_id' => $id], [...$requiredCatalog, 'material_id']),
            self::tool('list_fasteners', 'List screws and nails already installed in a project. Returns placement, catalog and instance identifiers.',
                'GET', $fasteners, $project, ['project_id']),
            self::tool('place_fasteners', 'Preview or install one fastener or a face-local U/V row. Dry run is the default. Set suggest_targets=true on a preview to inspect geometry and receive proposed target IDs in row order. To commit, send the preview revision, dry_run=false and optional row_target_instance_ids; use either those IDs or target_instance_id.',
                'POST', $fasteners, [
                    ...$placement, ...$transaction,
                    'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    'row_axis' => ['enum' => ['u', 'v']],
                    'spacing' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 100000],
                    'row_target_instance_ids' => $rowTargets,
                    'suggest_targets' => ['type' => 'boolean'],
                ], $requiredPlacement),
            self::tool('update_fastener', 'Update the location, seating or linked detail of one installed fastener. Supply the full placement.',
                'PUT', $fasteners.'/{fastener_id}', [...$placement, 'fastener_id' => $id], [...$requiredPlacement, 'fastener_id']),
            self::tool('delete_fasteners', 'Delete selected installed fasteners. Prepared machining operations are managed separately by woodworking_prepare_fastener_drilling.',
                'DELETE', $fasteners, [...$project, 'ids' => $ids], ['project_id', 'ids']),
            self::tool('copy_fastener_row', 'Preview or copy a row from one face to another, optionally reflecting U or V. Set suggest_targets=true to check machined geometry during preview. Target lists follow ascending original fastener ID, also the returned placement order. Copying preserves drilling settings in the response; use the drilling tool to apply holes separately.',
                'POST', $fasteners.'/copy', [
                    ...$project, ...$transaction, 'fastener_ids' => [...$ids, 'minItems' => 2],
                    'source_instance_id' => $id, 'target_instance_id' => $nullableId, 'row_target_instance_ids' => [...$rowTargets, 'minItems' => 2],
                    'face' => $face, 'mirror_u' => ['type' => 'boolean'], 'mirror_v' => ['type' => 'boolean'],
                    'suggest_targets' => ['type' => 'boolean'],
                ], ['project_id', 'fastener_ids', 'source_instance_id', 'face']),
            self::tool('prepare_fastener_drilling', 'Preview or apply clearance, pilot holes and tapered countersinks for screws. Use the dry-run revision to apply. To undo, pass operation IDs from the result in remove_operation_ids with the same settings. Refresh the project afterwards because parts may have split.',
                'POST', $fasteners.'/drilling', [
                    ...$project, ...$transaction, 'fastener_ids' => $ids, 'clearance_diameter' => $millimeters,
                    'pilot' => ['type' => 'boolean'], 'pilot_diameter' => $millimeters, 'pilot_depth' => $millimeters,
                    'countersink' => ['type' => 'boolean'], 'countersink_diameter' => $millimeters, 'countersink_depth' => $millimeters,
                    'remove_operation_ids' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^fastener-[a-f0-9]{24}-(clearance|pilot|countersink)$'], 'minItems' => 1, 'maxItems' => 150, 'uniqueItems' => true],
                ], ['project_id', 'fastener_ids', 'clearance_diameter', 'pilot']),
            self::tool('analyze_fasteners', 'Check installed fasteners against saved machined geometry. Reports unsupported seating, misses, depth shortfalls and protrusion by source face and assembly group. Unlinked placements are counted separately. approximate=true flags use of stock bounds.',
                'GET', $fasteners.'/analysis', $project, ['project_id']),
        ];
    }

    private static function tool(string $name, string $description, string $method, string $path, array $properties, array $required): WoodworkingApiTool
    {
        return new WoodworkingApiTool('woodworking_'.$name, $description, $method, $path, [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
        ]);
    }
}
