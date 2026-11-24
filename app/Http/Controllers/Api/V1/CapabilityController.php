<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CapabilityController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'api_version' => 'v1',
                'schema_version' => 18,
                'coordinate_system' => [
                    'units' => 'millimeters',
                    'angles' => 'degrees',
                    'plane_axes' => ['x', 'y'],
                    'up_axis' => 'z',
                    'part_operations_use_local_coordinates' => true,
                    'instances_use_world_coordinates' => true,
                ],
                'commands' => [
                    'create_part',
                    'create_instance',
                    'transform_instance',
                    'delete_instance',
                    'create_group',
                    'update_group',
                    'delete_group',
                    'assign_instance_to_group',
                ],
                'assembly_groups' => [
                    'fields' => ['name', 'parent_id', 'is_visible', 'is_locked', 'sort_order'],
                    'instances_reference_group_by' => 'assembly_group_id',
                    'supports_nested_groups' => true,
                    'delete_contents_is_explicit' => true,
                ],
                'instance_transforms' => [
                    'optional_part_reference' => ['part_id', 'part_ref'],
                    'part_replacement_requires_no_connections' => true,
                ],
                'project_analysis' => [
                    'diagnostics' => ['warnings', 'review_items', 'rotated_bounds'],
                    'manufacturing' => ['bill_of_materials', 'linear_cutting', 'sheet_cutting'],
                    'cutting_settings' => ['kerf_mm', 'edge_margin_mm', 'linear_stock_length_mm', 'sheet_length_mm', 'sheet_width_mm'],
                    'cutting_map_is_preliminary' => true,
                ],
                'project_templates' => [
                    'parameters' => ['width_x', 'depth_y', 'height_z'],
                    'preserves' => ['part_operations', 'assembly_groups', 'instance_rotations'],
                    'splits_part_variants_for_non_uniform_scaling' => true,
                ],
                'fasteners' => [
                    'type' => 'screw',
                    'types' => ['screw', 'nail'],
                    'catalog_fields' => ['fastener_type', 'fastener_diameter', 'fastener_length'],
                    'drilling_supported_types' => ['screw'],
                    'placement' => ['source_instance_id', 'target_instance_id', 'catalog_material_id', 'face', 'center_u', 'center_v', 'sink'],
                    'row_parameters' => ['count', 'row_axis', 'spacing', 'row_target_instance_ids'],
                    'row_limit' => 50,
                    'copy' => ['fastener_ids', 'source_instance_id', 'target_instance_id', 'face', 'mirror_u', 'mirror_v', 'dry_run', 'expected_revision', 'row_target_instance_ids'],
                    'row_target_order' => ['placement' => 'row_index', 'copy' => 'ascending_fastener_id'],
                    'included_in_project_costing' => true,
                    'drilling' => ['clearance', 'pilot', 'countersink', 'dry_run', 'expected_revision'],
                    'countersink_fields' => ['countersink', 'countersink_diameter', 'countersink_depth'],
                ],
                'connections' => [
                    'types' => ['butt', 'half_lap', 'mortise_tenon', 'dowel'],
                    'links' => ['primary_instance_id', 'secondary_instance_id'],
                    'supports_verification' => true,
                    'supports_machining_generation' => true,
                    'machining_statuses' => ['pending', 'generated', 'not_required', 'outdated'],
                    'generated_operations_are_applied' => true,
                    'shared_parts_are_split_before_machining' => true,
                    'placement_parameters' => ['primary_center_u', 'primary_center_v', 'secondary_center_u', 'secondary_center_v', 'joint_angle'],
                    'supports_machining_regeneration' => true,
                    'machining_preview_types' => ['mortise_tenon'],
                    'machining_apply_accepts_expected_revision' => true,
                    'machining_invalidated_on_part_resize' => ['mortise_tenon'],
                    'supports_machining_reset' => true,
                ],
                'part_operations' => [
                    'cross_cut' => ['position', 'miter_angle', 'bevel_angle', 'kerf', 'cut_depth', 'cut_direction', 'keep_side'],
                    'rip_cut' => ['reference_side', 'start_offset', 'end_offset', 'bevel_angle', 'kerf', 'cut_depth', 'cut_direction', 'keep_side'],
                    'groove' => ['face', 'center_u', 'center_v', 'path_angle', 'groove_length', 'width', 'depth', 'blade_diameter'],
                    'edge_roundover' => ['edge', 'radius'],
                    'plunge_route' => ['face', 'route_mode', 'start_u', 'start_v', 'path_angle', 'travel_length', 'cutter_diameter', 'cutter_profile', 'cutter_angle', 'depth'],
                    'drill' => ['face', 'center_u', 'center_v', 'diameter', 'depth', 'through'],
                ],
                'part_operation_values' => [
                    'face' => ['top', 'bottom', 'left', 'right', 'start', 'end'],
                    'route_mode' => ['point', 'path'],
                    'cutter_profile' => ['straight', 'dovetail', 'v_groove'],
                    'edge' => ['top_left', 'top_right', 'bottom_left', 'bottom_right', 'top_start', 'top_end', 'bottom_start', 'bottom_end', 'start_left', 'start_right', 'end_left', 'end_right'],
                ],
                'part_operation_optional_fields' => ['drill' => ['bottom_diameter']],
                'limits' => [
                    'commands_per_batch' => 100,
                    'operations_per_part' => 50,
                ],
            ],
        ]);
    }
}
