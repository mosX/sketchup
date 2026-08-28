<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePartInstanceRequest;
use App\Http\Requests\UpdatePartInstanceRequest;
use App\Http\Resources\PartInstanceResource;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PartInstanceController extends Controller
{
    public function store(StorePartInstanceRequest $request, Project $project, PartDefinition $partDefinition): JsonResponse
    {
        $validated = $request->validated();
        $quantity = (int) ($validated['quantity'] ?? 1);
        unset($validated['quantity']);

        $instances = DB::transaction(function () use ($partDefinition, $project, $quantity, $validated) {
            return collect(range(0, $quantity - 1))->map(function (int $index) use ($partDefinition, $project, $validated) {
                $positionY = (float) ($validated['position_y'] ?? 0) + ($index * ($partDefinition->width + 100));

                return $partDefinition->instances()->create([
                    ...$validated,
                    'project_id' => $project->id,
                    'position_y' => $positionY,
                ]);
            });
        });
        $project->increment('revision');

        return PartInstanceResource::collection($instances)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePartInstanceRequest $request, Project $project, PartInstance $partInstance): PartInstanceResource
    {
        $partInstance->update($request->validated());
        $project->increment('revision');

        return new PartInstanceResource($partInstance->refresh());
    }

    public function destroy(Project $project, PartInstance $partInstance): Response
    {
        Gate::authorize('update', $project);
        $partInstance->delete();
        $project->increment('revision');

        return response()->noContent();
    }
}
