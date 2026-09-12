<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstantiateProjectTemplateRequest;
use App\Http\Requests\StoreProjectTemplateRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectTemplateResource;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Services\ProjectTemplateManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProjectTemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ProjectTemplate::class);

        return ProjectTemplateResource::collection(auth()->user()->projectTemplates()->latest()->get());
    }

    public function store(StoreProjectTemplateRequest $request, ProjectTemplateManager $manager): JsonResponse
    {
        Gate::authorize('create', ProjectTemplate::class);
        $project = Project::query()->findOrFail($request->integer('project_id'));
        Gate::authorize('view', $project);
        $template = $manager->capture(
            $request->user(),
            $project,
            $request->string('name')->toString(),
            $request->validated('description'),
        );

        return (new ProjectTemplateResource($template))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function instantiate(
        InstantiateProjectTemplateRequest $request,
        ProjectTemplate $projectTemplate,
        ProjectTemplateManager $manager,
    ): JsonResponse {
        Gate::authorize('view', $projectTemplate);
        $project = $manager->instantiate($request->user(), $projectTemplate, $request->validated());

        return (new ProjectResource($project))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(ProjectTemplate $projectTemplate): Response
    {
        Gate::authorize('delete', $projectTemplate);
        $projectTemplate->delete();

        return response()->noContent();
    }
}
