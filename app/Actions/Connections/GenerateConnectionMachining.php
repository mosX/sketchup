<?php

namespace App\Actions\Connections;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GenerateConnectionMachining
{
    public function handle(Project $project, ProjectConnection $connection): ProjectConnection
    {
        return DB::transaction(function () use ($connection, $project): ProjectConnection {
            $connection = ProjectConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if (in_array($connection->machining_status, ['generated', 'not_required'], true)) {
                return $connection;
            }

            if ($connection->machining_status === 'outdated') {
                $this->removeTrackedOperations($connection);
            }

            if ($connection->type === 'butt') {
                $connection->update([
                    'machining_status' => 'not_required',
                    'generated_operation_ids' => [],
                ]);
                $project->increment('revision');

                return $connection->refresh();
            }

            $primaryInstance = $this->lockInstance($connection->primary_instance_id);
            $secondaryInstance = $this->lockInstance($connection->secondary_instance_id);
            $primaryPart = $this->makePartUniqueForInstance($primaryInstance, $connection);
            $secondaryPart = $this->makePartUniqueForInstance($secondaryInstance, $connection);
            $operationsByRole = $this->operationsForConnection($connection, $primaryPart, $secondaryPart);
            $generatedOperations = [];

            foreach ([
                'primary' => $primaryPart,
                'secondary' => $secondaryPart,
            ] as $role => $part) {
                $operations = $operationsByRole[$role];
                $this->ensureOperationLimit($part, count($operations));
                $part->operations = [...($part->operations ?? []), ...$operations];
                $part->save();

                foreach ($operations as $operation) {
                    $generatedOperations[] = [
                        'role' => $role,
                        'part_definition_id' => $part->id,
                        'operation_id' => $operation['id'],
                        'type' => $operation['type'],
                    ];
                }
            }

            $connection->update([
                'machining_status' => 'generated',
                'generated_operation_ids' => $generatedOperations,
                'is_verified' => false,
            ]);
            $project->increment('revision');

            return $connection->refresh();
        }, attempts: 3);
    }

    public function reset(Project $project, ProjectConnection $connection): ProjectConnection
    {
        return DB::transaction(function () use ($connection, $project): ProjectConnection {
            $connection = ProjectConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($connection->machining_status === 'pending' && empty($connection->generated_operation_ids)) {
                return $connection;
            }

            $this->removeTrackedOperations($connection);
            $connection->update([
                'machining_status' => 'pending',
                'generated_operation_ids' => [],
                'is_verified' => false,
            ]);
            $project->increment('revision');

            return $connection->refresh();
        }, attempts: 3);
    }

    private function removeTrackedOperations(ProjectConnection $connection): void
    {
        collect($connection->generated_operation_ids ?? [])
            ->groupBy('part_definition_id')
            ->each(function ($references, int|string $partId): void {
                $part = PartDefinition::query()->lockForUpdate()->find($partId);

                if (! $part) {
                    return;
                }

                $operationIds = $references->pluck('operation_id')->all();
                $part->operations = collect($part->operations ?? [])
                    ->reject(fn (array $operation): bool => in_array($operation['id'] ?? null, $operationIds, true))
                    ->values()
                    ->all();
                $part->save();
            });
    }

    private function lockInstance(int $instanceId): PartInstance
    {
        return PartInstance::query()->lockForUpdate()->findOrFail($instanceId);
    }

    private function makePartUniqueForInstance(PartInstance $instance, ProjectConnection $connection): PartDefinition
    {
        $part = PartDefinition::query()->lockForUpdate()->findOrFail($instance->part_definition_id);

        if ($part->instances()->count() === 1) {
            return $part;
        }

        $variant = $part->replicate();
        $variant->name = $part->name.' — узел #'.$connection->id;
        $variant->save();
        $instance->part_definition_id = $variant->id;
        $instance->save();

        return $variant;
    }

