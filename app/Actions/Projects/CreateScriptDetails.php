<?php

namespace App\Actions\Projects;

use App\Http\Requests\PrepareFastenerDrillingRequest;
use App\Http\Requests\ProjectFastenerRequest;
use App\Http\Requests\UpdateAssemblyInstructionsRequest;
use App\Models\CatalogMaterial;
use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateScriptDetails
{
    public function __construct(private PlanFastenerPlacements $placements, private PrepareFastenerDrilling $drilling) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $results
     * @return array{fasteners: array<string, list<int>>, sections: array<string, mixed>}
     */
    public function handle(Project $project, array $payload, array $results): array
    {
        $instances = collect($results)->where('type', 'create_instance')->pluck('instance_id', 'temporary_id')->all();
        $fasteners = $this->createFasteners($project, $payload['fasteners'] ?? [], $instances);
        $sections = [];
        if (! empty($payload['materials'])) {
            $sections['material_plan'] = $this->materialPlan($project, $payload['materials'], $results, $instances);
        }
        if (! empty($payload['assembly_steps'])) {
            $steps = $this->assemblySteps($payload['assembly_steps'], $instances, $fasteners);
            $allSteps = [...($project->assembly_instructions ?? []), ...$steps];
            if (count($allSteps) > 200) {
                throw ValidationException::withMessages(['assembly_steps' => 'The complete assembly guide may contain at most 200 steps.']);
            }
            $sections['assembly_instructions'] = $allSteps;
        }

        return ['fasteners' => $fasteners, 'sections' => $sections];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $instances
     * @return array<string, list<int>>
     */
    private function createFasteners(Project $project, array $entries, array $instances): array
    {
        $references = [];
        $count = 0;
        $placementRules = ProjectFastenerRequest::create('/', 'POST')->rules();
        foreach ($entries as $index => $entry) {
            $field = "fasteners.$index";
            try {
                $placement = [
                    ...$entry['placement'],
                    'source_instance_id' => $this->resolve($instances, $entry['source_ref'], 'source_ref'),
                ];
                if (isset($entry['row_target_refs'])) {
                    $placement['row_target_instance_ids'] = array_map(
                        fn (?string $ref): ?int => $ref === null ? null : $this->resolve($instances, $ref, 'row_target_refs'),
                        $entry['row_target_refs'],
                    );
                } elseif (isset($entry['target_ref'])) {
                    $placement['target_instance_id'] = $this->resolve($instances, $entry['target_ref'], 'target_ref');
                }
                $rows = $this->placements->handle($project, Validator::make($placement, $placementRules)->validate());
                $count += count($rows);
                if ($count > 500) {
                    throw ValidationException::withMessages(['placement' => 'Limit: 500 installed fasteners per script.']);
                }
                $ids = [];
                foreach ($rows as $row) {
                    $ids[] = $project->projectFasteners()->create($row)->id;
                }
                $references[$entry['ref']] = $ids;
                if (isset($entry['drilling'])) {
                    $options = [
                        'pilot' => false,
                        ...$entry['drilling'],
                        'dry_run' => false,
                        'fastener_ids' => $ids,
                        'expected_revision' => $project->fresh()->revision,
                    ];
                    $rules = PrepareFastenerDrillingRequest::create('/', 'POST', $options)->rules();
                    $this->drilling->handle($project, Validator::make($options, $rules)->validate());
                }
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => ["$field.$key" => $messages])->all());
            }
        }

        return $references;
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, int>  $instances
     * @return array<string, mixed>
     */
    private function materialPlan(Project $project, array $selection, array $results, array $instances): array
    {
        $plan = $project->material_plan ?? ['default_material_id' => null, 'assignments' => [], 'supplies' => []];
        $assignments = $selection['assignments'] ?? [];
        $supplies = $selection['supplies'] ?? [];
        $ids = [...array_column($assignments, 'material_id'), ...array_column($supplies, 'material_id'), $selection['default_material_id'] ?? null];
        $kinds = CatalogMaterial::query()->where('user_id', $project->user_id)->whereIn('id', array_filter($ids))->pluck('kind', 'id');
        if (array_key_exists('default_material_id', $selection)) {
            $default = $selection['default_material_id'];
            if ($default !== null && ! in_array($kinds->get($default), ['board', 'sheet'], true)) {
                throw ValidationException::withMessages(['materials.default_material_id' => 'Choose a board or sheet from your catalog.']);
            }
            $plan['default_material_id'] = $default === null ? null : (int) $default;
        }

        $partRefs = collect($results)->where('type', 'create_part')->pluck('part_id', 'temporary_id')->all();
        $variants = $project->partInstances()->whereKey(array_values($instances))->pluck('part_definition_id', 'id');
        $variantsByPart = collect($results)->where('type', 'create_instance')->groupBy('part_id');
        foreach ($assignments as $index => $row) {
            $partId = $this->resolve($partRefs, $row['part_ref'], "materials.assignments.$index.part_ref");
            if (! in_array($kinds->get($row['material_id']), ['board', 'sheet'], true)) {
                throw ValidationException::withMessages(["materials.assignments.$index.material_id" => 'Choose a board or sheet from your catalog.']);
            }
            $partIds = [$partId, ...($variantsByPart->get($partId)?->map(fn (array $entry) => $variants->get($entry['instance_id']))->all() ?? [])];
            foreach (array_unique($partIds) as $id) {
                $plan['assignments'][] = ['part_id' => $id, 'material_id' => (int) $row['material_id']];
            }
        }
        $byMaterial = collect($plan['supplies'])->keyBy('material_id');
        foreach ($supplies as $index => $row) {
            $id = (int) $row['material_id'];
            if ($kinds->get($id) !== 'consumable') {
                throw ValidationException::withMessages(["materials.supplies.$index.material_id" => 'Choose a consumable from your catalog.']);
            }
            $quantity = round(($byMaterial->get($id)['quantity'] ?? 0) + (float) $row['quantity'], 3);
            if ($quantity > 1000000) {
                throw ValidationException::withMessages(["materials.supplies.$index.quantity" => 'Combined quantity exceeds 1000000.']);
            }
            $byMaterial->put($id, ['material_id' => $id, 'quantity' => $quantity]);
        }
        $plan['supplies'] = $byMaterial->values()->all();
        if (count($plan['supplies']) > 500 || count($plan['assignments']) > 10000) {
            throw ValidationException::withMessages(['materials' => 'The combined material plan exceeds the project limit.']);
        }

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $instances
     * @param  array<string, list<int>>  $fasteners
     * @return list<array<string, mixed>>
     */
    private function assemblySteps(array $entries, array $instances, array $fasteners): array
    {
        $steps = [];
        foreach ($entries as $index => $entry) {
            $instanceIds = fn (array $refs, string $field): array => array_values(array_unique(array_map(
                fn (string $ref): int => $this->resolve($instances, $ref, "assembly_steps.$index.$field"),
                $refs,
            )));
            $fastenerIds = null;
            if (isset($entry['fastener_refs'])) {
                $fastenerIds = [];
                foreach ($entry['fastener_refs'] as $ref) {
                    if (! isset($fasteners[$ref])) {
                        throw ValidationException::withMessages(["assembly_steps.$index.fastener_refs" => "Unknown fastener reference: $ref."]);
                    }
                    array_push($fastenerIds, ...$fasteners[$ref]);
                }
                $fastenerIds = array_values(array_unique($fastenerIds));
            }
            $steps[] = [
                ...Arr::only($entry, ['title', 'instruction', 'notes', 'camera']),
                'instance_ids' => $instanceIds($entry['instance_refs'], 'instance_refs'),
                'highlight_instance_ids' => isset($entry['highlight_refs']) ? $instanceIds($entry['highlight_refs'], 'highlight_refs') : null,
                'fastener_ids' => $fastenerIds,
            ];
        }
        $validator = Validator::make(['steps' => $steps], UpdateAssemblyInstructionsRequest::stepRules());
        $validator->after((new UpdateAssemblyInstructionsRequest)->after());
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())
                ->mapWithKeys(fn (array $messages, string $key): array => ['assembly_'.$key => $messages])->all());
        }

        return $steps;
    }

    /** @param array<string, int> $lookup */
    private function resolve(array $lookup, string $ref, string $field): int
    {
        return $lookup[$ref] ?? throw ValidationException::withMessages([$field => "Unknown script reference: $ref."]);
    }
}
