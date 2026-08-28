<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ProjectDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProjectValidationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Project $project, ProjectDiagnostics $diagnostics): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => [
                'project_id' => $project->id,
                'revision' => $project->revision,
                ...$diagnostics->analyze($project),
            ],
        ]);
    }
}
