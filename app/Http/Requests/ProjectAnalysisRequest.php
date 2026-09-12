<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectAnalysisRequest extends FormRequest
{
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
            'kerf_mm' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'edge_margin_mm' => ['sometimes', 'numeric', 'min:0', 'max:500'],
            'linear_stock_length_mm' => ['sometimes', 'numeric', 'min:100', 'max:50000'],
            'sheet_length_mm' => ['sometimes', 'numeric', 'min:100', 'max:10000'],
            'sheet_width_mm' => ['sometimes', 'numeric', 'min:100', 'max:10000'],
        ];
    }
}
