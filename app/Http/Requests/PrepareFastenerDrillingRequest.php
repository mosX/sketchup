<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PrepareFastenerDrillingRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'fastener_ids' => ['required', 'array', 'list', 'max:50'],
            'fastener_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'clearance_diameter' => ['required', 'numeric', 'decimal:0,2', 'between:0.1,10000'],
            'countersink' => ['sometimes', 'boolean'],
            'countersink_diameter' => [Rule::excludeIf(! $this->boolean('countersink')), 'required', 'numeric', 'decimal:0,2', 'between:0.1,10000', 'gt:clearance_diameter'],
            'countersink_depth' => [Rule::excludeIf(! $this->boolean('countersink')), 'required', 'numeric', 'decimal:0,2', 'between:0.1,10000'],
            'pilot' => ['required', 'boolean'],
            'pilot_diameter' => [Rule::excludeIf(! $this->boolean('pilot')), 'required', 'numeric', 'decimal:0,2', 'between:0.1,10000'],
            'pilot_depth' => [Rule::excludeIf(! $this->boolean('pilot')), 'required', 'numeric', 'decimal:0,2', 'between:0.1,10000'],
            'dry_run' => ['required', 'boolean'],
            'expected_revision' => [Rule::requiredIf(! $this->boolean('dry_run')), 'integer', 'min:1'],
            'remove_operation_ids' => ['sometimes', 'array', 'list', 'min:1', 'max:150'],
            'remove_operation_ids.*' => ['required', 'string', 'distinct', 'regex:/^fastener-[a-f0-9]{24}-(clearance|pilot|countersink)$/D'],
        ];
    }

    public function messages(): array
    {
        return ['countersink_diameter.gt' => 'Диаметр зенковки должен быть больше диаметра проходного отверстия.'];
    }
}
