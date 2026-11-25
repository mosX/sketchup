<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectFastenerRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('project'));
    }

    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['ids' => ['required', 'array', 'list', 'max:50'], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']];
        }

        return [
            'source_instance_id' => ['required', 'integer', 'min:1'],
            'target_instance_id' => ['nullable', 'integer', 'min:1', 'different:source_instance_id'],
            'row_target_instance_ids' => [$this->isMethod('PUT') ? 'prohibited' : 'sometimes', 'array', 'list', 'min:1', 'max:50', 'prohibits:target_instance_id'],
            'row_target_instance_ids.*' => ['nullable', 'integer', 'min:1', 'different:source_instance_id'],
            'catalog_material_id' => ['required', 'integer', 'min:1'],
            'face' => ['required', 'in:top,bottom,left,right,start,end'],
            'center_u' => ['required', 'numeric', 'decimal:0,2', 'between:0,100000'],
            'center_v' => ['required', 'numeric', 'decimal:0,2', 'between:0,100000'],
            'sink' => ['required', 'numeric', 'decimal:0,2', 'between:0,1000'],
            'count' => [$this->isMethod('PUT') ? 'prohibited' : 'sometimes', 'integer', 'between:1,50'],
            'row_axis' => ['required_with:spacing', 'in:u,v'],
            'spacing' => ['required_with:row_axis', 'numeric', 'decimal:0,2', 'between:0.1,100000'],
            'dry_run' => [$this->isMethod('POST') ? 'sometimes' : 'prohibited', 'boolean'],
            'expected_revision' => [Rule::requiredIf($this->isMethod('POST') && $this->has('dry_run') && ! $this->boolean('dry_run')), 'integer', 'min:1'],
            'suggest_targets' => [$this->isMethod('POST') && $this->boolean('dry_run') ? 'sometimes' : 'prohibited', 'boolean'],
        ];
    }
}
