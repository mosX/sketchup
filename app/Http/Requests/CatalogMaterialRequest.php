<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $material = $this->route('catalogMaterial');

        return $material === null || $material->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        $newKind = $this->route('catalogMaterial')?->kind ?? $this->input('kind');
        $consumable = $newKind === 'consumable';
        $dimensionRules = [$consumable ? 'exclude' : 'required', 'numeric', 'decimal:0,2', 'between:0.01,50000'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in($this->route('catalogMaterial') ? [$newKind] : ['board', 'sheet', 'consumable'])],
            'length_mm' => $dimensionRules,
            'width_mm' => $dimensionRules,
            'thickness_mm' => $dimensionRules,
            'price' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'between:0,100000000'],
            'unit' => [$consumable ? 'required' : 'exclude', 'string', 'max:24'],
            'pack_quantity' => [$consumable ? 'required' : 'exclude', 'numeric', 'decimal:0,3', 'between:0.001,1000000'],
        ];
    }
}
