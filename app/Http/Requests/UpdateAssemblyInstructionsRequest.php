<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAssemblyInstructionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...self::stepRules(),
            'dry_run' => ['sometimes', 'boolean'],
            'expected_revision' => [Rule::requiredIf(! $this->boolean('dry_run', true)), 'integer', 'min:1'],
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function stepRules(): array
    {
        $rules = [
            'steps' => ['present', 'array', 'list', 'max:200'],
            'steps.*' => ['array:title,instruction,notes,instance_ids,highlight_instance_ids,fastener_ids,camera'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.instruction' => ['nullable', 'string', 'max:5000'],
            'steps.*.notes' => ['nullable', 'string', 'max:5000'],
            'steps.*.instance_ids' => ['present', 'array', 'list', 'max:1000'],
            'steps.*.highlight_instance_ids' => ['nullable', 'array', 'list', 'max:1000'],
            'steps.*.fastener_ids' => ['nullable', 'array', 'list', 'max:1000'],
            'steps.*.instance_ids.*' => ['required', 'integer', 'min:1'],
            'steps.*.highlight_instance_ids.*' => ['required', 'integer', 'min:1'],
            'steps.*.fastener_ids.*' => ['required', 'integer', 'min:1'],
            'steps.*.camera' => ['nullable', 'array:position,target', 'required_array_keys:position,target'],
        ];

        foreach (['position', 'target'] as $point) {
            $path = 'steps.*.camera.'.$point;
            $rules[$path] = ['array:x,y,z', 'required_array_keys:x,y,z'];
            foreach (['x', 'y', 'z'] as $axis) {
                $rules[$path.'.'.$axis] = ['required_with:steps.*.camera', 'numeric', 'between:-10000000,10000000'];
            }
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($validator->validated()['steps'] as $index => $step) {
                $camera = $step['camera'] ?? null;
                if ($camera !== null && $camera['position'] == $camera['target']) {
                    $validator->errors()->add("steps.$index.camera", 'Camera position and target must differ.');
                }
            }
        }];
    }
}
