<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\CheckAssemblySequence;
use App\Exceptions\ProjectRevisionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAssemblyInstructionsRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectAssemblyInstructionsController extends Controller
{
    public function check(Request $request, Project $project, CheckAssemblySequence $check): JsonResponse
    {
        Gate::authorize('view', $project);
        $expected = $request->validate(['expected_revision' => ['sometimes', 'integer', 'min:1']])['expected_revision'] ?? null;

        return response()->json(['data' => $check->handle($project, $expected === null ? null : (int) $expected)]);
    }

    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $members = $project->partInstances()->orderBy('id')->get(['id', 'assembly_group_id'])
            ->groupBy(fn ($instance) => $instance->assembly_group_id ?? 'ungrouped');
        $groups = $project->assemblyGroups()->orderBy('sort_order')->orderBy('id')->pluck('name', 'id');
        $suggested = [];
        foreach (['ungrouped' => 'Основа изделия'] + $groups->all() as $group => $title) {
            $ids = $members->get($group)?->pluck('id')->all() ?? [];
            if ($ids !== []) {
                $suggested[] = $this->normalizeStep(['title' => $title, 'instance_ids' => $ids]);
            }
        }

        return response()->json([
            'data' => $project->assembly_instructions ?? [],
            'suggested_steps' => $suggested,
            'revision' => $project->revision,
        ]);
    }

    public function update(UpdateAssemblyInstructionsRequest $request, Project $project): JsonResponse
    {
        $steps = array_map($this->normalizeStep(...), $request->validated('steps'));
        $expected = $request->validated('expected_revision');
        $dryRun = $request->boolean('dry_run', true);
        $revision = DB::transaction(function () use ($project, $steps, $expected, $dryRun): int {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($expected !== null && (int) $expected !== $locked->revision) {
                throw new ProjectRevisionConflictException((int) $expected, $locked->revision);
            }

            $this->validateReferences($locked, $steps);
            if (! $dryRun && $steps !== array_map($this->normalizeStep(...), $locked->assembly_instructions ?? [])) {
                $locked->assembly_instructions = $steps;
                $locked->revision++;
                $locked->save();
            }

            return $locked->revision;
        });

        return response()->json(['data' => $steps, 'revision' => $revision, 'dry_run' => $dryRun]);
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function normalizeStep(array $step): array
    {
        $ids = fn (array $values): array => array_values(array_unique(array_map(intval(...), $values)));
        $point = fn (array $values): array => ['x' => (float) $values['x'], 'y' => (float) $values['y'], 'z' => (float) $values['z']];
        $camera = $step['camera'] ?? null;

        return [
            'title' => $step['title'],
            'instruction' => $step['instruction'] ?? null,
            'notes' => $step['notes'] ?? null,
            'instance_ids' => $ids($step['instance_ids']),
            'highlight_instance_ids' => isset($step['highlight_instance_ids']) ? $ids($step['highlight_instance_ids']) : null,
            'fastener_ids' => isset($step['fastener_ids']) ? $ids($step['fastener_ids']) : null,
            'camera' => $camera === null ? null : [
                'position' => $point($camera['position']),
                'target' => $point($camera['target']),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $steps */
    private function validateReferences(Project $project, array $steps): void
    {
        $fields = ['instance_ids', 'highlight_instance_ids', 'fastener_ids'];
        $ids = collect($steps)->flatMap(fn (array $step): array => [...$step['instance_ids'], ...($step['highlight_instance_ids'] ?? [])])->unique();
        $fastenerIds = collect($steps)->flatMap(fn (array $step): array => $step['fastener_ids'] ?? [])->unique();
        $instanceLookup = $project->partInstances()->whereIntegerInRaw('id', $ids)->pluck('id')->flip();
        $fastenerLookup = $project->projectFasteners()->whereIntegerInRaw('id', $fastenerIds)->pluck('id')->flip();
        $errors = [];
        foreach ($steps as $index => $step) {
            foreach ($fields as $field) {
                $lookup = $field === 'fastener_ids' ? $fastenerLookup : $instanceLookup;
                $invalid = array_filter($step[$field] ?? [], fn (int $id): bool => ! $lookup->has($id));
                if ($invalid !== []) {
                    $errors["steps.$index.$field"] = ['Unknown IDs in this project: '.implode(', ', $invalid).'.'];
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
