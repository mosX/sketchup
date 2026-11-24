<?php

namespace App\Models;

use Database\Factories\CatalogMaterialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'kind', 'length_mm', 'width_mm', 'thickness_mm', 'unit', 'pack_quantity', 'price'])]
class CatalogMaterial extends Model
{
    /** @use HasFactory<CatalogMaterialFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'length_mm' => 'decimal:2',
            'width_mm' => 'decimal:2',
            'thickness_mm' => 'decimal:2',
            'pack_quantity' => 'decimal:3',
            'price' => 'decimal:2',
        ];
    }
}
