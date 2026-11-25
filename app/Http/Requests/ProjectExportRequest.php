<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectExportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('project')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'in:obj,glb,png'],
            'width' => ['sometimes', 'integer', 'between:320,2048'],
            'height' => ['sometimes', 'integer', 'between:240,2048'],
            'view' => ['sometimes', 'in:isometric,front,rear,side,top'],
            'expected_revision' => ['sometimes', 'integer', 'min:1'],
            'metadata' => ['sometimes', 'boolean'],
        ];
    }
}
