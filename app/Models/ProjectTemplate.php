<?php

namespace App\Models;

use Database\Factories\ProjectTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_project_id', 'name', 'description', 'base_dimensions', 'snapshot', 'schema_version'])]
class ProjectTemplate extends Model
{
    /** @use HasFactory<ProjectTemplateFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'schema_version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_dimensions' => 'array',
            'snapshot' => 'array',
            'schema_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'source_project_id');
    }
}
