<?php

namespace App\Actions\Projects;

use App\Exceptions\ProjectRevisionConflictException;
use App\Http\Resources\PartDefinitionResource;
use App\Http\Resources\ProjectConnectionResource;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunProjectScript
{
    public function __construct(private ExecuteProjectCommands $executeCommands, private CreateScriptConnections $createConnections) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(Project $project, array $payload): array
    {
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($project->revision !== (int) $payload['expected_revision']) {
                throw new ProjectRevisionConflictException((int) $payload['expected_revision'], $project->revision);
            }
            $previous = $project->script_data ?? [];
            $this->removeResult($project, $previous['result'] ?? []);
            $commands = $payload['commands'];
            $batch = [];
            if ($commands !== []) {
                $batch[] = ['type' => 'create_group', 'temporary_id' => '__script_root', 'data' => ['name' => 'Script result']];
            }
            foreach ($commands as $command) {
                if ($command['type'] === 'create_group') {
                    $command['parent_group_ref'] ??= '__script_root';
                } elseif ($command['type'] === 'create_instance') {
                    $command['group_ref'] ??= '__script_root';
                }
                $batch[] = $command;
            }
            $execution = $this->executeCommands->handle($project, [
                'expected_revision' => $project->revision,
                'commands' => $batch,
            ]);
            $result = [
                'parts' => array_values(array_unique(array_column($execution['results'], 'part_id'))),
                'instances' => array_column($execution['results'], 'instance_id'),
                'groups' => array_column($execution['results'], 'group_id'),
            ];
            $result['connections'] = $this->createConnections->handle($project, $payload['connections'] ?? [], $execution['results']);
            $variantIds = $project->partInstances()->whereKey($result['instances'])->pluck('part_definition_id')->all();
            $result['parts'] = array_values(array_unique([...$result['parts'], ...$variantIds]));
            $operationCount = $project->partDefinitions()->whereKey($result['parts'])->get()->sum(fn ($part): int => count($part->operations ?? []));
            if ($operationCount > 100) {
                throw ValidationException::withMessages(['connections' => 'Обработка соединений превышает лимит 100 операций. Разделите изделие на несколько сценариев.']);
            }
            $result['fingerprint'] = $this->fingerprint($project, $result);
            $parts = PartDefinitionResource::collection($project->partDefinitions()
                ->whereKey($result['parts'])->with('instances')->withCount('instances')->get())->resolve();
            $project->refresh()->forceFill(['script_data' => [
                'source' => $payload['source'] ?? '',
                'applied_source' => $payload['source'] ?? '',
                'commands' => $commands,
                'connections' => $payload['connections'] ?? [],
                'parameter_values' => $payload['parameter_values'] ?? [],
                'applied_parameter_values' => $payload['parameter_values'] ?? [],
                'result' => $result,
            ], 'revision' => (int) $payload['expected_revision'] + 1,
            ])->save();
            $dryRun = (bool) ($payload['dry_run'] ?? false);
            $response = [
                'committed' => ! $dryRun,
                'revision' => $dryRun ? (int) $payload['expected_revision'] : $execution['revision'],
                'parts' => $parts,
                'connections' => ProjectConnectionResource::collection($project->projectConnections()->whereKey($result['connections'])->get())->resolve(),
                'previous' => ['source' => $previous['applied_source'] ?? '', 'commands' => $previous['commands'] ?? [], 'connections' => $previous['connections'] ?? [], 'parameter_values' => $previous['applied_parameter_values'] ?? []],
                'root_group_id' => $result['groups'][0] ?? null,
            ];
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $response;
        } catch (Throwable $exception) {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $result */
    private function removeResult(Project $project, array $result): void
    {
        if ($result === []) {
            return;
        }
        $partIds = $result['parts'];
        $instanceIds = $result['instances'];
        $groupIds = $result['groups'];
        $hasExternalReferences = $project->partInstances()->whereNotIn('id', $instanceIds)
            ->where(fn ($query) => $query->whereIn('part_definition_id', $partIds)->orWhereIn('assembly_group_id', $groupIds))->exists()
            || $project->assemblyGroups()->whereNotIn('id', $groupIds)->whereIn('parent_id', $groupIds)->exists()
            || $project->projectConnections()->whereNotIn('id', $result['connections'] ?? [])->where(fn ($query) => $query
                ->whereIn('primary_instance_id', $instanceIds)->orWhereIn('secondary_instance_id', $instanceIds))->exists();
        if ($hasExternalReferences || $this->fingerprint($project, $result) !== $result['fingerprint']) {
            throw ValidationException::withMessages(['commands' => ['Результат сценария изменён вручную или используется другими деталями. Отвяжите результат, чтобы сохранить его и выполнить сценарий заново.']]);
        }
        $project->projectConnections()->whereKey($result['connections'] ?? [])->delete();
        $project->partInstances()->whereKey($instanceIds)->delete();
        $project->assemblyGroups()->whereKey($groupIds)->delete();
        $project->partDefinitions()->whereKey($partIds)->delete();
    }

    /** @param array<string, mixed> $result */
    private function fingerprint(Project $project, array $result): string
    {
        $records = [
            $project->partDefinitions()->whereKey($result['parts'])->orderBy('id')->get()->map->getAttributes()->all(),
            $project->partInstances()->whereKey($result['instances'])->orderBy('id')->get()->map->getAttributes()->all(),
            $project->assemblyGroups()->whereKey($result['groups'])->orderBy('id')->get()->map->getAttributes()->all(),
        ];

        if (array_key_exists('connections', $result)) {
            $records[] = $project->projectConnections()->whereKey($result['connections'])->orderBy('id')->get()->map->getAttributes()->all();
        }

        return hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
    }
}
