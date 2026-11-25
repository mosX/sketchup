<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProjectRevisionConflictException;
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

        return response()->json(['data' => $project->material_plan, 'revision' => $project->revision]);
    }

    public function update(UpdateProjectMaterialsRequest $request, Project $project): JsonResponse
    {
        $plan = $request->safe()->only(['default_material_id', 'assignments', 'supplies']);
        $expected = $request->validated('expected_revision');
        $dryRun = $request->boolean('dry_run');
        $revision = DB::transaction(function () use ($project, $plan, $expected, $dryRun): int {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($expected !== null && (int) $expected !== $locked->revision) {
                throw new ProjectRevisionConflictException((int) $expected, $locked->revision);
            }
            if (! $dryRun) {
                $locked->material_plan = $plan;
                if ($locked->isDirty('material_plan')) {
                    $locked->revision++;
                    $locked->save();
                }
            }

            return $locked->revision;
        }, attempts: 3);

        return response()->json(['data' => $plan, 'revision' => $revision]);
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
