<?php

namespace App\Actions\Projects;

use App\Exceptions\ProjectRevisionConflictException;
use App\Http\Resources\PartDefinitionResource;
use App\Http\Resources\ProjectConnectionResource;
use App\Http\Resources\ProjectFastenerResource;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunProjectScript
{
    private const array OptionalSections = ['fasteners', 'materials', 'assembly_steps', 'render_settings'];

    public function __construct(
        private ExecuteProjectCommands $executeCommands,
        private CreateScriptConnections $createConnections,
        private CreateScriptDetails $createDetails,
    ) {}

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
            $details = $this->createDetails->handle($project, $payload, $execution['results']);
            $result['fasteners'] = array_merge(...array_values($details['fasteners']));
            $result['sections'] = [];
            $result['render'] = ! empty($payload['render_settings']);
            foreach ($details['sections'] as $name => $value) {
                $result['sections'][$name] = ['previous' => $project->{$name}, 'applied' => $value];
            }
            $variantIds = $project->partInstances()->whereKey($result['instances'])->pluck('part_definition_id')->all();
            $result['parts'] = array_values(array_unique([...$result['parts'], ...$variantIds]));
            $operationCount = $project->partDefinitions()->whereKey($result['parts'])->get()->sum(fn ($part): int => count($part->operations ?? []));
            if ($operationCount > 100) {
                throw ValidationException::withMessages(['commands' => 'Сценарий с обработкой соединений и крепежа превышает лимит 100 операций.']);
            }
            $result['fingerprint'] = $this->fingerprint($project, $result);
            $parts = PartDefinitionResource::collection($project->partDefinitions()
                ->whereKey($result['parts'])->with('instances')->withCount('instances')->get())->resolve();
            $project->refresh()->forceFill([...$details['sections'], 'script_data' => [
                'source' => $payload['source'] ?? '',
                'applied_source' => $payload['source'] ?? '',
                'commands' => $commands,
                'connections' => $payload['connections'] ?? [],
                ...array_intersect_key($payload, array_flip(self::OptionalSections)),
                'base_render_settings' => $previous['base_render_settings'] ?? [],
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
                'fasteners' => ProjectFastenerResource::collection($project->projectFasteners()->whereKey($result['fasteners'])->orderBy('id')->get())->resolve(),
                'assembly_steps' => isset($details['sections']['assembly_instructions']) ? array_slice($details['sections']['assembly_instructions'], -count($payload['assembly_steps'])) : [],
                'material_plan' => $details['sections']['material_plan'] ?? null,
                'render_settings' => (object) ($payload['render_settings'] ?? []),
                'references' => ['commands' => $execution['results'], 'fasteners' => (object) $details['fasteners']],
                'previous' => [
                    'source' => $previous['applied_source'] ?? '', 'commands' => $previous['commands'] ?? [],
                    'connections' => $previous['connections'] ?? [], 'parameter_values' => $previous['applied_parameter_values'] ?? [],
                    ...array_intersect_key($previous, array_flip(self::OptionalSections)),
                ],
                'root_group_id' => $result['groups'][0] ?? null,
                'has_result' => $result['groups'] !== [] || $result['sections'] !== [] || ! empty($payload['render_settings']),
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
        $fastenerIds = $result['fasteners'] ?? [];
        $sections = $result['sections'] ?? [];
        $baseline = [];
        $changedSection = false;
        foreach ($sections as $name => $section) {
            $changedSection = $changedSection || $project->{$name} != $section['applied'];
            $baseline[$name] = $section['previous'];
        }
        $guide = isset($sections['assembly_instructions']) ? $baseline['assembly_instructions'] : $project->assembly_instructions;
        $materials = isset($sections['material_plan']) ? $baseline['material_plan'] : $project->material_plan;
        $hasExternalReferences = $project->partInstances()->whereNotIn('id', $instanceIds)
            ->where(fn ($query) => $query->whereIn('part_definition_id', $partIds)->orWhereIn('assembly_group_id', $groupIds))->exists()
            || $project->assemblyGroups()->whereNotIn('id', $groupIds)->whereIn('parent_id', $groupIds)->exists()
            || $project->projectConnections()->whereNotIn('id', $result['connections'] ?? [])->where(fn ($query) => $query
                ->whereIn('primary_instance_id', $instanceIds)->orWhereIn('secondary_instance_id', $instanceIds))->exists()
            || $project->projectFasteners()->whereNotIn('id', $fastenerIds)->where(fn ($query) => $query
                ->whereIn('source_instance_id', $instanceIds)->orWhereIn('target_instance_id', $instanceIds))->exists()
            || array_intersect(array_column($materials['assignments'] ?? [], 'part_id'), $partIds) !== [];
        foreach ($guide ?? [] as $step) {
            if (array_intersect([...$step['instance_ids'], ...($step['highlight_instance_ids'] ?? [])], $instanceIds) !== []
                || array_intersect($step['fastener_ids'] ?? [], $fastenerIds) !== []) {
                $hasExternalReferences = true;
                break;
            }
        }
        if ($changedSection || $hasExternalReferences || $this->fingerprint($project, $result) !== $result['fingerprint']) {
            throw ValidationException::withMessages(['commands' => ['Результат сценария изменён вручную или используется другими деталями. Отвяжите результат, чтобы сохранить его и выполнить сценарий заново.']]);
        }
        if ($baseline !== []) {
            $project->forceFill($baseline)->save();
        }
        $project->projectFasteners()->whereKey($fastenerIds)->delete();
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
        if (array_key_exists('fasteners', $result)) {
            $records[] = $project->projectFasteners()->whereKey($result['fasteners'])->orderBy('id')->get()->map->getAttributes()->all();
        }

        return hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
    }
}
