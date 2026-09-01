<?php

namespace App\Http\Requests;

use App\Models\AssemblyGroup;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAssemblyGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists(AssemblyGroup::class, 'id')->where('project_id', $this->route('project')?->id),
            ],
            'is_visible' => ['sometimes', 'boolean'],
            'is_locked' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('parent_id') || $this->input('parent_id') === null) {
                return;
            }

            $group = $this->route('assemblyGroup');
            $project = $this->route('project');

            if (! $group instanceof AssemblyGroup || ! $project instanceof Project) {
                return;
            }

            $parentById = $project->assemblyGroups()->pluck('parent_id', 'id');
            $candidateId = (int) $this->input('parent_id');

            while ($candidateId !== 0) {
                if ($candidateId === $group->id) {
                    $validator->errors()->add('parent_id', 'Сборочный узел нельзя переместить внутрь самого себя или своего дочернего узла.');

                    return;
                }

                $candidateId = (int) ($parentById[$candidateId] ?? 0);
            }
        }];
    }
}
