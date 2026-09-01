<?php

namespace App\Models;

use Database\Factories\AssemblyGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'name', 'is_visible', 'is_locked', 'sort_order'])]
class AssemblyGroup extends Model
{
    /** @use HasFactory<AssemblyGroupFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_visible' => true,
        'is_locked' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'is_locked' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(PartInstance::class);
    }
}
