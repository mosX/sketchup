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
                'schema_version' => 26,
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
                'assembly_instructions' => [
                    'step_fields' => ['title', 'instruction', 'notes', 'instance_ids', 'highlight_instance_ids', 'fastener_ids', 'camera'],
                    'endpoint' => '/api/v1/projects/{project}/assembly-instructions',
                    'sequence_check' => '/api/v1/projects/{project}/assembly-instructions/check',
                    'max_steps' => 200,
                    'camera' => ['fields' => ['position', 'target'], 'units' => 'millimeters', 'up_axis' => 'z'],
                    'supports_group_based_suggestions' => true,
                    'preview_and_commit' => ['dry_run', 'expected_revision'],
                ],
                'project_scripts' => [
                    'compiler' => '/api/v1/script-api',
                    'run' => '/api/v1/projects/{project}/script/run',
                    'sections' => ['commands', 'connections', 'fasteners', 'materials', 'assembly_steps', 'render_settings'],
                    'transaction' => ['dry_run', 'expected_revision'],
                    'runs_javascript_on_server' => false,
                ],
                'project_export' => [
                    'endpoint' => '/api/v1/projects/{project}/export',
                    'formats' => ['obj', 'glb', 'png'],
                    'supports_inline_mcp_png' => true,
                    'views' => ['isometric', 'front', 'rear', 'side', 'top'],
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
                'assembly_check' => [
                    'endpoint' => '/api/v1/projects/{project}/assembly-check',
                    'tool' => 'woodworking_check_assembly_geometry',
                    'checks' => ['solid_intersections_after_machining', 'fastener_reach_after_machining'],
                    'ignores_draft_operations' => true,
                    'checks_hidden_instances' => true,
                    'minimum_penetration_mm' => 0.01,
                    'minimum_overlap_volume_mm3' => 0.01,
                    'reports_partial_results' => true,
                ],
                'project_templates' => [
                    'parameters' => ['width_x', 'depth_y', 'height_z'],
                    'preserves' => ['part_operations', 'assembly_groups', 'instance_rotations'],
                    'splits_part_variants_for_non_uniform_scaling' => true,
                ],
                'materials' => [
                    'catalog_kinds' => ['board', 'sheet', 'consumable'],
                    'project_plan' => ['default_material_id', 'assignments', 'supplies'],
                    'project_plan_preview' => ['dry_run', 'expected_revision'],
                ],
                'fasteners' => [
                    'type' => 'screw',
                    'types' => ['screw', 'nail'],
                    'catalog_fields' => ['fastener_type', 'fastener_diameter', 'fastener_length'],
                    'drilling_supported_types' => ['screw'],
                    'placement' => ['source_instance_id', 'target_instance_id', 'catalog_material_id', 'face', 'center_u', 'center_v', 'sink'],
                    'row_parameters' => ['count', 'row_axis', 'spacing', 'row_target_instance_ids'],
                    'row_limit' => 50,
                    'placement_preview' => ['dry_run', 'expected_revision', 'suggest_targets'],
                    'geometry_analysis' => true,
                    'copy' => ['fastener_ids', 'source_instance_id', 'target_instance_id', 'face', 'mirror_u', 'mirror_v', 'dry_run', 'expected_revision', 'row_target_instance_ids', 'suggest_targets'],
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
                    'rabbet' => ['edge', 'face', 'start', 'cut_length', 'width', 'depth'],
                    'pocket' => ['face', 'pocket_shape', 'center_u', 'center_v', 'depth', 'pocket_length', 'width', 'diameter'],
                    'edge_chamfer' => ['edge', 'width'],
                    'jigsaw_cut' => ['face', 'points'],
                    'plunge_route' => ['face', 'route_mode', 'start_u', 'start_v', 'path_angle', 'travel_length', 'cutter_diameter', 'cutter_profile', 'cutter_angle', 'depth'],
                    'drill' => ['face', 'center_u', 'center_v', 'diameter', 'depth', 'through'],
                    'drill_row' => ['face', 'center_u', 'center_v', 'path_angle', 'diameter', 'depth', 'through', 'hole_count', 'hole_spacing'],
                    'pocket_screw' => ['face', 'center_u', 'center_v', 'path_angle', 'entry_angle', 'diameter', 'depth', 'pilot_diameter', 'pilot_length'],
                    'curve_route' => ['face', 'points', 'cutter_diameter', 'cutter_profile', 'cutter_angle', 'depth', 'closed_path'],
                    'taper' => ['taper_sides', 'start_width', 'end_width', 'start_height', 'end_height'],
                    'turning' => ['stations'],
                ],
                'part_operation_values' => [
                    'face' => ['top', 'bottom', 'left', 'right', 'start', 'end'],
                    'route_mode' => ['point', 'path'],
                    'cutter_profile' => ['straight', 'dovetail', 'v_groove'],
                    'taper_sides' => ['two', 'four'],
                    'edge' => ['top_left', 'top_right', 'bottom_left', 'bottom_right', 'top_start', 'top_end', 'bottom_start', 'bottom_end', 'start_left', 'start_right', 'end_left', 'end_right'],
                ],
                'part_operation_optional_fields' => [
                    'drill' => ['bottom_diameter'], 'pocket' => ['corner_radius'],
                    'curve_route' => ['smooth_path'], 'turning' => ['smooth_profile'],
                ],
                'shaping_operations' => [
                    'rabbet' => ['face' => 'One of the two faces listed in edge', 'start' => 'Distance along the edge from the smaller stock coordinate'],
                    'pocket' => [
                        'shapes' => ['rectangle', 'circle'],
                        'rectangle_fields' => ['pocket_length (U)', 'width (V)', 'corner_radius (optional)'],
                        'circle_fields' => ['diameter'],
                        'requires_remaining_floor' => true,
                    ],
                    'edge_chamfer' => ['angle_degrees' => 45, 'width' => 'Distance along each adjacent face', 'one_operation_per_edge' => true],
                    'jigsaw_cut' => ['point_fields' => ['u', 'v'], 'points_per_contour' => [3, 64], 'implicitly_closed' => true, 'points_may_extend_past_stock' => true, 'through' => true],
                    'script_methods' => ['rabbet', 'pocket', 'chamfer', 'jigsawCut'],
                ],
                'advanced_machining' => [
                    'drill_row' => ['count' => [1, 40], 'center' => 'First hole', 'path_angle' => 'Heading from positive face U towards positive V'],
                    'pocket_screw' => ['entry_angle' => 'Degrees from the selected face plane', 'depth' => 'Length of the large step along the bore axis', 'pilot_length' => 'Additional length from the shoulder'],
                    'curve_route' => ['point_fields' => ['u', 'v'], 'open_points' => [2, 32], 'closed_points' => [3, 32], 'profiles' => ['straight', 'dovetail', 'v_groove']],
                    'taper' => ['axis' => 'length', 'centered' => true, 'two' => 'Width only', 'four' => 'Width and thickness', 'four_additional_fields' => ['start_height', 'end_height']],
                    'turning' => ['axis' => 'length', 'station_fields' => ['at', 'diameter'], 'station_count' => [2, 24], 'start_at_zero_end_at_stock_length' => true],
                    'script_methods' => ['drillRow', 'pocketScrew', 'curveRoute', 'taper', 'turn'],
                ],
                'limits' => [
                    'commands_per_batch' => 100,
                    'operations_per_part' => 50,
                ],
            ],
        ]);
    }
}
