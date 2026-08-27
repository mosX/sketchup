<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePartDefinitionRequest extends FormRequest
{
    use ValidatesPartOperations;

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
            'name' => ['required', 'string', 'max:255'],
            'material' => ['nullable', 'string', 'max:255'],
            'length' => ['required', 'numeric', 'min:1', 'max:100000'],
            'width' => ['required', 'numeric', 'min:1', 'max:100000'],
            'thickness' => ['required', 'numeric', 'min:1', 'max:10000'],
            'grain_axis' => ['required', 'string', 'in:length,width'],
            ...$this->operationRules(),
        ];
    }

    /**
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return $this->operationValidation(
            (float) $this->input('length', 0),
            (float) $this->input('width', 0),
            (float) $this->input('thickness', 0),
        );
    }
}
