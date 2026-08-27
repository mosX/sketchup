<?php

namespace App\Models;

use Database\Factories\PartInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'position_x', 'position_y', 'position_z', 'rotation_x', 'rotation_y', 'rotation_z', 'mirrored'])]
class PartInstance extends Model
{
    /** @use HasFactory<PartInstanceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position_x' => 'float',
            'position_y' => 'float',
            'position_z' => 'float',
            'rotation_x' => 'float',
            'rotation_y' => 'float',
            'rotation_z' => 'float',
            'mirrored' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function partDefinition(): BelongsTo
    {
        return $this->belongsTo(PartDefinition::class);
    }
}
