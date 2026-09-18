<?php

namespace App\Actions\Projects;

use App\Actions\Connections\GenerateConnectionMachining;
use App\Models\PartInstance;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

class CreateScriptConnections
{
    public function __construct(private GenerateConnectionMachining $machining) {}

    /**
     * @param  array<int, array<string, mixed>>  $connections
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, int>
     */
    public function handle(Project $project, array $connections, array $results): array
    {
        $references = collect($results)->where('type', 'create_instance')->pluck('instance_id', 'temporary_id');
        $ids = [];
        $pairs = [];
        foreach ($connections as $index => $data) {
            $primaryId = $references[$data['primary_ref']] ?? null;
            $secondaryId = $references[$data['secondary_ref']] ?? null;
            $pair = min($primaryId ?? 0, $secondaryId ?? 0).':'.max($primaryId ?? 0, $secondaryId ?? 0);
            if (! $primaryId || ! $secondaryId || $primaryId === $secondaryId || isset($pairs[$pair])) {
                throw ValidationException::withMessages(["connections.{$index}" => 'Нужны два разных экземпляра этого сценария без повторного соединения между ними.']);
            }
            $pairs[$pair] = true;
            if ($data['generate_machining'] ?? false) {
                $this->validatePlacement($project->partInstances()->with('partDefinition')->findOrFail($primaryId), $project->partInstances()->with('partDefinition')->findOrFail($secondaryId), $data, $index);
            }
            $connection = $project->projectConnections()->create([
                'primary_instance_id' => $primaryId, 'secondary_instance_id' => $secondaryId,
                'type' => $data['type'], 'label' => $data['label'] ?? '', 'parameters' => $data['parameters'] ?? [],
            ]);
            if ($data['generate_machining'] ?? false) {
                $this->machining->handle($project, $connection);
            }
            $ids[] = $connection->id;
        }

        return $ids;
    }

    /** @param array<string, mixed> $data */
    private function validatePlacement(PartInstance $primary, PartInstance $secondary, array $data, int $index): void
    {
        $parameters = $data['parameters'] ?? [];
        $a = $this->frame($primary, $parameters, 'primary', 'end');
        $b = $this->frame($secondary, $parameters, 'secondary', 'start');
        $penetration = match ($data['type']) {
            'mortise_tenon' => (float) ($parameters['tenon_length'] ?? 20),
            'half_lap' => $a['depth'] * (float) ($parameters['depth_ratio'] ?? 0.5) + $b['depth'] * (1 - (float) ($parameters['depth_ratio'] ?? 0.5)),
            default => 0,
        };
        $error = 0;
        for ($axis = 0; $axis < 3; $axis++) {
            $error += ($b['point'][$axis] - $a['point'][$axis] + $a['normal'][$axis] * $penetration) ** 2;
        }
        if ($this->dot($a['normal'], $b['normal']) > -0.9999 || sqrt($error) > 0.1) {
            throw ValidationException::withMessages(["connections.{$index}" => 'Грани или центры обработки не совмещены. Совместите грани через alignTo; для шипа задайте gap = -tenon_length, для вполдерева — отрицательную суммарную глубину выборок. Допуск положения: 0.1 мм.']);
        }
        $angle = deg2rad((float) ($parameters['joint_angle'] ?? 0));
        $directionA = $directionB = [];
        for ($axis = 0; $axis < 3; $axis++) {
            $directionA[] = $a['u'][$axis] * cos($angle) + $a['v'][$axis] * sin($angle);
            $directionB[] = $b['u'][$axis] * cos($angle) + $b['v'][$axis] * sin($angle);
        }
        $needsOrientation = in_array($data['type'], ['mortise_tenon', 'half_lap'], true)
            || ($data['type'] === 'dowel' && (int) ($parameters['dowel_count'] ?? 2) > 1);
        if ($needsOrientation && abs($this->dot($directionA, $directionB)) < 0.9999) {
            throw ValidationException::withMessages(["connections.{$index}" => 'Направления шипа, паза или ряда шкантов не совпадают. Поверните деталь вокруг нормали грани.']);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function frame(PartInstance $instance, array $parameters, string $role, string $defaultFace): array
    {
        $part = $instance->partDefinition;
        $length = $part->length;
        $width = $part->width;
        $thickness = $part->thickness;
        [$point, $normal, $u, $v, $uSize, $vSize, $depth] = match ($parameters[$role.'_face'] ?? $defaultFace) {
            'top' => [[0, 0, $thickness / 2], [0, 0, 1], [1, 0, 0], [0, 1, 0], $length, $width, $thickness],
            'bottom' => [[0, 0, -$thickness / 2], [0, 0, -1], [1, 0, 0], [0, -1, 0], $length, $width, $thickness],
            'left' => [[0, -$width / 2, 0], [0, -1, 0], [1, 0, 0], [0, 0, 1], $length, $thickness, $width],
            'right' => [[0, $width / 2, 0], [0, 1, 0], [-1, 0, 0], [0, 0, 1], $length, $thickness, $width],
            'start' => [[-$length / 2, 0, 0], [-1, 0, 0], [0, -1, 0], [0, 0, 1], $width, $thickness, $length],
            'end' => [[$length / 2, 0, 0], [1, 0, 0], [0, 1, 0], [0, 0, 1], $width, $thickness, $length],
        };
        for ($axis = 0; $axis < 3; $axis++) {
            $point[$axis] += $u[$axis] * ((float) ($parameters[$role.'_center_u'] ?? $uSize / 2) - $uSize / 2)
                + $v[$axis] * ((float) ($parameters[$role.'_center_v'] ?? $vSize / 2) - $vSize / 2);
        }
        $point = $this->rotate($point, $instance);
        foreach (['x', 'y', 'z'] as $axis => $name) {
            $point[$axis] += $instance->{'position_'.$name};
        }

        return ['point' => $point, 'normal' => $this->rotate($normal, $instance), 'u' => $this->rotate($u, $instance), 'v' => $this->rotate($v, $instance), 'depth' => $depth];
    }

    /** @param array<int, float|int> $point
     * @return array<int, float>
     */
    private function rotate(array $point, PartInstance $instance): array
    {
        [$x, $y, $z] = $point;
        $x *= $instance->mirrored ? -1 : 1;
        $angle = deg2rad($instance->rotation_x);
        [$y, $z] = [$y * cos($angle) - $z * sin($angle), $y * sin($angle) + $z * cos($angle)];
        $angle = deg2rad($instance->rotation_y);
        [$x, $z] = [$x * cos($angle) + $z * sin($angle), -$x * sin($angle) + $z * cos($angle)];
        $angle = deg2rad($instance->rotation_z);

        return [$x * cos($angle) - $y * sin($angle), $x * sin($angle) + $y * cos($angle), $z];
    }

    /** @param array<int, float> $a
     * @param  array<int, float>  $b
     */
    private function dot(array $a, array $b): float
    {
        return $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
    }
}
