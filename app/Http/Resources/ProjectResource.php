<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'scene_data' => $this->scene_data,
            'revision' => $this->revision,
            'render_settings' => (object) ($this->script_data['render_settings'] ?? $this->script_data['base_render_settings'] ?? []),
            'coordinate_system' => [
                'units' => 'millimeters',
                'angles' => 'degrees',
                'up_axis' => 'z',
            ],
            'parts' => PartDefinitionResource::collection($this->whenLoaded('partDefinitions')),
            'assembly_groups' => AssemblyGroupResource::collection($this->whenLoaded('assemblyGroups')),
            'connections' => ProjectConnectionResource::collection($this->whenLoaded('projectConnections')),
            'fasteners' => ProjectFastenerResource::collection($this->whenLoaded('projectFasteners')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
