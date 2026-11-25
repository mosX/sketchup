<?php

namespace App\Actions\Projects;

use App\Exceptions\ProjectRevisionConflictException;
use App\Http\Resources\AssemblyGroupResource;
use App\Http\Resources\PartDefinitionResource;
use App\Http\Resources\ProjectFastenerResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class AnalyzeProjectGeometry
{
    /** @param list<array<string, mixed>>|null $placements */
    public function handle(Project $project, Request $request, ?array $placements = null): array
    {
        $fasteners = $placements ?? $this->fasteners($project, $request);
        if ($fasteners === []) {
            return ['total' => 0, 'unchecked' => 0, 'issueCount' => 0, 'pending' => false, 'approximate' => false, 'issues' => []];
        }

        return $this->run([
            ...$this->snapshot($project, $request),
            'fasteners' => $fasteners,
            'preview' => $placements !== null,
        ]);
    }

    /** @return array<string, mixed> */
    public function checkAssembly(Project $project, Request $request, ?int $expectedRevision): array
    {
        [$revision, $payload] = DB::transaction(function () use ($project, $request, $expectedRevision): array {
            $current = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($expectedRevision !== null && $expectedRevision !== $current->revision) {
                throw new ProjectRevisionConflictException($expectedRevision, $current->revision);
            }

            return [$current->revision, [
                ...$this->snapshot($current, $request),
                'fasteners' => $this->fasteners($current, $request),
                'assembly' => true,
            ]];
        });

        return ['project_id' => $project->id, 'revision' => $revision, ...$this->run($payload)];
    }

    /** @return array<string, mixed> */
    private function snapshot(Project $project, Request $request): array
    {
        return [
            'parts' => PartDefinitionResource::collection($project->partDefinitions()->with('instances')->get())->resolve($request),
            'groups' => AssemblyGroupResource::collection($project->assemblyGroups()->get())->resolve($request),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fasteners(Project $project, Request $request): array
    {
        return ProjectFastenerResource::collection($project->projectFasteners()->orderBy('id')->get())->resolve($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function run(array $payload): array
    {
        $message = empty($payload['assembly']) ? 'Fastener geometry analysis is unavailable.' : 'Assembly geometry analysis is unavailable.';
        try {
            $result = Process::path(base_path())->timeout(20)->input(json_encode($payload, JSON_THROW_ON_ERROR))
                ->run([config('services.woodworking.node', 'node'), resource_path('js/editor/fastenerAnalysisCli.js')]);
            if ($result->successful()) {
                $report = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($report)) {
                    return $report;
                }
            }
        } catch (RuntimeException|JsonException) {
            abort(503, $message);
        }

        abort(503, $message);
    }
}
