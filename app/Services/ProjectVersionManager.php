<?php

namespace App\Services;

use App\Exceptions\ProjectRevisionConflictException;
use App\Models\AssemblyGroup;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectConnection;
use App\Models\ProjectVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectVersionManager
{
    public function capture(Project $project, int $expectedRevision, string $label): ProjectVersion
    {
        return DB::transaction(function () use ($project, $expectedRevision, $label): ProjectVersion {
            $project = $this->lock($project, $expectedRevision);

            return $this->snapshot($project, $label);
        });
    }

    public function restore(Project $project, ProjectVersion $version, int $expectedRevision): Project
    {
        abort_unless($version->project_id === $project->id, 404);

        return DB::transaction(function () use ($project, $version, $expectedRevision): Project {
            $project = $this->lock($project, $expectedRevision);
            $snapshot = $version->snapshot;
            if (($snapshot['schema_version'] ?? null) !== 1) {
                throw ValidationException::withMessages(['version' => 'Эта версия снимка не поддерживается.']);
            }
            $models = ['parts' => PartDefinition::class, 'groups' => AssemblyGroup::class, 'instances' => PartInstance::class, 'connections' => ProjectConnection::class];
            foreach ($models as $key => $model) {
                if ($model::query()->whereIn('id', array_column($snapshot[$key], 'id'))->where('project_id', '!=', $project->id)->exists()) {
                    throw ValidationException::withMessages(['version' => 'Идентификаторы снимка заняты другим проектом. Восстановление отменено.']);
                }
            }
            $this->snapshot($project, 'Перед восстановлением #'.$version->id);
            $project->projectConnections()->delete();
            $project->partInstances()->delete();
            $project->assemblyGroups()->delete();
            $project->partDefinitions()->delete();
            foreach ($models as $key => $model) {
                foreach ($snapshot[$key] as $row) {
                    $row['project_id'] = $project->id;
                    if ($key === 'groups') {
                        $row['parent_id'] = null;
                    }
                    $model::query()->insert($row);
                }
                if ($key === 'groups') {
                    foreach ($snapshot[$key] as $row) {
                        if ($row['parent_id'] !== null) {
                            AssemblyGroup::query()->whereKey($row['id'])->update(['parent_id' => $row['parent_id'], 'updated_at' => $row['updated_at']]);
                        }
                    }
                }
            }
            $project->forceFill([...$snapshot['project'], 'revision' => $expectedRevision + 1])->save();

            return $project->refresh();
        });
    }

    private function lock(Project $project, int $expectedRevision): Project
    {
        $project = Project::query()->lockForUpdate()->findOrFail($project->id);
        if ($project->revision !== $expectedRevision) {
            throw new ProjectRevisionConflictException($expectedRevision, $project->revision);
        }

        return $project;
    }

    private function snapshot(Project $project, string $label): ProjectVersion
    {
        $snapshot = [
            'schema_version' => 1,
            'project' => $project->only(['name', 'description', 'scene_data', 'script_data']),
            'parts' => $project->partDefinitions()->orderBy('id')->get()->map->getAttributes()->all(),
            'groups' => $project->assemblyGroups()->orderBy('id')->get()->map->getAttributes()->all(),
            'instances' => $project->partInstances()->orderBy('id')->get()->map->getAttributes()->all(),
            'connections' => $project->projectConnections()->orderBy('id')->get()->map->getAttributes()->all(),
        ];
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['version' => 'Снимок превышает лимит 10 МБ.']);
        }

        return $project->projectVersions()->create(['label' => $label, 'revision' => $project->revision, 'snapshot' => $snapshot]);
    }
}
