<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);
        $user = auth()->user();
        $projects = $user->projects()->latest();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $scopedProjectIds = collect($token->abilities ?? [])
                ->filter(fn (string $ability): bool => str_starts_with($ability, 'project:'))
                ->map(fn (string $ability): int => (int) str_replace('project:', '', $ability));

            if ($scopedProjectIds->isNotEmpty()) {
                $projects->whereKey($scopedProjectIds);
            }
        }

        return ProjectResource::collection(
            $projects->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $request->user()->projects()->create($request->validated());

        return (new ProjectResource($project))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $project->update($request->validated());
        $project->increment('revision');

        return new ProjectResource($project->refresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project): Response
    {
        Gate::authorize('delete', $project);
        $project->delete();

        return response()->noContent();
    }
}
