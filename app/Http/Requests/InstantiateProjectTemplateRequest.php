<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InstantiateProjectTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'dimensions' => ['required', 'array:width,depth,height'],
            'dimensions.width' => ['required', 'numeric', 'min:1', 'max:100000'],
            'dimensions.depth' => ['required', 'numeric', 'min:1', 'max:100000'],
            'dimensions.height' => ['required', 'numeric', 'min:1', 'max:100000'],
        ];
    }
}
