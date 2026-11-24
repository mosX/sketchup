<?php

namespace App\Models;

use Database\Factories\CatalogMaterialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'kind', 'length_mm', 'width_mm', 'thickness_mm', 'unit', 'pack_quantity', 'price', 'fastener_type', 'fastener_diameter', 'fastener_length'])]
class CatalogMaterial extends Model
{
    /** @use HasFactory<CatalogMaterialFactory> */
    use HasFactory;

    public function fastenerType(): ?string
    {
        return $this->kind === 'consumable' && $this->fastener_diameter && $this->fastener_length
            ? ($this->fastener_type ?? 'screw')
            : null;
    }

    protected function casts(): array
    {
        return [
            'length_mm' => 'decimal:2',
            'width_mm' => 'decimal:2',
            'thickness_mm' => 'decimal:2',
            'pack_quantity' => 'decimal:3',
            'price' => 'decimal:2',
            'fastener_diameter' => 'decimal:2',
            'fastener_length' => 'decimal:2',
        ];
    }
}
