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

        $fastener = $this->filled('fastener_type');

        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in($this->route('catalogMaterial') ? [$newKind] : ['board', 'sheet', 'consumable'])],
            'length_mm' => $dimensionRules,
            'width_mm' => $dimensionRules,
            'thickness_mm' => $dimensionRules,
            'price' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'between:0,100000000'],
            'unit' => [$consumable ? 'required' : 'exclude', 'string', 'max:24'],
            'pack_quantity' => [$consumable ? 'required' : 'exclude', 'numeric', $fastener ? 'integer' : 'decimal:0,3', 'between:0.001,1000000'],
            'fastener_type' => [$consumable ? 'nullable' : 'exclude', Rule::in(['screw', 'nail'])],
            'fastener_diameter' => [$consumable ? 'nullable' : 'exclude', 'required_with:fastener_type,fastener_length', 'numeric', 'decimal:0,2', 'between:0.5,30'],
            'fastener_length' => [$consumable ? 'nullable' : 'exclude', 'required_with:fastener_type,fastener_diameter', 'numeric', 'decimal:0,2', 'between:1,1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $material = $this->route('catalogMaterial');
        if (($material?->kind ?? $this->input('kind')) !== 'consumable') {
            return;
        }

        $diameter = $this->input('fastener_diameter', $material?->fastener_diameter);
        $length = $this->input('fastener_length', $material?->fastener_length);
        $type = $this->input('fastener_type', $material?->fastenerType());
        if ($diameter === null && $length === null && ! $this->filled('fastener_type')) {
            $type = null;
        } elseif ($diameter !== null || $length !== null) {
            $type ??= 'screw';
        }
        $this->merge(['fastener_type' => $type, 'fastener_diameter' => $diameter, 'fastener_length' => $length]);

        if ($type !== null) {
            $this->merge(['unit' => 'шт.']);
        }
    }
}
