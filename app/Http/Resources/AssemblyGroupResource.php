<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssemblyGroupResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'is_visible' => $this->is_visible,
            'is_locked' => $this->is_locked,
            'sort_order' => $this->sort_order,
            'instance_count' => $this->whenCounted('instances'),
            'child_count' => $this->whenCounted('children'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
