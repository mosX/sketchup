<?php

namespace App\Http\Controllers;

use App\Actions\Projects\RunProjectScript;
use App\Exceptions\ProjectRevisionConflictException;
use App\Http\Requests\RunProjectScriptRequest;
use App\Http\Requests\SaveProjectScriptRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectScriptController extends Controller
{
    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['data' => [
            'source' => $project->script_data['source'] ?? null,
            'parameter_values' => $project->script_data['parameter_values'] ?? [],
            'has_result' => ! empty($project->script_data['result']['groups']),
            'revision' => $project->revision,
        ]]);
    }

    public function update(SaveProjectScriptRequest $request, Project $project): JsonResponse
    {
        return $this->save($request, $project, false);
    }

    public function detach(SaveProjectScriptRequest $request, Project $project): JsonResponse
    {
        return $this->save($request, $project, true);
    }

    public function run(RunProjectScriptRequest $request, Project $project, RunProjectScript $run): JsonResponse
    {
        return response()->json(['data' => $run->handle($project, $request->validated())]);
    }

    private function save(SaveProjectScriptRequest $request, Project $project, bool $detach): JsonResponse
    {
        return DB::transaction(function () use ($request, $project, $detach): JsonResponse {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $data = $request->validated();
            if ($project->revision !== (int) $data['expected_revision']) {
                throw new ProjectRevisionConflictException((int) $data['expected_revision'], $project->revision);
            }
            $script = $detach ? [] : ($project->script_data ?? []);
            $script['source'] = $data['source'] ?? '';
            $script['parameter_values'] = $data['parameter_values'] ?? [];
            $project->forceFill(['script_data' => $script])->save();
            $project->increment('revision');

            return $this->show($project);
        });
    }
}
