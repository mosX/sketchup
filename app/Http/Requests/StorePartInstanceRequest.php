<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePartInstanceRequest extends FormRequest
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
            'quantity' => ['sometimes', 'integer', 'between:1,20'],
            'position_x' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'position_y' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'position_z' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'rotation_x' => ['sometimes', 'numeric', 'between:-360,360'],
            'rotation_y' => ['sometimes', 'numeric', 'between:-360,360'],
            'rotation_z' => ['sometimes', 'numeric', 'between:-360,360'],
            'mirrored' => ['sometimes', 'boolean'],
        ];
    }
}
