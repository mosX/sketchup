<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\CopyProjectFasteners;
use App\Actions\Projects\PrepareFastenerDrilling;
use App\Http\Controllers\Controller;
use App\Http\Requests\CopyProjectFastenersRequest;
use App\Http\Requests\PrepareFastenerDrillingRequest;
use App\Http\Requests\ProjectFastenerRequest;
use App\Http\Resources\ProjectFastenerResource;
use App\Models\CatalogMaterial;
use App\Models\Project;
use App\Models\ProjectFastener;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectFastenerController extends Controller
{
    public function drill(PrepareFastenerDrillingRequest $request, Project $project, PrepareFastenerDrilling $drilling): JsonResponse
    {
        return response()->json(['data' => $drilling->handle($project, $request->validated())]);
    }

    public function copy(CopyProjectFastenersRequest $request, Project $project, CopyProjectFasteners $copying): JsonResponse
    {
        $result = $copying->handle($project, $request->validated());
        $result['placements'] = ProjectFastenerResource::collection($result['placements'])->resolve($request);

        return response()->json(['data' => $result], $request->boolean('dry_run') ? 200 : 201);
    }

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ProjectFastenerResource::collection($project->projectFasteners()->orderBy('id')->get());
    }

    public function store(ProjectFastenerRequest $request, Project $project): JsonResponse
    {
        $fasteners = $this->save($project, $request->validated());

        return ProjectFastenerResource::collection($fasteners)->response()->setStatusCode(201);
    }

    public function update(ProjectFastenerRequest $request, Project $project, ProjectFastener $projectFastener): ProjectFastenerResource
    {
        return new ProjectFastenerResource($this->save($project, $request->validated(), $projectFastener)[0]);
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
            $existing = $existing ? $project->projectFasteners()->findOrFail($existing->id) : null;
            $placements = $this->placements($project, $attributes, $existing);
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

            return $saved;
        }, attempts: 3);
    }

    private function placements(Project $project, array $attributes, ?ProjectFastener $existing): array
    {
        $source = $project->partInstances()->with('partDefinition')->find($attributes['source_instance_id']);
        if (! $source) {
            throw ValidationException::withMessages(['source_instance_id' => 'Выберите исходную деталь этого проекта.']);
        }
        $targetId = $attributes['target_instance_id'] ?? null;
        $count = (int) ($attributes['count'] ?? 1);
        $rowTargets = $attributes['row_target_instance_ids'] ?? array_fill(0, $count, $targetId);
        if (count($rowTargets) !== $count) {
            throw ValidationException::withMessages(['row_target_instance_ids' => 'Укажите вторую деталь или пустое значение для каждой точки ряда.']);
        }
        $targetIds = collect($rowTargets)->filter()->map(fn (int|string $id): int => (int) $id)->unique();
        if ($targetIds->contains($source->id) || ($targetIds->isNotEmpty() && $project->partInstances()->whereKey($targetIds->all())->count() !== $targetIds->count())) {
            throw ValidationException::withMessages(['target_instance_id' => 'Выберите вторую деталь этого проекта.']);
        }

        $material = CatalogMaterial::query()->where('user_id', $project->user_id)->where('kind', 'consumable')->find($attributes['catalog_material_id']);
        $sameMaterial = $existing !== null && $existing->catalog_material_id === $material?->id;
        $type = $sameMaterial ? $existing->fastener_type : $material?->fastenerType();
        if ($type === null) {
            throw ValidationException::withMessages(['catalog_material_id' => 'Выберите крепёж с размерами из каталога.']);
        }
        $diameter = $sameMaterial ? $existing->diameter : (float) $material->fastener_diameter;
        $length = $sameMaterial ? $existing->length : (float) $material->fastener_length;
        $part = $source->partDefinition;
        [$uSize, $vSize, $depth] = match ($attributes['face']) {
            'top', 'bottom' => [$part->length, $part->width, $part->thickness],
            'left', 'right' => [$part->length, $part->thickness, $part->width],
            'start', 'end' => [$part->width, $part->thickness, $part->length],
        };
        if ((float) $attributes['sink'] > $depth) {
            throw ValidationException::withMessages(['sink' => 'Посадка глубже исходной детали.']);
        }

        $spacing = (float) ($attributes['spacing'] ?? 0);
        if ($count > 1 && (! isset($attributes['row_axis']) || $spacing < $diameter)) {
            throw ValidationException::withMessages(['spacing' => 'Укажите направление ряда и шаг не меньше диаметра крепежа.']);
        }
        $axis = $attributes['row_axis'] ?? 'u';
        $radius = $diameter / 2;

        return array_map(function (int $index) use ($attributes, $axis, $spacing, $uSize, $vSize, $radius, $length, $diameter, $type, $rowTargets): array {
            $u = round((float) $attributes['center_u'] + ($axis === 'u' ? $index * $spacing : 0), 2);
            $v = round((float) $attributes['center_v'] + ($axis === 'v' ? $index * $spacing : 0), 2);
            if ($u < $radius || $u > $uSize - $radius || $v < $radius || $v > $vSize - $radius) {
                throw ValidationException::withMessages(['center_u' => 'Крепёж выходит за рабочую грань. Сместите начало или измените шаг.']);
            }

            return [
                ...Arr::except($attributes, ['count', 'row_axis', 'spacing', 'row_target_instance_ids']),
                'target_instance_id' => $rowTargets[$index],
                'center_u' => $u,
                'center_v' => $v,
                'diameter' => $diameter,
                'length' => $length,
                'fastener_type' => $type,
            ];
        }, range(0, $count - 1));
    }
}
