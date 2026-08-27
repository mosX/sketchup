<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartInstanceResource extends JsonResource
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
            'part_definition_id' => $this->part_definition_id,
            'position' => [
                'x' => $this->position_x,
                'y' => $this->position_y,
                'z' => $this->position_z,
            ],
            'rotation' => [
                'x' => $this->rotation_x,
                'y' => $this->rotation_y,
                'z' => $this->rotation_z,
            ],
            'mirrored' => $this->mirrored,
        ];
    }
}
