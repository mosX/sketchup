<?php

namespace App\Models;

use Database\Factories\ProjectVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['label', 'revision', 'snapshot'])]
class ProjectVersion extends Model
{
    /** @use HasFactory<ProjectVersionFactory> */
    use HasFactory;

    protected $hidden = ['snapshot'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'revision' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
