<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectMaterialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'dry_run' => ['sometimes', 'boolean'],
            'expected_revision' => [Rule::requiredIf($this->has('dry_run') && ! $this->boolean('dry_run')), 'integer', 'min:1'],
            'default_material_id' => ['present', 'nullable', 'integer'],
            'assignments' => ['present', 'array', 'list', 'max:10000'],
            'assignments.*' => ['array:part_id,material_id'],
            'assignments.*.part_id' => ['required', 'integer', 'distinct'],
            'assignments.*.material_id' => ['required', 'integer'],
            'supplies' => ['present', 'array', 'list', 'max:500'],
            'supplies.*' => ['array:material_id,quantity'],
            'supplies.*.material_id' => ['required', 'integer', 'distinct'],
            'supplies.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'between:0.001,1000000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $data = $validator->validated();
                $assignments = collect($data['assignments']);
                $supplies = collect($data['supplies']);
                $materialIds = $assignments->pluck('material_id')->merge($supplies->pluck('material_id'))
                    ->push($data['default_material_id'])->filter()->unique();
                $kinds = $this->user()->catalogMaterials()->whereIn('id', $materialIds)->pluck('kind', 'id');
                /** @var Project $project */
                $project = $this->route('project');
                $partIds = $project->partDefinitions()->whereIn('id', $assignments->pluck('part_id'))->pluck('id')->flip();

                if ($data['default_material_id'] !== null && ! in_array($kinds->get($data['default_material_id']), ['board', 'sheet'], true)) {
                    $validator->errors()->add('default_material_id', 'Выберите доску или лист из своего каталога.');
                }

                foreach ($assignments as $index => $assignment) {
                    if (! $partIds->has($assignment['part_id'])) {
                        $validator->errors()->add("assignments.$index.part_id", 'Такой заготовки нет в проекте.');
                    }
                    if (! in_array($kinds->get($assignment['material_id']), ['board', 'sheet'], true)) {
                        $validator->errors()->add("assignments.$index.material_id", 'Выберите материал из своего каталога.');
                    }
                }

                foreach ($supplies as $index => $supply) {
                    if ($kinds->get($supply['material_id']) !== 'consumable') {
                        $validator->errors()->add("supplies.$index.material_id", 'Выберите расходник из своего каталога.');
                    }
                }
            },
        ];
    }
}
