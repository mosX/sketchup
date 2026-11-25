<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\AnalyzeProjectGeometry;
use App\Actions\Projects\CopyProjectFasteners;
use App\Actions\Projects\PlanFastenerPlacements;
use App\Actions\Projects\PrepareFastenerDrilling;
use App\Exceptions\ProjectRevisionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CopyProjectFastenersRequest;
use App\Http\Requests\PrepareFastenerDrillingRequest;
use App\Http\Requests\ProjectFastenerRequest;
use App\Http\Resources\ProjectFastenerResource;
use App\Models\Project;
use App\Models\ProjectFastener;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectFastenerController extends Controller
{
    public function __construct(private PlanFastenerPlacements $placementPlanner) {}

    public function drill(PrepareFastenerDrillingRequest $request, Project $project, PrepareFastenerDrilling $drilling): JsonResponse
    {
        return response()->json(['data' => $drilling->handle($project, $request->validated())]);
    }

    public function copy(CopyProjectFastenersRequest $request, Project $project, CopyProjectFasteners $copying, AnalyzeProjectGeometry $analysis): JsonResponse
    {
        $result = $copying->handle($project, $request->safe()->except(['suggest_targets']));
        $result['placements'] = ProjectFastenerResource::collection($result['placements'])->resolve($request);
        if ($request->boolean('suggest_targets')) {
            $result['target_suggestions'] = $analysis->handle($project, $request, $result['placements']);
        }

        return response()->json(['data' => $result], $request->boolean('dry_run') ? 200 : 201);
    }

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ProjectFastenerResource::collection($project->projectFasteners()->orderBy('id')->get());
    }

    public function analyze(Request $request, Project $project, AnalyzeProjectGeometry $analysis): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['data' => $analysis->handle($project, $request)]);
    }

    public function store(ProjectFastenerRequest $request, Project $project, AnalyzeProjectGeometry $analysis): JsonResponse
    {
        $result = $this->save($project, $request->validated());
        $placements = ProjectFastenerResource::collection($result['placements']);
        if ($request->boolean('dry_run')) {
            $data = $placements->resolve($request);
            $suggestions = $request->boolean('suggest_targets') ? $analysis->handle($project, $request, $data) : null;

            return response()->json(['data' => $data, 'revision' => $result['revision'], 'target_suggestions' => $suggestions]);
        }

        return $placements->additional(['revision' => $result['revision']])->response()->setStatusCode(201);
    }

    public function update(ProjectFastenerRequest $request, Project $project, ProjectFastener $projectFastener): ProjectFastenerResource
    {
        return new ProjectFastenerResource($this->save($project, $request->validated(), $projectFastener)['placements'][0]);
    }

    public function destroy(ProjectFastenerRequest $request, Project $project): Response
    {
        $ids = $request->validated('ids');
        DB::transaction(function () use ($project, $ids): void {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $fasteners = $locked->projectFasteners()->whereKey($ids);
            abort_unless($fasteners->count() === count($ids), 404);
            $fasteners->delete();
            $locked->increment('revision');
        }, attempts: 3);

        return response()->noContent();
    }

    private function save(Project $project, array $attributes, ?ProjectFastener $existing = null): array
    {
        return DB::transaction(function () use ($project, $attributes, $existing): array {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $expected = $attributes['expected_revision'] ?? null;
            if ($expected !== null && (int) $expected !== $project->revision) {
                throw new ProjectRevisionConflictException((int) $expected, $project->revision);
            }
            $existing = $existing ? $project->projectFasteners()->findOrFail($existing->id) : null;
            $placements = $this->placementPlanner->handle($project, Arr::except($attributes, ['expected_revision', 'dry_run', 'suggest_targets']), $existing);
            if ($attributes['dry_run'] ?? false) {
                return ['revision' => $project->revision, 'placements' => array_map(fn (array $placement): ProjectFastener => new ProjectFastener($placement), $placements)];
            }
            $saved = [];
            foreach ($placements as $placement) {
                if ($existing) {
                    $existing->update($placement);
                    $saved[] = $existing;
                } else {
                    $saved[] = $project->projectFasteners()->create($placement);
                }
            }
            $project->increment('revision');

            return ['revision' => $project->revision, 'placements' => $saved];
        }, attempts: 3);
    }
}
