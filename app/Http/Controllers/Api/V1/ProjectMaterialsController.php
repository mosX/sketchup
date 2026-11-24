<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectAnalysisRequest;
use App\Http\Requests\UpdateProjectMaterialsRequest;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Services\ProjectManufacturingReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectMaterialsController extends Controller
{
    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['data' => $project->material_plan]);
    }

    public function update(UpdateProjectMaterialsRequest $request, Project $project): JsonResponse
    {
        $plan = $request->validated();
        DB::transaction(function () use ($project, $plan): void {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $locked->material_plan = $plan;
            if ($locked->isDirty('material_plan')) {
                $locked->revision++;
                $locked->save();
            }
        }, attempts: 3);

        return response()->json(['data' => $plan]);
    }

    public function estimates(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['data' => $project->projectEstimates()->latest('id')->paginate(20, ['id', 'created_at'])]);
    }

    public function estimate(Project $project, ProjectEstimate $projectEstimate): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['data' => $this->estimateData($projectEstimate)]);
    }

    public function storeEstimate(ProjectAnalysisRequest $request, Project $project, ProjectManufacturingReport $report): JsonResponse
    {
        Gate::authorize('update', $project);

        $estimate = $project->projectEstimates()->create(['snapshot' => $report->build($project, $request->validated())]);

        return response()->json(['data' => $this->estimateData($estimate)], 201);
    }

    private function estimateData(ProjectEstimate $estimate): array
    {
        return ['id' => $estimate->id, 'created_at' => $estimate->created_at, 'manufacturing' => $estimate->snapshot];
    }
}
