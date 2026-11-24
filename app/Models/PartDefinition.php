<?php

namespace App\Models;

use Database\Factories\PartDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'material', 'length', 'width', 'thickness', 'grain_axis', 'operations'])]
class PartDefinition extends Model
{
    /** @use HasFactory<PartDefinitionFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'grain_axis' => 'length',
        'operations' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'length' => 'float',
            'width' => 'float',
            'thickness' => 'float',
            'operations' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(PartInstance::class);
    }

    /** @return Builder<ProjectConnection> */
    public function mortiseTenonConnections(): Builder
    {
        return ProjectConnection::query()->where('project_id', $this->project_id)
            ->where('type', 'mortise_tenon')
            ->where(fn (Builder $query) => $query
                ->whereIn('primary_instance_id', $this->instances()->select('id'))
                ->orWhereIn('secondary_instance_id', $this->instances()->select('id')));
    }
}
