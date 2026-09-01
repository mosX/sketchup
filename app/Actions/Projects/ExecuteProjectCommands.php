<?php

namespace App\Actions\Projects;

use App\Actions\AssemblyGroups\DeleteAssemblyGroup;
use App\Exceptions\ProjectRevisionConflictException;
use App\Models\AssemblyGroup;
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
    public function __construct(
        private ProjectDiagnostics $diagnostics,
        private DeleteAssemblyGroup $deleteAssemblyGroup,
    ) {}

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
            $groupReferences = [];
            $results = [];

            foreach ($payload['commands'] as $index => $command) {
                $results[] = $this->executeCommand(
                    $lockedProject,
                    $command,
                    $index,
                    $partReferences,
                    $instanceReferences,
                    $groupReferences,
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
     * @param  array<string, AssemblyGroup>  $groupReferences
     * @return array<string, mixed>
     */
    private function executeCommand(
        Project $project,
        array $command,
        int $index,
        array &$partReferences,
        array &$instanceReferences,
        array &$groupReferences,
    ): array {
        return match ($command['type']) {
            'create_part' => $this->createPart($project, $command, $partReferences),
            'create_instance' => $this->createInstance($project, $command, $index, $partReferences, $instanceReferences, $groupReferences),
            'transform_instance' => $this->transformInstance($project, $command, $index, $instanceReferences),
            'delete_instance' => $this->deleteInstance($project, $command, $index, $instanceReferences),
            'create_group' => $this->createGroup($project, $command, $index, $groupReferences),
            'update_group' => $this->updateGroup($project, $command, $index, $groupReferences),
            'delete_group' => $this->deleteGroup($project, $command, $index, $groupReferences),
            'assign_instance_to_group' => $this->assignInstanceToGroup($project, $command, $index, $instanceReferences, $groupReferences),
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
     * @param  array<string, AssemblyGroup>  $groupReferences
     * @return array<string, mixed>
     */
    private function createInstance(
        Project $project,
        array $command,
        int $index,
        array $partReferences,
        array &$instanceReferences,
        array $groupReferences,
    ): array {
        $part = $this->resolvePart($project, $command, $index, $partReferences);
        $group = $this->resolveGroup($project, $command, $index, $groupReferences);
        $instance = $part->instances()->create([
            ...($command['data'] ?? []),
            'project_id' => $project->id,
            'assembly_group_id' => $group?->id,
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
     * @param  array<string, AssemblyGroup>  $groupReferences
     * @return array<string, mixed>
     */
    private function createGroup(Project $project, array $command, int $index, array &$groupReferences): array
    {
        $parent = $this->resolveGroup(
            $project,
            $command,
            $index,
            $groupReferences,
            'parent_group_id',
            'parent_group_ref',
        );
        $group = $project->assemblyGroups()->create([
            ...$command['data'],
            'parent_id' => $parent?->id,
        ]);
        $temporaryId = $command['temporary_id'] ?? null;

        if ($temporaryId !== null) {
            $groupReferences[$temporaryId] = $group;
        }

        return [
            'type' => 'create_group',
            'temporary_id' => $temporaryId,
            'group_id' => $group->id,
            'parent_id' => $group->parent_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, AssemblyGroup>  $groupReferences
     * @return array<string, mixed>
     */
    private function updateGroup(Project $project, array $command, int $index, array $groupReferences): array
    {
        $group = $this->resolveGroup($project, $command, $index, $groupReferences);

        if (! $group instanceof AssemblyGroup) {
            throw (new ModelNotFoundException)->setModel(AssemblyGroup::class);
        }

        $data = $command['data'];

        if (array_key_exists('parent_id', $data)) {
            $parent = $data['parent_id'] === null
                ? null
                : $project->assemblyGroups()->find($data['parent_id']);

            if ($data['parent_id'] !== null && ! $parent instanceof AssemblyGroup) {
                throw (new ModelNotFoundException)->setModel(AssemblyGroup::class);
            }

            $this->ensureGroupParentIsValid($project, $group, $parent, $index);
        }

        $group->update($data);

        return [
            'type' => 'update_group',
            'group_id' => $group->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, AssemblyGroup>  $groupReferences
     * @return array<string, mixed>
     */
    private function deleteGroup(Project $project, array $command, int $index, array $groupReferences): array
    {
        $group = $this->resolveGroup($project, $command, $index, $groupReferences);

        if (! $group instanceof AssemblyGroup) {
            throw (new ModelNotFoundException)->setModel(AssemblyGroup::class);
        }

        $groupId = $group->id;
        $this->deleteAssemblyGroup->handle($project, $group, (bool) ($command['data']['delete_contents'] ?? false));

        return [
            'type' => 'delete_group',
            'group_id' => $groupId,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, PartInstance>  $instanceReferences
     * @param  array<string, AssemblyGroup>  $groupReferences
     * @return array<string, mixed>
     */
    private function assignInstanceToGroup(
        Project $project,
        array $command,
        int $index,
        array $instanceReferences,
        array $groupReferences,
    ): array {
        $instance = $this->resolveInstance($project, $command, $index, $instanceReferences);
        $group = $this->resolveGroup($project, $command, $index, $groupReferences);
        $instance->update(['assembly_group_id' => $group?->id]);

        return [
            'type' => 'assign_instance_to_group',
            'instance_id' => $instance->id,
            'group_id' => $group?->id,
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

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, AssemblyGroup>  $references
     */
    private function resolveGroup(
        Project $project,
        array $command,
        int $index,
        array $references,
        string $idKey = 'group_id',
        string $referenceKey = 'group_ref',
    ): ?AssemblyGroup {
        if (isset($command[$referenceKey])) {
            return $references[$command[$referenceKey]] ?? throw ValidationException::withMessages([
                "commands.{$index}.{$referenceKey}" => ['The referenced group was not created earlier in this batch.'],
            ]);
        }

        if (! isset($command[$idKey])) {
            return null;
        }

        return $project->assemblyGroups()->find($command[$idKey]) ?? throw (new ModelNotFoundException)->setModel(AssemblyGroup::class);
    }

    private function ensureGroupParentIsValid(
        Project $project,
        AssemblyGroup $group,
        ?AssemblyGroup $parent,
        int $index,
    ): void {
        if ($parent === null) {
            return;
        }

        $parentById = $project->assemblyGroups()->pluck('parent_id', 'id');
        $candidateId = $parent->id;

        while ($candidateId !== 0) {
            if ($candidateId === $group->id) {
                throw ValidationException::withMessages([
                    "commands.{$index}.data.parent_id" => ['A group cannot be moved inside itself or one of its descendants.'],
                ]);
            }

            $candidateId = (int) ($parentById[$candidateId] ?? 0);
        }
    }
}
