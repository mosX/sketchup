<?php

namespace App\Services;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;

class ProjectDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(Project $project): array
    {
        $project->loadMissing('partDefinitions.instances', 'projectConnections');
        $warnings = [];
        $reviewItems = [];
        $instanceCount = 0;
        $materialVolume = 0.0;
        $bounds = null;
        $instanceGeometry = [];

        if ($project->partDefinitions->isEmpty()) {
            $warnings[] = $this->warning('project_empty', 'The project does not contain any part definitions.');
        }

        $project->partDefinitions->each(function (PartDefinition $part) use (&$bounds, &$instanceCount, &$instanceGeometry, &$materialVolume, &$warnings): void {
            if ($part->instances->isEmpty()) {
                $warnings[] = $this->warning('part_unused', 'The part has no instances in the assembly.', [
                    'part_id' => $part->id,
                    'part_name' => $part->name,
                ]);
            }

            $this->appendThinStockWarnings($part, $warnings);

            $materialVolume += $part->length * $part->width * $part->thickness * $part->instances->count();

            $part->instances->each(function (PartInstance $instance) use ($part, &$bounds, &$instanceCount, &$instanceGeometry, &$warnings): void {
                $instanceCount++;
                $instanceBounds = $this->orientedBounds($part, $instance);

                if ($instanceBounds['min']['z'] < 0) {
                    $warnings[] = $this->warning('instance_below_floor', 'The instance starts below the Z=0 floor plane.', [
                        'instance_id' => $instance->id,
                        'part_id' => $part->id,
                        'position_z' => round($instanceBounds['min']['z'], 2),
                    ]);
                }

                $instanceGeometry[] = [
                    'instance' => $instance,
                    'part' => $part,
                    'bounds' => $instanceBounds,
                ];
                $bounds = $this->mergeBounds($bounds, $instanceBounds);
            });
        });

        $this->appendDuplicateWarnings($instanceGeometry, $warnings);
        $this->appendUnsupportedWarnings($instanceGeometry, $warnings);
        $this->appendIntersectionReviewItems($instanceGeometry, $reviewItems);

        foreach ($project->projectConnections as $connection) {
            if (! $connection->is_verified) {
                $warnings[] = $this->warning('connection_unverified', 'A woodworking connection has not been verified.', [
                    'connection_id' => $connection->id,
                    'connection_type' => $connection->type,
                    'instance_ids' => [$connection->primary_instance_id, $connection->secondary_instance_id],
                ]);
            }
        }

        return [
            'valid' => true,
            'errors' => [],
            'warnings' => $warnings,
            'review_items' => $reviewItems,
            'summary' => [
                'part_count' => $project->partDefinitions->count(),
                'instance_count' => $instanceCount,
                'material_volume_mm3' => round($materialVolume, 2),
                'bounds' => $bounds,
                'bounds_note' => 'Bounds include instance rotation and are axis-aligned in project coordinates.',
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $warnings
     */
    private function appendThinStockWarnings(PartDefinition $part, array &$warnings): void
    {
        foreach ($part->operations ?? [] as $operation) {
            if (! in_array($operation['type'] ?? null, ['groove', 'plunge_route'], true) || ! isset($operation['depth'], $operation['face'])) {
                continue;
            }

            $normalDimension = match ($operation['face']) {
                'top', 'bottom' => $part->thickness,
                'left', 'right' => $part->width,
                'start', 'end' => $part->length,
                default => null,
            };
            $remaining = $normalDimension === null ? null : $normalDimension - (float) $operation['depth'];

            if ($remaining !== null && $remaining > 0 && $remaining < 3) {
                $warnings[] = $this->warning('thin_remaining_stock', 'A machining operation leaves less than 3 mm of material.', [
                    'part_id' => $part->id,
                    'part_name' => $part->name,
                    'operation_id' => $operation['id'] ?? null,
                    'remaining_mm' => round($remaining, 2),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{instance: PartInstance, part: PartDefinition, bounds: array<string, mixed>}>  $geometry
     * @param  array<int, array<string, mixed>>  $warnings
     */
    private function appendDuplicateWarnings(array $geometry, array &$warnings): void
    {
        $seen = [];

        foreach ($geometry as $item) {
            $instance = $item['instance'];
            $signature = implode('|', [
                $item['part']->id,
                $instance->position_x,
                $instance->position_y,
                $instance->position_z,
                $instance->rotation_x,
                $instance->rotation_y,
                $instance->rotation_z,
                (int) $instance->mirrored,
            ]);

            if (isset($seen[$signature])) {
                $warnings[] = $this->warning('duplicate_instance', 'Two instances of the same part occupy the same transform.', [
                    'instance_id' => $instance->id,
                    'duplicate_of_instance_id' => $seen[$signature],
                    'part_id' => $item['part']->id,
                ]);
            } else {
                $seen[$signature] = $instance->id;
            }
        }
    }

    /**
     * @param  array<int, array{instance: PartInstance, part: PartDefinition, bounds: array<string, mixed>}>  $geometry
     * @param  array<int, array<string, mixed>>  $warnings
     */
    private function appendUnsupportedWarnings(array $geometry, array &$warnings): void
    {
        foreach ($geometry as $item) {
            $instance = $item['instance'];
            $minimumZ = $item['bounds']['min']['z'];

            if ($minimumZ <= 1 || $this->hasRotation($instance)) {
                continue;
            }

            $isSupported = collect($geometry)->contains(function (array $candidate) use ($item, $minimumZ): bool {
                if ($candidate['instance']->id === $item['instance']->id) {
                    return false;
                }

                $candidateBounds = $candidate['bounds'];
                $touchesBottom = abs($candidateBounds['max']['z'] - $minimumZ) <= 5
                    || ($candidateBounds['min']['z'] <= $minimumZ && $candidateBounds['max']['z'] >= $minimumZ);

                return $touchesBottom && $this->overlapLength($item['bounds'], $candidateBounds, 'x') > 2
                    && $this->overlapLength($item['bounds'], $candidateBounds, 'y') > 2;
            });

            if (! $isSupported) {
                $warnings[] = $this->warning('instance_without_support', 'A horizontal instance has no supporting part directly below it.', [
                    'instance_id' => $instance->id,
                    'part_id' => $item['part']->id,
                    'part_name' => $item['part']->name,
                    'bottom_z' => round($minimumZ, 2),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{instance: PartInstance, part: PartDefinition, bounds: array<string, mixed>}>  $geometry
     * @param  array<int, array<string, mixed>>  $reviewItems
     */
    private function appendIntersectionReviewItems(array $geometry, array &$reviewItems): void
    {
        $sorted = collect($geometry)->sortBy(fn (array $item): float => $item['bounds']['min']['x'])->values();

        for ($leftIndex = 0; $leftIndex < $sorted->count(); $leftIndex++) {
            $left = $sorted[$leftIndex];

            for ($rightIndex = $leftIndex + 1; $rightIndex < $sorted->count(); $rightIndex++) {
                $right = $sorted[$rightIndex];

                if ($right['bounds']['min']['x'] >= $left['bounds']['max']['x']) {
                    break;
                }

                $overlap = [
                    'x' => $this->overlapLength($left['bounds'], $right['bounds'], 'x'),
                    'y' => $this->overlapLength($left['bounds'], $right['bounds'], 'y'),
                    'z' => $this->overlapLength($left['bounds'], $right['bounds'], 'z'),
                ];

                if (min($overlap) <= 2) {
                    continue;
                }

                $overlapVolume = $overlap['x'] * $overlap['y'] * $overlap['z'];
                $smallerVolume = min($this->boundsVolume($left['bounds']), $this->boundsVolume($right['bounds']));

                if ($smallerVolume <= 0 || $overlapVolume / $smallerVolume < 0.1) {
                    continue;
                }

                $reviewItems[] = $this->warning('potential_intersection', 'Two rotated bounding volumes intersect significantly and should be reviewed.', [
                    'instance_ids' => [$left['instance']->id, $right['instance']->id],
                    'part_names' => [$left['part']->name, $right['part']->name],
                    'overlap_mm' => array_map(fn (float $value): float => round($value, 2), $overlap),
                ]);

                if (count($reviewItems) >= 100) {
                    return;
                }
            }
        }
    }

    private function hasRotation(PartInstance $instance): bool
    {
        return abs($instance->rotation_x) > 0.001 || abs($instance->rotation_y) > 0.001 || abs($instance->rotation_z) > 0.001;
    }

    /**
     * @return array{min: array{x: float, y: float, z: float}, max: array{x: float, y: float, z: float}}
     */
    private function orientedBounds(PartDefinition $part, PartInstance $instance): array
    {
        $halfSize = [$part->length / 2, $part->width / 2, $part->thickness / 2];
        $rotation = $this->rotationMatrix($instance);
        $extent = [];

        for ($row = 0; $row < 3; $row++) {
            $extent[$row] = abs($rotation[$row][0]) * $halfSize[0]
                + abs($rotation[$row][1]) * $halfSize[1]
                + abs($rotation[$row][2]) * $halfSize[2];
        }

        $center = [$instance->position_x, $instance->position_y, $instance->position_z];

        return [
            'min' => ['x' => $center[0] - $extent[0], 'y' => $center[1] - $extent[1], 'z' => $center[2] - $extent[2]],
            'max' => ['x' => $center[0] + $extent[0], 'y' => $center[1] + $extent[1], 'z' => $center[2] + $extent[2]],
        ];
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function rotationMatrix(PartInstance $instance): array
    {
        $x = deg2rad($instance->rotation_x);
        $y = deg2rad($instance->rotation_y);
        $z = deg2rad($instance->rotation_z);
        $rotationX = [[1.0, 0.0, 0.0], [0.0, cos($x), -sin($x)], [0.0, sin($x), cos($x)]];
        $rotationY = [[cos($y), 0.0, sin($y)], [0.0, 1.0, 0.0], [-sin($y), 0.0, cos($y)]];
        $rotationZ = [[cos($z), -sin($z), 0.0], [sin($z), cos($z), 0.0], [0.0, 0.0, 1.0]];

        return $this->multiplyMatrices($this->multiplyMatrices($rotationZ, $rotationY), $rotationX);
    }

    /**
     * @param  array<int, array<int, float>>  $left
     * @param  array<int, array<int, float>>  $right
     * @return array<int, array<int, float>>
     */
    private function multiplyMatrices(array $left, array $right): array
    {
        $result = array_fill(0, 3, array_fill(0, 3, 0.0));

        for ($row = 0; $row < 3; $row++) {
            for ($column = 0; $column < 3; $column++) {
                for ($index = 0; $index < 3; $index++) {
                    $result[$row][$column] += $left[$row][$index] * $right[$index][$column];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, float>>  $left
     * @param  array<string, array<string, float>>  $right
     */
    private function overlapLength(array $left, array $right, string $axis): float
    {
        return max(0, min($left['max'][$axis], $right['max'][$axis]) - max($left['min'][$axis], $right['min'][$axis]));
    }

    /**
     * @param  array<string, array<string, float>>  $bounds
     */
    private function boundsVolume(array $bounds): float
    {
        return ($bounds['max']['x'] - $bounds['min']['x'])
            * ($bounds['max']['y'] - $bounds['min']['y'])
            * ($bounds['max']['z'] - $bounds['min']['z']);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function warning(string $code, string $message, array $context = []): array
    {
        return compact('code', 'message', 'context');
    }

    /**
     * @param  array<string, array<string, float>>|null  $current
     * @param  array<string, array<string, float>>  $incoming
     * @return array<string, array<string, float>>
     */
    private function mergeBounds(?array $current, array $incoming): array
    {
        if ($current === null) {
            return $incoming;
        }

        foreach (['x', 'y', 'z'] as $axis) {
            $current['min'][$axis] = min($current['min'][$axis], $incoming['min'][$axis]);
            $current['max'][$axis] = max($current['max'][$axis], $incoming['max'][$axis]);
        }

        return $current;
    }
}