    /**
     * @return array{primary: array<int, array<string, mixed>>, secondary: array<int, array<string, mixed>>}
     */
    private function operationsForConnection(
        ProjectConnection $connection,
        PartDefinition $primaryPart,
        PartDefinition $secondaryPart,
    ): array {
        $parameters = $connection->parameters ?? [];
        $primaryFace = (string) ($parameters['primary_face'] ?? 'end');
        $secondaryFace = (string) ($parameters['secondary_face'] ?? 'start');

        return match ($connection->type) {
            'dowel' => [
                'primary' => $this->dowelOperationsForRole($primaryPart, $primaryFace, $parameters, 'primary'),
                'secondary' => $this->dowelOperationsForRole($secondaryPart, $secondaryFace, $parameters, 'secondary'),
            ],
            'half_lap' => $this->halfLapOperations($primaryPart, $primaryFace, $secondaryPart, $secondaryFace, $parameters),
            'mortise_tenon' => $this->mortiseTenonOperations($primaryPart, $primaryFace, $secondaryPart, $secondaryFace, $parameters),
            default => throw ValidationException::withMessages(['type' => 'Для этого типа соединения обработка пока не поддерживается.']),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function dowelOperationsForRole(PartDefinition $part, string $face, array $parameters, string $role): array
    {
        $surface = $this->surfaceDimensions($part, $face);
        $diameter = (float) ($parameters['dowel_diameter'] ?? 8);
        $count = (int) ($parameters['dowel_count'] ?? 2);
        $depth = (float) ($parameters['dowel_depth'] ?? 25);
        $spacing = (float) ($parameters['dowel_spacing'] ?? $diameter * 2);
        $center = $this->connectionCenter($parameters, $role, $surface);

        return $this->dowelOperationsAtCenter($face, $surface, $parameters, $diameter, $count, $depth, $spacing, $center);
    }

    /**
     * @param  array{u: float, v: float, depth: float}  $surface
     * @param  array{u: float, v: float}  $center
     * @return array<int, array<string, mixed>>
     */
    private function dowelOperationsAtCenter(
        string $face,
        array $surface,
        array $parameters,
        float $diameter,
        int $count,
        float $depth,
        float $spacing,
        array $center,
    ): array {
        $angle = deg2rad((float) ($parameters['joint_angle'] ?? 0));
        $halfSpan = $spacing * ($count - 1) / 2;
        $radius = $diameter / 2;

        if ($depth > $surface['depth']) {
            throw ValidationException::withMessages([
                'parameters' => 'Шканты не помещаются на выбранной грани. Уменьшите диаметр, количество или глубину.',
            ]);
        }

        return collect(range(0, $count - 1))->map(function (int $index) use ($angle, $center, $count, $depth, $diameter, $face, $halfSpan, $radius, $spacing, $surface): array {
            $offset = $count === 1 ? 0 : -$halfSpan + $spacing * $index;
            $centerU = $center['u'] + cos($angle) * $offset;
            $centerV = $center['v'] + sin($angle) * $offset;

            if ($centerU - $radius < 0 || $centerU + $radius > $surface['u'] || $centerV - $radius < 0 || $centerV + $radius > $surface['v']) {
                throw ValidationException::withMessages([
                    'parameters' => 'Шканты выходят за выбранную грань. Переместите соединение или уменьшите шаг и количество.',
                ]);
            }

            return [
                ...$this->operation('drill'),
                'face' => $face,
                'center_u' => $centerU,
                'center_v' => $centerV,
                'diameter' => $diameter,
                'depth' => $depth,
                'through' => false,
            ];
        })->all();
    }

    /**
     * @return array{primary: array<int, array<string, mixed>>, secondary: array<int, array<string, mixed>>}
     */
    private function halfLapOperations(
        PartDefinition $primaryPart,
        string $primaryFace,
        PartDefinition $secondaryPart,
        string $secondaryFace,
        array $parameters,
    ): array {
        $primarySurface = $this->surfaceDimensions($primaryPart, $primaryFace);
        $secondarySurface = $this->surfaceDimensions($secondaryPart, $secondaryFace);
        $depthRatio = (float) ($parameters['depth_ratio'] ?? 0.5);
        $length = (float) ($parameters['joint_length'] ?? min($primarySurface['u'], $secondarySurface['u']));
        $width = (float) ($parameters['joint_width'] ?? min($primarySurface['v'], $secondarySurface['v']));
        $angle = (float) ($parameters['joint_angle'] ?? 0);
        $primaryCenter = $this->connectionCenter($parameters, 'primary', $primarySurface);
        $secondaryCenter = $this->connectionCenter($parameters, 'secondary', $secondarySurface);
        $this->assertRectangleFits($primarySurface, $primaryCenter, $length, $width, $angle);
        $this->assertRectangleFits($secondarySurface, $secondaryCenter, $length, $width, $angle);

        return [
            'primary' => [$this->grooveOperation(
                $primaryFace,
                $primaryCenter['u'],
                $primaryCenter['v'],
                $length,
                $width,
                $primarySurface['depth'] * $depthRatio,
                $angle,
            )],
            'secondary' => [$this->grooveOperation(
                $secondaryFace,
                $secondaryCenter['u'],
                $secondaryCenter['v'],
                $length,
                $width,
                $secondarySurface['depth'] * (1 - $depthRatio),
                $angle,
            )],
        ];
    }

    /**
     * @return array{primary: array<int, array<string, mixed>>, secondary: array<int, array<string, mixed>>}
     */
    private function mortiseTenonOperations(
        PartDefinition $primaryPart,
        string $primaryFace,
        PartDefinition $secondaryPart,
        string $secondaryFace,
        array $parameters,
    ): array {
        $primarySurface = $this->surfaceDimensions($primaryPart, $primaryFace);
        $secondarySurface = $this->surfaceDimensions($secondaryPart, $secondaryFace);
        $tenonWidth = (float) ($parameters['tenon_width'] ?? 30);
        $tenonThickness = (float) ($parameters['tenon_thickness'] ?? 10);
        $tenonLength = (float) ($parameters['tenon_length'] ?? 20);
        $angle = (float) ($parameters['joint_angle'] ?? 0);
        $primaryCenter = $this->connectionCenter($parameters, 'primary', $primarySurface);
        $secondaryCenter = $this->connectionCenter($parameters, 'secondary', $secondarySurface);

        if (
            $tenonWidth > min($primarySurface['u'], $secondarySurface['u'])
            || $tenonThickness > min($primarySurface['v'], $secondarySurface['v'])
            || $tenonLength > min($primarySurface['depth'], $secondarySurface['depth'])
        ) {
            throw ValidationException::withMessages([
                'parameters' => 'Размеры шипа не помещаются на одной из выбранных граней.',
            ]);
        }

        if (abs($angle) > 0.001) {
            throw ValidationException::withMessages([
                'parameters.joint_angle' => 'Поворот шипа пока не поддерживается. Измените его положение U/V или задайте угол 0°.',
            ]);
        }

        $this->assertRectangleFits($primarySurface, $primaryCenter, $tenonWidth, $tenonThickness, 0);
        $this->assertRectangleFits($secondarySurface, $secondaryCenter, $tenonWidth, $tenonThickness, 0);

        return [
            'primary' => $this->tenonWasteOperations($primarySurface, $primaryFace, $primaryCenter, $tenonWidth, $tenonThickness, $tenonLength),
            'secondary' => [$this->grooveOperation(
                $secondaryFace,
                $secondaryCenter['u'],
                $secondaryCenter['v'],
                $tenonWidth,
                $tenonThickness,
                $tenonLength,
            )],
        ];
    }

    /**
     * @param  array{u: float, v: float, depth: float}  $surface
     * @param  array{u: float, v: float}  $center
     * @return array<int, array<string, mixed>>
     */
    private function tenonWasteOperations(
        array $surface,
        string $face,
        array $center,
        float $tenonWidth,
        float $tenonThickness,
        float $depth,
    ): array {
        $leftWidth = $center['u'] - $tenonWidth / 2;
        $rightWidth = $surface['u'] - ($center['u'] + $tenonWidth / 2);
        $bottomHeight = $center['v'] - $tenonThickness / 2;
        $topHeight = $surface['v'] - ($center['v'] + $tenonThickness / 2);
        $operations = [];

        if ($leftWidth >= 0.1) {
            $operations[] = $this->grooveOperation($face, $leftWidth / 2, $surface['v'] / 2, $leftWidth, $surface['v'], $depth);
        }

        if ($rightWidth >= 0.1) {
            $operations[] = $this->grooveOperation($face, $surface['u'] - $rightWidth / 2, $surface['v'] / 2, $rightWidth, $surface['v'], $depth);
        }

        if ($bottomHeight >= 0.1) {
            $operations[] = $this->grooveOperation($face, $center['u'], $bottomHeight / 2, $tenonWidth, $bottomHeight, $depth);
        }

        if ($topHeight >= 0.1) {
            $operations[] = $this->grooveOperation($face, $center['u'], $surface['v'] - $topHeight / 2, $tenonWidth, $topHeight, $depth);
        }

        return $operations;
    }

    /** @return array<string, mixed> */
    private function grooveOperation(
        string $face,
        float $centerU,
        float $centerV,
        float $length,
        float $width,
        float $depth,
        float $angle = 0,
    ): array {
        return [
            ...$this->operation('groove'),
            'face' => $face,
            'center_u' => $centerU,
            'center_v' => $centerV,
            'path_angle' => $angle,
            'groove_length' => $length,
            'width' => $width,
            'depth' => $depth,
            'blade_diameter' => 190,
        ];
    }

    /** @return array{id: string, type: string, status: string, enabled: bool} */
    private function operation(string $type): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'type' => $type,
            'status' => 'applied',
            'enabled' => true,
        ];
    }

