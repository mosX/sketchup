<?php

namespace App\Http\Controllers;

use App\Actions\AssemblyGroups\DeleteAssemblyGroup;
use App\Http\Requests\DestroyAssemblyGroupRequest;
use App\Http\Requests\StoreAssemblyGroupRequest;
use App\Http\Requests\UpdateAssemblyGroupRequest;
use App\Http\Resources\AssemblyGroupResource;
use App\Models\AssemblyGroup;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AssemblyGroupController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return AssemblyGroupResource::collection(
            $project->assemblyGroups()
                ->withCount(['instances', 'children'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAssemblyGroupRequest $request, Project $project): JsonResponse
    {
        $group = $project->assemblyGroups()->create($request->validated());
        $project->increment('revision');

        return (new AssemblyGroupResource($group->loadCount(['instances', 'children'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAssemblyGroupRequest $request, Project $project, AssemblyGroup $assemblyGroup): AssemblyGroupResource
    {
        $assemblyGroup->update($request->validated());
        $project->increment('revision');

        return new AssemblyGroupResource($assemblyGroup->refresh()->loadCount(['instances', 'children']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(
        DestroyAssemblyGroupRequest $request,
        Project $project,
        AssemblyGroup $assemblyGroup,
        DeleteAssemblyGroup $deleteAssemblyGroup,
    ): Response {
        DB::transaction(function () use ($request, $project, $assemblyGroup, $deleteAssemblyGroup): void {
            $deleteAssemblyGroup->handle($project, $assemblyGroup, $request->boolean('delete_contents'));
            $project->increment('revision');
        });

        return response()->noContent();
    }
}
