<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectAnalysisRequest;
use App\Models\Project;
use App\Services\ProjectDiagnostics;
use App\Services\ProjectManufacturingReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProjectAnalysisController extends Controller
{
    public function __invoke(
        ProjectAnalysisRequest $request,
        Project $project,
        ProjectDiagnostics $diagnostics,
        ProjectManufacturingReport $manufacturingReport,
    ): JsonResponse {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => [
                'project_id' => $project->id,
                'revision' => $project->revision,
                'diagnostics' => $diagnostics->analyze($project),
                'manufacturing' => $manufacturingReport->build($project, $request->validated()),
            ],
        ]);
    }
}
