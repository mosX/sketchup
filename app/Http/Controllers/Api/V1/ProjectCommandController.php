<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\ExecuteProjectCommands;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteProjectCommandsRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

class ProjectCommandController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        ExecuteProjectCommandsRequest $request,
        Project $project,
        ExecuteProjectCommands $executeProjectCommands,
    ): JsonResponse {
        return response()->json([
            'data' => $executeProjectCommands->handle($project, $request->validated()),
        ]);
    }
}
