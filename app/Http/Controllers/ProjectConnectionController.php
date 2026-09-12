<?php

namespace App\Http\Controllers;

use App\Actions\Connections\GenerateConnectionMachining;
use App\Actions\Connections\UpdateProjectConnection as UpdateProjectConnectionAction;
use App\Http\Requests\StoreProjectConnectionRequest;
use App\Http\Requests\UpdateProjectConnectionRequest;
use App\Http\Resources\ProjectConnectionResource;
use App\Models\Project;
use App\Models\ProjectConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectConnectionController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ProjectConnectionResource::collection($project->projectConnections()->latest()->get());
    }

    public function store(StoreProjectConnectionRequest $request, Project $project): JsonResponse
    {
        $validated = $request->validated();
        $validated['parameters'] = [
            ...$this->defaultParameters($validated['type']),
            ...($validated['parameters'] ?? []),
        ];
        $connection = DB::transaction(function () use ($project, $validated): ProjectConnection {
            $connection = $project->projectConnections()->create($validated);
            $project->increment('revision');

            return $connection;
        });

        return (new ProjectConnectionResource($connection))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateProjectConnectionRequest $request,
        Project $project,
        ProjectConnection $projectConnection,
        UpdateProjectConnectionAction $updateProjectConnection,
    ): ProjectConnectionResource {
        return new ProjectConnectionResource(
            $updateProjectConnection->handle($project, $projectConnection, $request->validated())
        );
    }

    public function destroy(Project $project, ProjectConnection $projectConnection): Response
    {
        Gate::authorize('update', $project);
        DB::transaction(function () use ($project, $projectConnection): void {
            $projectConnection->delete();
            $project->increment('revision');
        });

        return response()->noContent();
    }

    public function generateMachining(
        Project $project,
        ProjectConnection $projectConnection,
        GenerateConnectionMachining $generateConnectionMachining,
    ): ProjectConnectionResource {
        Gate::authorize('update', $project);

        return new ProjectConnectionResource(
            $generateConnectionMachining->handle($project, $projectConnection)
        );
    }

    public function removeMachining(
        Project $project,
        ProjectConnection $projectConnection,
        GenerateConnectionMachining $generateConnectionMachining,
    ): ProjectConnectionResource {
        Gate::authorize('update', $project);

        return new ProjectConnectionResource(
            $generateConnectionMachining->reset($project, $projectConnection)
        );
    }

    /** @return array<string, float|int|string> */
    private function defaultParameters(string $type): array
    {
        return [
            'primary_face' => 'end',
            'secondary_face' => 'start',
            'joint_angle' => 0,
            ...match ($type) {
                'half_lap' => ['depth_ratio' => 0.5],
                'mortise_tenon' => ['tenon_width' => 30, 'tenon_thickness' => 10, 'tenon_length' => 20],
                'dowel' => ['dowel_diameter' => 8, 'dowel_count' => 2, 'dowel_depth' => 25, 'dowel_spacing' => 16],
                default => [],
            },
        ];
    }
}
