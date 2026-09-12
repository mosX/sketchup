<?php

namespace App\Services;

use App\Models\PartDefinition;
use App\Models\Project;
use App\Models\ProjectConnection;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProjectTemplateManager
{
    public function __construct(public ProjectDiagnostics $diagnostics) {}

    public function capture(User $user, Project $project, string $name, ?string $description): ProjectTemplate
    {
        $project->load('assemblyGroups', 'partDefinitions.instances', 'projectConnections');
        $bounds = $this->diagnostics->analyze($project)['summary']['bounds'];
        $origin = $bounds['min'] ?? ['x' => 0, 'y' => 0, 'z' => 0];
        $maximum = $bounds['max'] ?? ['x' => 1, 'y' => 1, 'z' => 1];

        return $user->projectTemplates()->create([
            'source_project_id' => $project->id,
            'name' => $name,
            'description' => $description,
            'base_dimensions' => [
                'width' => max((float) $maximum['x'] - (float) $origin['x'], 1),
                'depth' => max((float) $maximum['y'] - (float) $origin['y'], 1),
                'height' => max((float) $maximum['z'] - (float) $origin['z'], 1),
            ],
            'snapshot' => [
                'origin' => $origin,
                'groups' => $project->assemblyGroups->map(fn ($group): array => [
                    'source_id' => $group->id,
                    'parent_source_id' => $group->parent_id,
                    'name' => $group->name,
                    'is_visible' => $group->is_visible,
                    'is_locked' => $group->is_locked,
                    'sort_order' => $group->sort_order,
                ])->values()->all(),
                'parts' => $project->partDefinitions->map(fn (PartDefinition $part): array => [
                    'name' => $part->name,
                    'material' => $part->material,
                    'length' => $part->length,
                    'width' => $part->width,
                    'thickness' => $part->thickness,
                    'grain_axis' => $part->grain_axis,
                    'operations' => $part->operations,
                    'instances' => $part->instances->map(fn ($instance): array => [
                        'source_id' => $instance->id,
                        'group_source_id' => $instance->assembly_group_id,
                        'position' => ['x' => $instance->position_x, 'y' => $instance->position_y, 'z' => $instance->position_z],
                        'rotation' => ['x' => $instance->rotation_x, 'y' => $instance->rotation_y, 'z' => $instance->rotation_z],
                        'mirrored' => $instance->mirrored,
                    ])->values()->all(),
                ])->values()->all(),
                'connections' => $project->projectConnections->map(fn (ProjectConnection $connection): array => [
                    'primary_source_instance_id' => $connection->primary_instance_id,
                    'secondary_source_instance_id' => $connection->secondary_instance_id,
                    'type' => $connection->type,
                    'label' => $connection->label,
                    'parameters' => $connection->parameters,
                    'note' => $connection->note,
                    'is_verified' => $connection->is_verified,
                    'machining_status' => $connection->machining_status,
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * @param  array{name: string, description?: string|null, dimensions: array{width: float|int, depth: float|int, height: float|int}}  $input
     */
    public function instantiate(User $user, ProjectTemplate $template, array $input): Project
    {
        return DB::transaction(function () use ($input, $template, $user): Project {
            $base = $template->base_dimensions;
            $scale = [
                'x' => (float) $input['dimensions']['width'] / (float) $base['width'],
                'y' => (float) $input['dimensions']['depth'] / (float) $base['depth'],
                'z' => (float) $input['dimensions']['height'] / (float) $base['height'],
            ];
            $snapshot = $template->snapshot;
            $origin = $snapshot['origin'];
            $project = $user->projects()->create([
                'name' => $input['name'],
                'description' => $input['description'] ?? $template->description,
            ]);
            $groupIds = $this->createGroups($project, $snapshot['groups'] ?? []);
            $instanceIds = [];

            foreach ($snapshot['parts'] ?? [] as $part) {
                $this->createPartVariants($project, $part, $groupIds, $origin, $scale, $instanceIds);
            }

            foreach ($snapshot['connections'] ?? [] as $connection) {
                $project->projectConnections()->create([
                    'primary_instance_id' => $instanceIds[$connection['primary_source_instance_id']],
                    'secondary_instance_id' => $instanceIds[$connection['secondary_source_instance_id']],
                    'type' => $connection['type'],
                    'label' => $connection['label'],
                    'parameters' => $this->scaleConnectionParameters($connection['parameters'], min($scale)),
                    'note' => $connection['note'],
                    'is_verified' => false,
                    'machining_status' => $connection['machining_status'] ?? 'pending',
                    'generated_operation_ids' => [],
                ]);
            }

            return $project->refresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, int>
     */
    private function createGroups(Project $project, array $groups): array
    {
        $createdIds = [];
        $pending = collect($groups)->keyBy('source_id');

        while ($pending->isNotEmpty()) {
            $createdThisPass = false;

            foreach ($pending as $sourceId => $group) {
                $parentSourceId = $group['parent_source_id'];

                if ($parentSourceId !== null && ! isset($createdIds[$parentSourceId])) {
                    continue;
                }

                $created = $project->assemblyGroups()->create([
                    'parent_id' => $parentSourceId === null ? null : $createdIds[$parentSourceId],
                    'name' => $group['name'],
                    'is_visible' => $group['is_visible'],
                    'is_locked' => $group['is_locked'],
                    'sort_order' => $group['sort_order'],
                ]);
                $createdIds[$sourceId] = $created->id;
                $pending->forget($sourceId);
                $createdThisPass = true;
            }

            if (! $createdThisPass) {
                break;
            }
        }

        return $createdIds;
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<int, int>  $groupIds
     * @param  array{x: float|int, y: float|int, z: float|int}  $origin
     * @param  array{x: float, y: float, z: float}  $scale
     */
    private function createPartVariants(Project $project, array $part, array $groupIds, array $origin, array $scale, array &$instanceIds): void
    {
        if ($part['instances'] === []) {
            $factors = ['length' => $scale['x'], 'width' => $scale['y'], 'thickness' => $scale['z']];
            $project->partDefinitions()->create([
                'name' => $part['name'],
                'material' => $part['material'],
                'length' => max((float) $part['length'] * $factors['length'], 0.1),
                'width' => max((float) $part['width'] * $factors['width'], 0.1),
                'thickness' => max((float) $part['thickness'] * $factors['thickness'], 0.1),
                'grain_axis' => $part['grain_axis'],
                'operations' => $this->scaleOperations($part['operations'] ?? [], $factors),
            ]);

            return;
        }

        $variants = collect($part['instances'])->groupBy(function (array $instance) use ($scale): string {
            $factors = $this->localScaleFactors($instance['rotation'], $scale);

            return implode('|', array_map(fn (float $value): string => number_format($value, 6, '.', ''), $factors));
        });

        foreach ($variants->values() as $variantIndex => $instances) {
            $factors = $this->localScaleFactors($instances->first()['rotation'], $scale);
            $definition = $project->partDefinitions()->create([
                'name' => $variants->count() > 1 ? "{$part['name']} · вариант ".($variantIndex + 1) : $part['name'],
                'material' => $part['material'],
                'length' => max((float) $part['length'] * $factors['length'], 0.1),
                'width' => max((float) $part['width'] * $factors['width'], 0.1),
                'thickness' => max((float) $part['thickness'] * $factors['thickness'], 0.1),
                'grain_axis' => $part['grain_axis'],
                'operations' => $this->scaleOperations($part['operations'] ?? [], $factors),
            ]);

            foreach ($instances as $instance) {
                $createdInstance = $definition->instances()->create([
                    'project_id' => $project->id,
                    'assembly_group_id' => isset($instance['group_source_id']) ? ($groupIds[$instance['group_source_id']] ?? null) : null,
                    'position_x' => $this->scaleCoordinate($instance['position']['x'], $origin['x'], $scale['x']),
                    'position_y' => $this->scaleCoordinate($instance['position']['y'], $origin['y'], $scale['y']),
                    'position_z' => $this->scaleCoordinate($instance['position']['z'], $origin['z'], $scale['z']),
                    'rotation_x' => $instance['rotation']['x'],
                    'rotation_y' => $instance['rotation']['y'],
                    'rotation_z' => $instance['rotation']['z'],
                    'mirrored' => $instance['mirrored'],
                ]);
                $instanceIds[$instance['source_id']] = $createdInstance->id;
            }
        }
    }

    private function scaleCoordinate(float|int $value, float|int $origin, float $scale): float
    {
        return round((float) $origin + ((float) $value - (float) $origin) * $scale, 4);
    }

    /**
     * @param  array{x: float|int, y: float|int, z: float|int}  $rotation
     * @param  array{x: float, y: float, z: float}  $scale
     * @return array{length: float, width: float, thickness: float}
     */
    private function localScaleFactors(array $rotation, array $scale): array
    {
        $matrix = $this->rotationMatrix($rotation);
        $factor = fn (int $column): float => sqrt(
            ($matrix[0][$column] * $scale['x']) ** 2
            + ($matrix[1][$column] * $scale['y']) ** 2
            + ($matrix[2][$column] * $scale['z']) ** 2
        );

        return ['length' => $factor(0), 'width' => $factor(1), 'thickness' => $factor(2)];
    }

    /**
     * @param  array{x: float|int, y: float|int, z: float|int}  $rotation
     * @return array<int, array<int, float>>
     */
    private function rotationMatrix(array $rotation): array
    {
        $x = deg2rad((float) $rotation['x']);
        $y = deg2rad((float) $rotation['y']);
        $z = deg2rad((float) $rotation['z']);
        $rotationX = [[1.0, 0.0, 0.0], [0.0, cos($x), -sin($x)], [0.0, sin($x), cos($x)]];
        $rotationY = [[cos($y), 0.0, sin($y)], [0.0, 1.0, 0.0], [-sin($y), 0.0, cos($y)]];
        $rotationZ = [[cos($z), -sin($z), 0.0], [sin($z), cos($z), 0.0], [0.0, 0.0, 1.0]];

        return $this->multiplyMatrices($this->multiplyMatrices($rotationZ, $rotationY), $rotationX);
    }

    /** @param array<int, array<int, float>> $left @param array<int, array<int, float>> $right @return array<int, array<int, float>> */
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
     * @param  array<int, array<string, mixed>>  $operations
     * @param  array{length: float, width: float, thickness: float}  $factors
     * @return array<int, array<string, mixed>>
     */
    private function scaleOperations(array $operations, array $factors): array
    {
        return collect($operations)->map(function (array $operation) use ($factors): array {
            $type = $operation['type'] ?? null;

            if ($type === 'cross_cut') {
                $this->scaleKeys($operation, ['position'], $factors['length']);
                $this->scaleKeys($operation, ['cut_depth'], $factors['thickness']);
            } elseif ($type === 'rip_cut') {
                $this->scaleKeys($operation, ['start_offset', 'end_offset'], $factors['width']);
                $this->scaleKeys($operation, ['cut_depth'], $factors['thickness']);
            } elseif (in_array($type, ['groove', 'plunge_route', 'drill'], true)) {
                [$uFactor, $vFactor, $depthFactor] = $this->faceFactors($operation['face'] ?? 'top', $factors);
                $this->scaleKeys($operation, ['center_u', 'start_u'], $uFactor);
                $this->scaleKeys($operation, ['center_v', 'start_v'], $vFactor);
                $this->scaleKeys($operation, ['depth'], $depthFactor);
                $this->scalePath($operation, $uFactor, $vFactor);
            } elseif ($type === 'edge_roundover') {
                $this->scaleKeys($operation, ['radius'], min($factors));
            }

            return $operation;
        })->all();
    }

    /** @param array<string, mixed> $operation @param array<int, string> $keys */
    private function scaleKeys(array &$operation, array $keys, float $factor): void
    {
        foreach ($keys as $key) {
            if (isset($operation[$key])) {
                $operation[$key] = round((float) $operation[$key] * $factor, 4);
            }
        }
    }

    /** @param array<string, mixed> $operation */
    private function scalePath(array &$operation, float $uFactor, float $vFactor): void
    {
        $lengthKey = isset($operation['groove_length']) ? 'groove_length' : (isset($operation['travel_length']) ? 'travel_length' : null);

        if ($lengthKey === null) {
            return;
        }

        $angle = deg2rad((float) ($operation['path_angle'] ?? 0));
        $operation[$lengthKey] = round((float) $operation[$lengthKey] * hypot(cos($angle) * $uFactor, sin($angle) * $vFactor), 4);
        $operation['path_angle'] = round(rad2deg(atan2(sin($angle) * $vFactor, cos($angle) * $uFactor)), 4);
    }

    /**
     * @param  array{length: float, width: float, thickness: float}  $factors
     * @return array{float, float, float}
     */
    private function faceFactors(string $face, array $factors): array
    {
        return match ($face) {
            'left', 'right' => [$factors['length'], $factors['thickness'], $factors['width']],
            'start', 'end' => [$factors['width'], $factors['thickness'], $factors['length']],
            default => [$factors['length'], $factors['width'], $factors['thickness']],
        };
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function scaleConnectionParameters(array $parameters, float $factor): array
    {
        foreach (['primary_center_u', 'primary_center_v', 'secondary_center_u', 'secondary_center_v', 'joint_length', 'joint_width', 'tenon_width', 'tenon_thickness', 'tenon_length', 'dowel_diameter', 'dowel_depth', 'dowel_spacing'] as $key) {
            if (isset($parameters[$key])) {
                $parameters[$key] = round((float) $parameters[$key] * $factor, 4);
            }
        }

        return $parameters;
    }
}
