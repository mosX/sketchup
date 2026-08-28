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
                'schema_version' => 1,
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
                ],
                'part_operations' => [
                    'cross_cut' => ['position', 'miter_angle', 'bevel_angle', 'kerf', 'cut_depth', 'cut_direction', 'keep_side'],
                    'rip_cut' => ['reference_side', 'start_offset', 'end_offset', 'bevel_angle', 'kerf', 'cut_depth', 'cut_direction', 'keep_side'],
                    'groove' => ['face', 'direction', 'offset', 'start', 'end', 'width', 'depth', 'blade_diameter'],
                ],
                'limits' => [
                    'commands_per_batch' => 100,
                    'operations_per_part' => 50,
                ],
            ],
        ]);
    }
}
