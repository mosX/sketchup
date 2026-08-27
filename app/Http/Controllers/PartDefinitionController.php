<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePartDefinitionRequest;
use App\Http\Requests\UpdatePartDefinitionRequest;
use App\Http\Resources\PartDefinitionResource;
use App\Models\PartDefinition;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PartDefinitionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return PartDefinitionResource::collection(
            $project->partDefinitions()->with('instances')->withCount('instances')->latest()->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePartDefinitionRequest $request, Project $project): JsonResponse
    {
        $partDefinition = $project->partDefinitions()->create($request->validated());

        return (new PartDefinitionResource($partDefinition->load('instances')->loadCount('instances')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, PartDefinition $partDefinition): PartDefinitionResource
    {
        Gate::authorize('view', $project);

        return new PartDefinitionResource($partDefinition->load('instances')->loadCount('instances'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePartDefinitionRequest $request, Project $project, PartDefinition $partDefinition): PartDefinitionResource
    {
        $partDefinition->update($request->validated());

        return new PartDefinitionResource($partDefinition->refresh()->load('instances')->loadCount('instances'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project, PartDefinition $partDefinition): Response
    {
        Gate::authorize('update', $project);
        $partDefinition->delete();

        return response()->noContent();
    }
}
