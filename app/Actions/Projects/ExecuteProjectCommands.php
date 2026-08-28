<?php

namespace App\Actions\Projects;

use App\Exceptions\ProjectRevisionConflictException;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Services\ProjectDiagnostics;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExecuteProjectCommands
{
    public function __construct(private ProjectDiagnostics $diagnostics) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(Project $project, array $payload): array
    {
        DB::beginTransaction();

        try {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $expectedRevision = (int) $payload['expected_revision'];

            if ($lockedProject->revision !== $expectedRevision) {
                throw new ProjectRevisionConflictException($expectedRevision, $lockedProject->revision);
            }

            $partReferences = [];
            $instanceReferences = [];
            $results = [];

            foreach ($payload['commands'] as $index => $command) {
                $results[] = $this->executeCommand(
                    $lockedProject,
                    $command,
                    $index,
                    $partReferences,
                    $instanceReferences,
                );
            }

            $diagnostics = $this->diagnostics->analyze($lockedProject->fresh());
            $dryRun = (bool) ($payload['dry_run'] ?? false);

            if ($dryRun) {
                DB::rollBack();

                return [
                    'committed' => false,
                    'dry_run' => true,
                    'revision' => $lockedProject->revision,
                    'proposed_revision' => $lockedProject->revision + 1,
                    'results' => $results,
                    'diagnostics' => $diagnostics,
                ];
            }

            $lockedProject->increment('revision');
            DB::commit();

            return [
                'committed' => true,
                'dry_run' => false,
                'revision' => $lockedProject->revision,
                'results' => $results,
                'diagnostics' => $diagnostics,
            ];
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, PartDefinition>  $partReferences
     * @param  array<string, PartInstance>  $instanceReferences
     * @return array<string, mixed>
     */
    private function executeCommand(
        Project $project,
        array $command,
        int $index,
        array &$partReferences,
        array &$instanceReferences,
    ): array {
        return match ($command['type']) {
            'create_part' => $this->createPart($project, $command, $partReferences),
            'create_instance' => $this->createInstance($project, $command, $index, $partReferences, $instanceReferences),
            'transform_instance' => $this->transformInstance($project, $command, $index, $instanceReferences),
            'delete_instance' => $this->deleteInstance($project, $command, $index, $instanceReferences),
        };
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, PartDefinition>  $partReferences
     * @return array<string, mixed>
     */
    private function createPart(Project $project, array $command, array &$partReferences): array
    {
        $part = $project->partDefinitions()->create($command['data']);
        $temporaryId = $command['temporary_id'] ?? null;

        if ($temporaryId !== null) {
            $partReferences[$temporaryId] = $part;
        }

        return [
            'type' => 'create_part',
            'temporary_id' => $temporaryId,
            'part_id' => $part->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, PartDefinition>  $partReferences
     * @param  array<string, PartInstance>  $instanceReferences
     * @return array<string, mixed>
     */
    private function createInstance(Project $project, array $command, int $index, array $partReferences, array &$instanceReferences): array
    {
        $part = $this->resolvePart($project, $command, $index, $partReferences);
        $instance = $part->instances()->create([
            ...($command['data'] ?? []),
            'project_id' => $project->id,
        ]);
        $temporaryId = $command['temporary_id'] ?? null;

        if ($temporaryId !== null) {
            $instanceReferences[$temporaryId] = $instance;
        }

        return [
            'type' => 'create_instance',
            'temporary_id' => $temporaryId,
            'instance_id' => $instance->id,
            'part_id' => $part->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, PartInstance>  $instanceReferences
     * @return array<string, mixed>
     */
    private function transformInstance(Project $project, array $command, int $index, array $instanceReferences): array
    {
        $instance = $this->resolveInstance($project, $command, $index, $instanceReferences);
        $instance->update($command['data']);

        return [
            'type' => 'transform_instance',
            'instance_id' => $instance->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, PartInstance>  $instanceReferences
     * @return array<string, mixed>
     */
    private function deleteInstance(Project $project, array $command, int $index, array $instanceReferences): array
    {
        $instance = $this->resolveInstance($project, $command, $index, $instanceReferences);
        $instanceId = $instance->id;
        $instance->delete();

        return [
            'type' => 'delete_instance',
            'instance_id' => $instanceId,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, PartDefinition>  $references
     */
    private function resolvePart(Project $project, array $command, int $index, array $references): PartDefinition
    {
        if (isset($command['part_ref'])) {
            return $references[$command['part_ref']] ?? throw ValidationException::withMessages([
                "commands.{$index}.part_ref" => ['The referenced part was not created earlier in this batch.'],
            ]);
        }

        return $project->partDefinitions()->find($command['part_id']) ?? throw (new ModelNotFoundException)->setModel(PartDefinition::class);
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, PartInstance>  $references
     */
    private function resolveInstance(Project $project, array $command, int $index, array $references): PartInstance
    {
        if (isset($command['instance_ref'])) {
            return $references[$command['instance_ref']] ?? throw ValidationException::withMessages([
                "commands.{$index}.instance_ref" => ['The referenced instance was not created earlier in this batch.'],
            ]);
        }

        return $project->partInstances()->find($command['instance_id']) ?? throw (new ModelNotFoundException)->setModel(PartInstance::class);
    }
}
