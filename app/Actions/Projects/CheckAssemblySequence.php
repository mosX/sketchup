<?php

namespace App\Actions\Projects;

use App\Exceptions\ProjectRevisionConflictException;
use App\Models\PartInstance;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class CheckAssemblySequence
{
    /** @return array<string, mixed> */
    public function handle(Project $project, ?int $expectedRevision): array
    {
        [$revision, $snapshot] = DB::transaction(function () use ($project, $expectedRevision): array {
            $current = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($expectedRevision !== null && $expectedRevision !== $current->revision) {
                throw new ProjectRevisionConflictException($expectedRevision, $current->revision);
            }

            return [$current->revision, [
                'steps' => $current->assembly_instructions ?? [],
                'instances' => $current->partInstances()->with('partDefinition:id,name')->orderBy('id')
                    ->get(['id', 'part_definition_id'])->map(fn (PartInstance $instance): array => [
                        'id' => $instance->id,
                        'name' => $instance->partDefinition->name,
                    ])->all(),
                'fasteners' => $current->projectFasteners()->orderBy('id')
                    ->get(['id', 'source_instance_id', 'target_instance_id'])->toArray(),
            ]];
        });

        try {
            $result = Process::path(base_path())->timeout(10)->input(json_encode($snapshot, JSON_THROW_ON_ERROR))
                ->run([config('services.woodworking.node', 'node'), resource_path('js/editor/assemblySequenceCli.js')]);
            $report = $result->successful() ? json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (RuntimeException|JsonException) {
            abort(503, 'Assembly sequence check is unavailable.');
        }
        abort_unless(is_array($report), 503, 'Assembly sequence check is unavailable.');

        return [...$report, 'project_id' => $project->id, 'revision' => $revision];
    }
}
