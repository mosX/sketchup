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
            'connections.*' => ['array:primary_ref,secondary_ref,type,label,parameters,generate_machining'],
            'connections.*.primary_ref' => ['required', 'string', 'max:100'],
            'connections.*.secondary_ref' => ['required', 'string', 'max:100'],
            'connections.*.generate_machining' => ['sometimes', 'boolean'],
            ...$connectionRules,
        ];
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
