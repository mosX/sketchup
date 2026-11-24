<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CopyProjectFastenersRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('project'));
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'fastener_ids' => ['required', 'array', 'list', 'min:2', 'max:50'],
            'fastener_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'source_instance_id' => ['required', 'integer', 'min:1'],
            'target_instance_id' => ['nullable', 'integer', 'min:1', 'different:source_instance_id'],
            'row_target_instance_ids' => ['sometimes', 'array', 'list', 'min:2', 'max:50', 'prohibits:target_instance_id'],
            'row_target_instance_ids.*' => ['nullable', 'integer', 'min:1', 'different:source_instance_id'],
            'face' => ['required', 'in:top,bottom,left,right,start,end'],
            'mirror_u' => ['sometimes', 'boolean'],
            'mirror_v' => ['sometimes', 'boolean'],
            'dry_run' => ['required', 'boolean'],
            'expected_revision' => [Rule::requiredIf(! $this->boolean('dry_run')), 'integer', 'min:1'],
        ];
    }
}
