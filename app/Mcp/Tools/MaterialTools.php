<?php

namespace App\Mcp\Tools;

class MaterialTools
{
    /** @return list<WoodworkingApiTool> */
    public static function all(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $dimension = ['type' => 'number', 'minimum' => 0.01, 'maximum' => 50000];
        $catalog = [
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'kind' => ['enum' => ['board', 'sheet', 'consumable']],
            'length_mm' => $dimension,
            'width_mm' => $dimension,
            'thickness_mm' => $dimension,
            'unit' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 24],
            'pack_quantity' => ['type' => 'number', 'minimum' => 0.001, 'maximum' => 1000000],
            'price' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 100000000],
            'fastener_type' => ['enum' => ['screw', 'nail', null]],
            'fastener_diameter' => ['type' => ['number', 'null'], 'minimum' => 0.5, 'maximum' => 30],
            'fastener_length' => ['type' => ['number', 'null'], 'minimum' => 1, 'maximum' => 1000],
        ];
        $catalogFields = ['name', 'kind', 'price'];
        $project = ['project_id' => $id];
        $path = '/projects/{project_id}/materials';
        $tool = fn (string $name, string $description, string $method, string $path, array $properties, array $required): WoodworkingApiTool => new WoodworkingApiTool(
            'woodworking_'.$name, $description, $method, $path,
            ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
        );
        $row = fn (array $properties): array => [
            'type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false,
        ];

        return [
            $tool('create_catalog_material', 'Add a board, sheet or consumable to the account catalog. Requires a global write key. For boards and sheets supply all three dimensions in mm; price is per piece in UAH. For consumables supply unit and pack_quantity; price is per pack. Pass null for an unknown price.',
                'POST', '/materials', $catalog, $catalogFields),
            $tool('update_catalog_material', 'Edit an existing account catalog entry using its ID from woodworking_list_material_catalog. Keep the existing kind and send all fields for that kind, including price. Requires a global write key.',
                'PUT', '/materials/{material_id}', ['material_id' => $id, ...$catalog], ['material_id', ...$catalogFields]),
            $tool('get_project_materials', 'Read the default stock, part-specific stock assignments, manual consumables and current revision. A null plan means the project has no explicit material selections.',
                'GET', $path, $project, ['project_id']),
            $tool('update_project_materials', 'Preview or replace the full material plan. Read the current plan first and retain entries you want to keep. Use part definition IDs for assignments, board or sheet IDs for stock and consumable IDs for supplies. Null clears the default; an empty list clears its section. Defaults to dry run. Commit with the returned revision.',
                'PUT', $path, [
                    ...$project,
                    'default_material_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'assignments' => ['type' => 'array', 'maxItems' => 10000, 'items' => $row(['part_id' => $id, 'material_id' => $id])],
                    'supplies' => ['type' => 'array', 'maxItems' => 500, 'items' => $row([
                        'material_id' => $id, 'quantity' => ['type' => 'number', 'minimum' => 0.001, 'maximum' => 1000000],
                    ])],
                    'dry_run' => ['type' => 'boolean', 'default' => true],
                    'expected_revision' => $id,
                ], ['project_id', 'default_material_id', 'assignments', 'supplies']),
        ];
    }
}
