<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectVersionRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Services\ProjectVersionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProjectVersionController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['data' => $project->projectVersions()->latest('id')->get(['id', 'project_id', 'label', 'revision', 'created_at'])]);
    }

    public function store(ProjectVersionRequest $request, Project $project, ProjectVersionManager $versions): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $versions->capture($project, (int) $data['expected_revision'], $data['label'] ?? 'Сохранённая версия')], 201);
    }

    public function restore(ProjectVersionRequest $request, Project $project, ProjectVersion $projectVersion, ProjectVersionManager $versions): ProjectResource
    {
        return new ProjectResource($versions->restore($project, $projectVersion, (int) $request->validated('expected_revision')));
    }
}
