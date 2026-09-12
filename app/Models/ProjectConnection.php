<?php

namespace App\Models;

use Database\Factories\ProjectConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['primary_instance_id', 'secondary_instance_id', 'type', 'label', 'parameters', 'note', 'is_verified', 'machining_status', 'generated_operation_ids'])]
class ProjectConnection extends Model
{
    /** @use HasFactory<ProjectConnectionFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'parameters' => '[]',
        'is_verified' => false,
        'machining_status' => 'pending',
        'generated_operation_ids' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'is_verified' => 'boolean',
            'generated_operation_ids' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function primaryInstance(): BelongsTo
    {
        return $this->belongsTo(PartInstance::class, 'primary_instance_id');
    }

    public function secondaryInstance(): BelongsTo
    {
        return $this->belongsTo(PartInstance::class, 'secondary_instance_id');
    }
}
