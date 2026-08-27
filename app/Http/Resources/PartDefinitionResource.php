<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartDefinitionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'material' => $this->material,
            'dimensions' => [
                'length' => $this->length,
                'width' => $this->width,
                'thickness' => $this->thickness,
            ],
            'grain_axis' => $this->grain_axis,
            'operations' => $this->operations ?? [],
            'instance_count' => $this->whenCounted('instances'),
            'instances' => PartInstanceResource::collection($this->whenLoaded('instances')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
