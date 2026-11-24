<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectFastenerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'source_instance_id', 'target_instance_id', 'catalog_material_id', 'face', 'center_u', 'center_v', 'sink', 'fastener_type', 'diameter', 'length']);
    }
}
