<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTemplateResource extends JsonResource
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
            'source_project_id' => $this->source_project_id,
            'name' => $this->name,
            'description' => $this->description,
            'base_dimensions' => $this->base_dimensions,
            'schema_version' => $this->schema_version,
            'part_count' => count($this->snapshot['parts'] ?? []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