    /** @return array{u: float, v: float, depth: float} */
    private function surfaceDimensions(PartDefinition $part, string $face): array
    {
        return match ($face) {
            'top', 'bottom' => ['u' => $part->length, 'v' => $part->width, 'depth' => $part->thickness],
            'left', 'right' => ['u' => $part->length, 'v' => $part->thickness, 'depth' => $part->width],
            'start', 'end' => ['u' => $part->width, 'v' => $part->thickness, 'depth' => $part->length],
            default => throw ValidationException::withMessages(['parameters' => 'Выбрана неизвестная грань заготовки.']),
        };
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array{u: float, v: float, depth: float}  $surface
     * @return array{u: float, v: float}
     */
    private function connectionCenter(array $parameters, string $role, array $surface): array
    {
        return [
            'u' => (float) ($parameters["{$role}_center_u"] ?? $surface['u'] / 2),
            'v' => (float) ($parameters["{$role}_center_v"] ?? $surface['v'] / 2),
        ];
    }

    /**
     * @param  array{u: float, v: float, depth: float}  $surface
     * @param  array{u: float, v: float}  $center
     */
    private function assertRectangleFits(array $surface, array $center, float $length, float $width, float $angle): void
    {
        $radians = deg2rad($angle);
        $extentU = abs(cos($radians)) * $length / 2 + abs(sin($radians)) * $width / 2;
        $extentV = abs(sin($radians)) * $length / 2 + abs(cos($radians)) * $width / 2;

        if (
            $center['u'] - $extentU < 0
            || $surface['u'] < $center['u'] + $extentU
            || $center['v'] - $extentV < 0
            || $surface['v'] < $center['v'] + $extentV
        ) {
            throw ValidationException::withMessages([
                'parameters' => 'Область соединения выходит за выбранную грань. Измените положение, угол или размеры.',
            ]);
        }
    }

    private function ensureOperationLimit(PartDefinition $part, int $addedCount): void
    {
        if (count($part->operations ?? []) + $addedCount > 50) {
            throw ValidationException::withMessages([
                'operations' => "Для заготовки «{$part->name}» будет превышен предел в 50 операций.",
            ]);
        }
    }
}
