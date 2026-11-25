<?php

namespace App\Mcp\Servers;

use App\Mcp\ProjectExportResource;
use App\Mcp\Tools\AssemblyInstructionTools;
use App\Mcp\Tools\FastenerTools;
use App\Mcp\Tools\MaterialTools;
use App\Mcp\Tools\ScriptTools;
use App\Mcp\Tools\WoodworkingApiTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

#[Name('sketchup-woodworking')]
#[Version('1.6.0')]
#[Instructions(<<<'TEXT'
    Read woodworking_capabilities before designing. Use dry_run before committing a command batch.

    For hardware, get the project snapshot and list the material catalog using a global key. To place a screw or nail, provide the catalog ID, the instance holding the head, its stock face, face-local U/V coordinates and sink in millimeters. Instance IDs are distinct from part definition IDs.

    Preview placement or copying before committing with dry_run=false and the returned expected_revision. In a placement preview, suggest_targets=true checks shaped stock and returns proposed target IDs in row order. Use row_target_instance_ids to assign them, with null for unlinked points. Copy target lists follow ascending original fastener ID, even when reflecting a row.

    Installed fasteners count toward the bill of materials. Use woodworking_prepare_fastener_drilling separately for screw clearance, pilot holes and countersinks. Use woodworking_analyze_fasteners to check installed hardware against saved machining and review any approximate results.

    Use woodworking_create_catalog_material and woodworking_update_catalog_material for account-wide boards, sheets and consumables. Read woodworking_get_project_materials before updating a project. Keep existing entries you want to retain in the full plan, preview with woodworking_update_project_materials and commit with the returned revision.

    Read woodworking_get_assembly_instructions before authoring assembly steps. Use woodworking_get_project for instance and fastener IDs. Treat the group-based suggestions as a starting point; choose a practical assembly order, name each action, and use notes for checks or alignment details. Send the complete ordered list to woodworking_update_assembly_instructions, review the dry run, then commit with its revision. Cameras use world coordinates in millimeters with Z up.

    For JavaScript workflows, get the standalone compiler from woodworking_get_script_api and compile your own script locally. Read the project script revision, then send the source and compiled payload to woodworking_run_project_script for a dry run and commit. Generated fasteners, instruction steps, catalog assignments and render settings travel with the script. Catalog IDs come from woodworking_list_material_catalog.

    Use woodworking_export_project to inspect a committed project. PNG returns an inline rendered image by default. Fetch any returned resource_uri via MCP resources/read, or use the revision-pinned HTTP download URL with the same Bearer API key.

    Use woodworking_check_assembly_geometry after committing a model to confirm material intersections and fastener reach using applied operations. The basic analysis only flags candidate overlaps by rotated bounds. Check the returned revision and coverage before using a clean result.

    Use woodworking_check_assembly_sequence after saving assembly instructions to catch missing parts, missing hardware and fasteners scheduled before their supporting parts.
    TEXT)]
class WoodworkingServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $resources = [ProjectExportResource::class];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->tools = [
            ...AssemblyInstructionTools::all(),
            ...ScriptTools::all(),
            ...FastenerTools::all(),
            ...MaterialTools::all(),
            new WoodworkingApiTool(
                'woodworking_export_project',
                'Export the visible assembly with applied machining and hardware. Returns an MCP resource URI and an authenticated HTTP download URL pinned to the project revision. PNG also returns an inline image unless inline_preview is false. OBJ coordinates are millimeters; GLB uses meters and includes wood textures.',
                'GET',
                '/projects/{project_id}/export',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'format' => ['enum' => ['obj', 'glb', 'png']],
                        'view' => ['enum' => ['isometric', 'front', 'rear', 'side', 'top']],
                        'width' => ['type' => 'integer', 'minimum' => 320, 'maximum' => 2048],
                        'height' => ['type' => 'integer', 'minimum' => 240, 'maximum' => 2048],
                        'expected_revision' => ['type' => 'integer', 'minimum' => 1],
                        'inline_preview' => ['type' => 'boolean', 'default' => true],
                    ],
                    'required' => ['project_id', 'format'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_capabilities',
                'Read supported coordinate conventions, woodworking operations, batch commands, and limits before designing a project.',
                'GET',
                '/capabilities',
                ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ),
            new WoodworkingApiTool(
                'woodworking_list_projects',
                'List projects available to the configured API key.',
                'GET',
                '/projects',
                ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ),
            new WoodworkingApiTool(
                'woodworking_list_project_templates',
                'List reusable parametric woodworking project templates available to the configured global API key.',
                'GET',
                '/project-templates',
                ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ),
            new WoodworkingApiTool(
                'woodworking_save_project_template',
                'Capture a complete project as a reusable parametric template.',
                'POST',
                '/project-templates',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                        'description' => ['type' => 'string', 'maxLength' => 5000],
                    ],
                    'required' => ['project_id', 'name'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_instantiate_project_template',
                'Create a project from a template with independent X width, Y depth, and Z height dimensions.',
                'POST',
                '/project-templates/{template_id}/instantiate',
                [
                    'type' => 'object',
                    'properties' => [
                        'template_id' => ['type' => 'integer', 'minimum' => 1],
                        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                        'description' => ['type' => 'string', 'maxLength' => 5000],
                        'width' => ['type' => 'number', 'minimum' => 1, 'maximum' => 100000],
                        'depth' => ['type' => 'number', 'minimum' => 1, 'maximum' => 100000],
                        'height' => ['type' => 'number', 'minimum' => 1, 'maximum' => 100000],
                    ],
                    'required' => ['template_id', 'name', 'width', 'depth', 'height'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_create_project',
                'Create an empty woodworking project and return its initial revision.',
                'POST',
                '/projects',
                [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                        'description' => ['type' => 'string', 'maxLength' => 5000],
                    ],
                    'required' => ['name'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_get_project',
                'Get a complete project snapshot with definitions, operations, instances, coordinate rules, and current revision.',
                'GET',
                '/projects/{project_id}',
                [
                    'type' => 'object',
                    'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1]],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_validate_project',
                'Validate a project and return warnings, assembly bounds, part count, instance count, and material volume.',
                'POST',
                '/projects/{project_id}/validate',
                [
                    'type' => 'object',
                    'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1]],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_check_assembly_geometry',
                'Check material intersections after applied cuts and fastener reach in the saved assembly. Includes hidden instances. Results include intersection bounds and volume, fastener IDs, and coverage. Review partial coverage before treating the assembly as clear.',
                'GET',
                '/projects/{project_id}/assembly-check',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'expected_revision' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_analyze_project',
                'Get rotated-bounds diagnostics, review items, a bill of materials, and preliminary linear and sheet cutting maps.',
                'GET',
                '/projects/{project_id}/analysis',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'kerf_mm' => ['type' => 'number', 'minimum' => 0, 'maximum' => 20],
                        'edge_margin_mm' => ['type' => 'number', 'minimum' => 0, 'maximum' => 500],
                        'linear_stock_length_mm' => ['type' => 'number', 'minimum' => 100, 'maximum' => 50000],
                        'sheet_length_mm' => ['type' => 'number', 'minimum' => 100, 'maximum' => 10000],
                        'sheet_width_mm' => ['type' => 'number', 'minimum' => 100, 'maximum' => 10000],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_list_connections',
                'List semantic woodworking connections between project instances.',
                'GET',
                '/projects/{project_id}/connections',
                [
                    'type' => 'object',
                    'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1]],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_create_connection',
                'Connect two instances with a butt, half-lap, mortise-and-tenon, or dowel joint.',
                'POST',
                '/projects/{project_id}/connections',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'primary_instance_id' => ['type' => 'integer', 'minimum' => 1],
                        'secondary_instance_id' => ['type' => 'integer', 'minimum' => 1],
                        'type' => ['enum' => ['butt', 'half_lap', 'mortise_tenon', 'dowel']],
                        'label' => ['type' => 'string', 'maxLength' => 255],
                        'note' => ['type' => 'string', 'maxLength' => 5000],
                        'parameters' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                    'required' => ['project_id', 'primary_instance_id', 'secondary_instance_id', 'type'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_update_connection',
                'Update connection metadata or face-local placement parameters. Changing placement marks generated machining as outdated.',
                'PATCH',
                '/projects/{project_id}/connections/{connection_id}',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'connection_id' => ['type' => 'integer', 'minimum' => 1],
                        'label' => ['type' => ['string', 'null'], 'maxLength' => 255],
                        'note' => ['type' => ['string', 'null'], 'maxLength' => 5000],
                        'is_verified' => ['type' => 'boolean'],
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'primary_face' => ['enum' => ['top', 'bottom', 'left', 'right', 'start', 'end']],
                                'secondary_face' => ['enum' => ['top', 'bottom', 'left', 'right', 'start', 'end']],
                                'primary_center_u' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
                                'primary_center_v' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
                                'secondary_center_u' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
                                'secondary_center_v' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
                                'joint_angle' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
                                'joint_length' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 100000],
                                'joint_width' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 10000],
                                'depth_ratio' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 0.9],
                                'tenon_width' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 10000],
                                'tenon_thickness' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 10000],
                                'tenon_length' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 10000],
                                'dowel_diameter' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 100],
                                'dowel_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                                'dowel_depth' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 1000],
                                'dowel_spacing' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 10000],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'required' => ['project_id', 'connection_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_delete_connection',
                'Delete a woodworking connection from a project.',
                'DELETE',
                '/projects/{project_id}/connections/{connection_id}',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'connection_id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['project_id', 'connection_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_preview_connection_machining',
                'Preview pending or outdated mortise-and-tenon machining on both parts without saving. Pass the returned revision as expected_revision when applying.',
                'GET',
                '/projects/{project_id}/connections/{connection_id}/machining/preview',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'connection_id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['project_id', 'connection_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_generate_connection_machining',
                'Generate applied machining operations for both parts of a semantic connection. Shared part definitions are split automatically.',
                'POST',
                '/projects/{project_id}/connections/{connection_id}/machining',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'connection_id' => ['type' => 'integer', 'minimum' => 1],
                        'expected_revision' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['project_id', 'connection_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_remove_connection_machining',
                'Remove operations generated by a connection and return it to pending machining state.',
                'DELETE',
                '/projects/{project_id}/connections/{connection_id}/machining',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'connection_id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['project_id', 'connection_id'],
                    'additionalProperties' => false,
                ],
            ),
            new WoodworkingApiTool(
                'woodworking_apply_commands',
                'Atomically preview or commit a batch of part, assembly-group, and instance commands. Use dry_run=true first, then repeat with dry_run=false and the current revision.',
                'POST',
                '/projects/{project_id}/commands',
                [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'minimum' => 1],
                        'expected_revision' => ['type' => 'integer', 'minimum' => 1],
                        'dry_run' => ['type' => 'boolean', 'default' => true],
                        'commands' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'maxItems' => 100,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => [
                                        'enum' => [
                                            'create_part',
                                            'create_instance',
                                            'transform_instance',
                                            'delete_instance',
                                            'create_group',
                                            'update_group',
                                            'delete_group',
                                            'assign_instance_to_group',
                                        ],
                                    ],
                                    'temporary_id' => ['type' => 'string', 'maxLength' => 100],
                                    'part_id' => ['type' => 'integer', 'minimum' => 1],
                                    'part_ref' => ['type' => 'string', 'maxLength' => 100],
                                    'instance_id' => ['type' => 'integer', 'minimum' => 1],
                                    'instance_ref' => ['type' => 'string', 'maxLength' => 100],
                                    'group_id' => ['type' => 'integer', 'minimum' => 1],
                                    'group_ref' => ['type' => 'string', 'maxLength' => 100],
                                    'parent_group_id' => ['type' => 'integer', 'minimum' => 1],
                                    'parent_group_ref' => ['type' => 'string', 'maxLength' => 100],
                                    'data' => ['type' => 'object'],
                                ],
                                'required' => ['type'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['project_id', 'expected_revision', 'commands'],
                    'additionalProperties' => false,
                ],
            ),
        ];
    }
}
