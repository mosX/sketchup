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
        $project->loadMissing('partDefinitions.instances');
        $warnings = [];
        $instanceCount = 0;
        $materialVolume = 0.0;
        $bounds = null;

        if ($project->partDefinitions->isEmpty()) {
            $warnings[] = $this->warning('project_empty', 'The project does not contain any part definitions.');
        }

        $project->partDefinitions->each(function (PartDefinition $part) use (&$bounds, &$instanceCount, &$materialVolume, &$warnings): void {
            if ($part->instances->isEmpty()) {
                $warnings[] = $this->warning('part_unused', 'The part has no instances in the assembly.', [
                    'part_id' => $part->id,
                    'part_name' => $part->name,
                ]);
            }

            $materialVolume += $part->length * $part->width * $part->thickness * $part->instances->count();

            $part->instances->each(function (PartInstance $instance) use ($part, &$bounds, &$instanceCount, &$warnings): void {
                $instanceCount++;

                if ($instance->position_z < 0) {
                    $warnings[] = $this->warning('instance_below_floor', 'The instance starts below the Z=0 floor plane.', [
                        'instance_id' => $instance->id,
                        'part_id' => $part->id,
                        'position_z' => $instance->position_z,
                    ]);
                }

                $instanceBounds = [
                    'min' => [
                        'x' => $instance->position_x - $part->length / 2,
                        'y' => $instance->position_y - $part->width / 2,
                        'z' => $instance->position_z,
                    ],
                    'max' => [
                        'x' => $instance->position_x + $part->length / 2,
                        'y' => $instance->position_y + $part->width / 2,
                        'z' => $instance->position_z + $part->thickness,
                    ],
                ];
                $bounds = $this->mergeBounds($bounds, $instanceBounds);
            });
        });

        return [
            'valid' => true,
            'errors' => [],
            'warnings' => $warnings,
            'summary' => [
                'part_count' => $project->partDefinitions->count(),
                'instance_count' => $instanceCount,
                'material_volume_mm3' => round($materialVolume, 2),
                'bounds' => $bounds,
                'bounds_note' => 'Bounds ignore instance rotation and are intended as an early diagnostic.',
            ],
        ];
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
