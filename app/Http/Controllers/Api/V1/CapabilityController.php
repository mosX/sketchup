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
                'schema_version' => 4,
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
                'limits' => [
                    'commands_per_batch' => 100,
                    'operations_per_part' => 50,
                ],
            ],
        ]);
    }
}
