<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectConnectionResource extends JsonResource
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
            'primary_instance_id' => $this->primary_instance_id,
            'secondary_instance_id' => $this->secondary_instance_id,
            'type' => $this->type,
            'label' => $this->label,
            'parameters' => $this->parameters,
            'note' => $this->note,
            'is_verified' => $this->is_verified,
            'machining_status' => $this->machining_status,
            'generated_operations' => $this->generated_operation_ids ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
