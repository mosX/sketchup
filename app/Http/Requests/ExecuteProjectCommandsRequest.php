<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ExecuteProjectCommandsRequest extends FormRequest
{
    use ValidatesPartOperations;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('project')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
            'commands' => ['required', 'array', 'min:1', 'max:100'],
            'commands.*' => ['required', 'array:type,temporary_id,part_id,part_ref,instance_id,instance_ref,group_id,group_ref,parent_group_id,parent_group_ref,data'],
            'commands.*.type' => ['required', 'string', Rule::in([
                'create_part',
                'create_instance',
                'transform_instance',
                'delete_instance',
                'create_group',
                'update_group',
                'delete_group',
                'assign_instance_to_group',
            ])],
            'commands.*.temporary_id' => ['sometimes', 'string', 'max:100', 'distinct'],
            'commands.*.part_id' => ['sometimes', 'integer'],
            'commands.*.part_ref' => ['sometimes', 'string', 'max:100'],
            'commands.*.instance_id' => ['sometimes', 'integer'],
            'commands.*.instance_ref' => ['sometimes', 'string', 'max:100'],
            'commands.*.group_id' => ['sometimes', 'integer'],
            'commands.*.group_ref' => ['sometimes', 'string', 'max:100'],
            'commands.*.parent_group_id' => ['sometimes', 'integer'],
            'commands.*.parent_group_ref' => ['sometimes', 'string', 'max:100'],
            'commands.*.data' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('commands', []) as $index => $command) {
                if (! is_array($command) || ! isset($command['type'])) {
                    continue;
                }

                $commandValidator = ValidatorFacade::make($command, $this->commandRules($command['type']));

                if ($command['type'] === 'create_part' && is_array($command['data'] ?? null)) {
                    $data = $command['data'];
                    $commandValidator->after(function (Validator $childValidator) use ($data): void {
                        if ($childValidator->errors()->isNotEmpty()) {
                            return;
                        }
                        $this->validatePartOperations(
                            $childValidator,
                            $data['operations'] ?? [],
                            (float) ($data['length'] ?? 0),
                            (float) ($data['width'] ?? 0),
                            (float) ($data['thickness'] ?? 0),
                            'data.operations',
                        );
                    });
                }

                foreach ($commandValidator->errors()->messages() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add("commands.{$index}.{$field}", $message);
                    }
                }
            }
        }];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function commandRules(string $type): array
    {
        if ($type === 'create_part') {
            return [
                'data' => ['required', 'array:name,material,length,width,thickness,grain_axis,operations'],
                'data.name' => ['required', 'string', 'max:255'],
                'data.material' => ['nullable', 'string', 'max:255'],
                'data.length' => ['required', 'numeric', 'min:1', 'max:100000'],
                'data.width' => ['required', 'numeric', 'min:1', 'max:100000'],
                'data.thickness' => ['required', 'numeric', 'min:1', 'max:10000'],
                'data.grain_axis' => ['required', 'string', 'in:length,width'],
                ...$this->operationRules('data.operations'),
            ];
        }

        if ($type === 'create_instance') {
            return [
                'part_id' => ['required_without:part_ref', 'integer'],
                'part_ref' => ['required_without:part_id', 'string', 'max:100'],
                'group_id' => ['sometimes', 'integer'],
                'group_ref' => ['sometimes', 'string', 'max:100'],
                'data' => ['sometimes', 'array:position_x,position_y,position_z,rotation_x,rotation_y,rotation_z,mirrored'],
                ...$this->transformRules(),
            ];
        }

        if ($type === 'create_group') {
            return [
                'parent_group_id' => ['sometimes', 'integer'],
                'parent_group_ref' => ['sometimes', 'string', 'max:100'],
                'data' => ['required', 'array:name,is_visible,is_locked,sort_order'],
                'data.name' => ['required', 'string', 'max:255'],
                'data.is_visible' => ['sometimes', 'boolean'],
                'data.is_locked' => ['sometimes', 'boolean'],
                'data.sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            ];
        }

        if ($type === 'update_group') {
            return [
                'group_id' => ['required_without:group_ref', 'integer'],
                'group_ref' => ['required_without:group_id', 'string', 'max:100'],
                'data' => ['required', 'array:name,parent_id,is_visible,is_locked,sort_order'],
                'data.name' => ['sometimes', 'string', 'max:255'],
                'data.parent_id' => ['sometimes', 'nullable', 'integer'],
                'data.is_visible' => ['sometimes', 'boolean'],
                'data.is_locked' => ['sometimes', 'boolean'],
                'data.sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            ];
        }

        if ($type === 'delete_group') {
            return [
                'group_id' => ['required_without:group_ref', 'integer'],
                'group_ref' => ['required_without:group_id', 'string', 'max:100'],
                'data' => ['sometimes', 'array:delete_contents'],
                'data.delete_contents' => ['sometimes', 'boolean'],
            ];
        }

        if ($type === 'assign_instance_to_group') {
            return [
                'instance_id' => ['required_without:instance_ref', 'integer'],
                'instance_ref' => ['required_without:instance_id', 'string', 'max:100'],
                'group_id' => ['sometimes', 'integer'],
                'group_ref' => ['sometimes', 'string', 'max:100'],
            ];
        }

        return [
            'instance_id' => ['required_without:instance_ref', 'integer'],
            'instance_ref' => ['required_without:instance_id', 'string', 'max:100'],
            'data' => [Rule::requiredIf($type === 'transform_instance'), 'array:position_x,position_y,position_z,rotation_x,rotation_y,rotation_z,mirrored'],
            ...($type === 'transform_instance' ? $this->transformRules() : []),
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function transformRules(): array
    {
        return [
            'data.position_x' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'data.position_y' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'data.position_z' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'data.rotation_x' => ['sometimes', 'numeric', 'between:-360,360'],
            'data.rotation_y' => ['sometimes', 'numeric', 'between:-360,360'],
            'data.rotation_z' => ['sometimes', 'numeric', 'between:-360,360'],
            'data.mirrored' => ['sometimes', 'boolean'],
        ];
    }
}
