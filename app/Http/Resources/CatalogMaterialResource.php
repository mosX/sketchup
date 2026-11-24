<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'length_mm' => $this->length_mm,
            'width_mm' => $this->width_mm,
            'thickness_mm' => $this->thickness_mm,
            'unit' => $this->unit,
            'pack_quantity' => $this->pack_quantity,
            'price' => $this->price,
        ];
    }
}
