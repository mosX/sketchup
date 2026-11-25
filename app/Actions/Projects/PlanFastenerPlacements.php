<?php

namespace App\Actions\Projects;

use App\Models\CatalogMaterial;
use App\Models\Project;
use App\Models\ProjectFastener;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PlanFastenerPlacements
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function handle(Project $project, array $attributes, ?ProjectFastener $existing = null): array
    {
        $source = $project->partInstances()->with('partDefinition')->find($attributes['source_instance_id']);
        if (! $source) {
            throw ValidationException::withMessages(['source_instance_id' => 'Выберите исходную деталь этого проекта.']);
        }
        $targetId = $attributes['target_instance_id'] ?? null;
        $count = (int) ($attributes['count'] ?? 1);
        $rowTargets = $attributes['row_target_instance_ids'] ?? array_fill(0, $count, $targetId);
        if (count($rowTargets) !== $count) {
            throw ValidationException::withMessages(['row_target_instance_ids' => 'Укажите вторую деталь или пустое значение для каждой точки ряда.']);
        }
        $targetIds = collect($rowTargets)->filter()->map(fn (int|string $id): int => (int) $id)->unique();
        if ($targetIds->contains($source->id) || ($targetIds->isNotEmpty() && $project->partInstances()->whereKey($targetIds->all())->count() !== $targetIds->count())) {
            throw ValidationException::withMessages(['target_instance_id' => 'Выберите вторую деталь этого проекта.']);
        }

        $material = CatalogMaterial::query()->where('user_id', $project->user_id)->where('kind', 'consumable')->find($attributes['catalog_material_id']);
        $sameMaterial = $existing !== null && $existing->catalog_material_id === $material?->id;
        $type = $sameMaterial ? $existing->fastener_type : $material?->fastenerType();
        if ($type === null) {
            throw ValidationException::withMessages(['catalog_material_id' => 'Выберите крепёж с размерами из каталога.']);
        }
        $diameter = $sameMaterial ? $existing->diameter : (float) $material->fastener_diameter;
        $length = $sameMaterial ? $existing->length : (float) $material->fastener_length;
        $part = $source->partDefinition;
        [$uSize, $vSize, $depth] = match ($attributes['face']) {
            'top', 'bottom' => [$part->length, $part->width, $part->thickness],
            'left', 'right' => [$part->length, $part->thickness, $part->width],
            'start', 'end' => [$part->width, $part->thickness, $part->length],
        };
        if ((float) $attributes['sink'] > $depth) {
            throw ValidationException::withMessages(['sink' => 'Посадка глубже исходной детали.']);
        }

        $spacing = (float) ($attributes['spacing'] ?? 0);
        if ($count > 1 && (! isset($attributes['row_axis']) || $spacing < $diameter)) {
            throw ValidationException::withMessages(['spacing' => 'Укажите направление ряда и шаг не меньше диаметра крепежа.']);
        }
        $axis = $attributes['row_axis'] ?? 'u';
        $radius = $diameter / 2;

        return array_map(function (int $index) use ($attributes, $axis, $spacing, $uSize, $vSize, $radius, $length, $diameter, $type, $rowTargets): array {
            $u = round((float) $attributes['center_u'] + ($axis === 'u' ? $index * $spacing : 0), 2);
            $v = round((float) $attributes['center_v'] + ($axis === 'v' ? $index * $spacing : 0), 2);
            if ($u < $radius || $u > $uSize - $radius || $v < $radius || $v > $vSize - $radius) {
                throw ValidationException::withMessages(['center_u' => 'Крепёж выходит за рабочую грань. Сместите начало или измените шаг.']);
            }

            return [
                ...Arr::except($attributes, ['count', 'row_axis', 'spacing', 'row_target_instance_ids']),
                'target_instance_id' => $rowTargets[$index],
                'center_u' => $u,
                'center_v' => $v,
                'diameter' => $diameter,
                'length' => $length,
                'fastener_type' => $type,
            ];
        }, range(0, $count - 1));
    }
}
