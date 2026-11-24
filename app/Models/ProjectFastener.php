<?php

namespace App\Models;

use Database\Factories\ProjectFastenerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source_instance_id', 'target_instance_id', 'catalog_material_id', 'face', 'center_u', 'center_v', 'sink', 'fastener_type', 'diameter', 'length'])]
class ProjectFastener extends Model
{
    /** @use HasFactory<ProjectFastenerFactory> */
    use HasFactory;

    protected $attributes = ['fastener_type' => 'screw'];

    protected function casts(): array
    {
        return ['center_u' => 'float', 'center_v' => 'float', 'sink' => 'float', 'diameter' => 'float', 'length' => 'float'];
    }
}
