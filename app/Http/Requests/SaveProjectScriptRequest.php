<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveProjectScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('project')) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'source' => ['present', 'nullable', 'string', 'max:50000'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'parameter_values' => ['sometimes', 'array', 'max:30'],
            'parameter_values.*' => ['numeric', 'between:-100000,100000'],
        ];
    }
}
