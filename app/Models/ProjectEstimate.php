<?php

namespace App\Models;

use Database\Factories\ProjectEstimateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['snapshot'])]
class ProjectEstimate extends Model
{
    /** @use HasFactory<ProjectEstimateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
