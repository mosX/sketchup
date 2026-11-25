<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RunProjectScriptRequest extends ExecuteProjectCommandsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $connectionRules = [];
        foreach (StoreProjectConnectionRequest::definitionRules() as $field => $rules) {
            $connectionRules['connections.*.'.$field] = $rules;
        }

        return [
            ...parent::rules(),
            'source' => ['present', 'nullable', 'string', 'max:50000'],
            'commands' => ['present', 'array', 'list', 'max:99'],
            'commands.*.type' => ['required', 'string', Rule::in(['create_part', 'create_instance', 'create_group'])],
            'commands.*.temporary_id' => ['required', 'string', 'max:100', 'distinct', 'not_in:__script_root'],
            'commands.*.part_id' => ['prohibited'],
            'commands.*.instance_id' => ['prohibited'],
            'commands.*.group_id' => ['prohibited'],
            'commands.*.parent_group_id' => ['prohibited'],
            'parameter_values' => ['sometimes', 'array', 'max:30'],
            'parameter_values.*' => ['numeric', 'between:-100000,100000'],
            'connections' => ['sometimes', 'array', 'list', 'max:30'],
            'connections.*' => ['array:primary_ref,secondary_ref,type,label,note,is_verified,parameters,generate_machining'],
            'connections.*.primary_ref' => ['required', 'string', 'max:100'],
            'connections.*.secondary_ref' => ['required', 'string', 'max:100'],
            'connections.*.generate_machining' => ['sometimes', 'boolean'],
            'connections.*.note' => ['nullable', 'string', 'max:5000'],
            'connections.*.is_verified' => ['sometimes', 'boolean'],
            ...$connectionRules,
            ...$this->scriptDetailsRules(),
        ];
    }

    /** @return array<string, array<mixed>> */
    private function scriptDetailsRules(): array
    {
        $reference = ['required', 'string', 'max:100'];
        $id = ['required', 'integer', 'min:1'];
        $list = ['sometimes', 'nullable', 'array', 'list', 'max:1000'];
        $rules = [
            'fasteners' => ['sometimes', 'array', 'list', 'max:100'],
            'fasteners.*' => ['array:ref,source_ref,target_ref,row_target_refs,placement,drilling'],
            'fasteners.*.ref' => [...$reference, 'distinct'],
            'fasteners.*.source_ref' => $reference,
            'fasteners.*.target_ref' => ['nullable', 'string', 'max:100', 'prohibits:fasteners.*.row_target_refs'],
            'fasteners.*.row_target_refs' => ['sometimes', 'array', 'list', 'min:1', 'max:50'],
            'fasteners.*.row_target_refs.*' => ['nullable', 'string', 'max:100'],
            'fasteners.*.placement' => ['required', 'array:catalog_material_id,face,center_u,center_v,sink,count,row_axis,spacing'],
            'fasteners.*.drilling' => ['nullable', 'array:clearance_diameter,pilot,pilot_diameter,pilot_depth,countersink,countersink_diameter,countersink_depth'],
            'materials' => ['sometimes', 'array:default_material_id,assignments,supplies'],
            'materials.default_material_id' => ['nullable', 'integer', 'min:1'],
            'materials.assignments' => ['sometimes', 'array', 'list', 'max:99'],
            'materials.assignments.*' => ['array:part_ref,material_id'],
            'materials.assignments.*.part_ref' => [...$reference, 'distinct'],
            'materials.assignments.*.material_id' => $id,
            'materials.supplies' => ['sometimes', 'array', 'list', 'max:500'],
            'materials.supplies.*' => ['array:material_id,quantity'],
            'materials.supplies.*.material_id' => [...$id, 'distinct'],
            'materials.supplies.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'between:0.001,1000000'],
            'assembly_steps' => ['sometimes', 'array', 'list', 'max:200'],
            'assembly_steps.*' => ['array:title,instruction,notes,instance_refs,highlight_refs,fastener_refs,camera'],
            'assembly_steps.*.title' => ['required', 'string', 'max:255'],
            'assembly_steps.*.instruction' => ['nullable', 'string', 'max:5000'],
            'assembly_steps.*.notes' => ['nullable', 'string', 'max:5000'],
            'assembly_steps.*.instance_refs' => ['present', 'array', 'list', 'max:1000'],
            'assembly_steps.*.instance_refs.*' => $reference,
            'assembly_steps.*.highlight_refs' => $list,
            'assembly_steps.*.highlight_refs.*' => $reference,
            'assembly_steps.*.fastener_refs' => $list,
            'assembly_steps.*.fastener_refs.*' => $reference,
            'render_settings' => ['sometimes', 'array:wood,finish,light,exposure,keyPower,fillPower,azimuth,elevation'],
            'render_settings.wood' => ['sometimes', 'in:pine,spruce,oak,dark'],
            'render_settings.finish' => ['sometimes', 'in:raw,oil,lacquer'],
            'render_settings.light' => ['sometimes', 'in:studio,daylight,contrast'],
            'render_settings.exposure' => ['sometimes', 'numeric', 'between:-2,1.5'],
            'render_settings.keyPower' => ['sometimes', 'numeric', 'between:0,200'],
            'render_settings.fillPower' => ['sometimes', 'numeric', 'between:0,200'],
            'render_settings.azimuth' => ['sometimes', 'numeric', 'between:-180,180'],
            'render_settings.elevation' => ['sometimes', 'numeric', 'between:-35,30'],
        ];
        foreach (UpdateAssemblyInstructionsRequest::stepRules() as $field => $constraints) {
            if (str_starts_with($field, 'steps.*.camera')) {
                $rules['assembly_'.$field] = array_map(fn (mixed $rule): mixed => is_string($rule) ? str_replace(':steps.', ':assembly_steps.', $rule) : $rule, $constraints);
            }
        }

        return $rules;
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach (parent::after() as $validate) {
                $validate($validator);
            }
            $operationCount = 0;
            foreach ($this->input('commands') as $command) {
                $operations = $command['data']['operations'] ?? [];
                if (is_array($operations)) {
                    $operationCount += count($operations);
                }
            }
            if ($operationCount > 100) {
                $validator->errors()->add('commands', 'Сценарий может содержать не более 100 операций обработки.');
            }
        }];
    }
}
