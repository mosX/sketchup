<?php

namespace App\Actions\Projects;

use App\Exceptions\ProjectRevisionConflictException;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectFastener;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepareFastenerDrilling
{
    private const float Tolerance = 0.0001;

    /** @var array<string, array{int, int, int, int, int, int}> */
    private const array Faces = [
        'top' => [0, 1, 1, 1, 2, -1],
        'bottom' => [0, 1, 1, -1, 2, 1],
        'left' => [0, 1, 2, 1, 1, 1],
        'right' => [0, -1, 2, 1, 1, -1],
        'start' => [1, -1, 2, 1, 0, 1],
        'end' => [1, 1, 2, 1, 0, -1],
    ];

    /**
     * @param  Collection<int, ProjectFastener>  $fasteners
     * @param  Collection<int, PartInstance>  $instances
     * @return array<string, bool|float>
     */
    public function savedSettings(Collection $fasteners, Collection $instances): array
    {
        $rows = $fasteners->map(function (ProjectFastener $fastener) use ($instances): array {
            $source = $instances->get($fastener->source_instance_id);
            $target = $instances->get($fastener->target_instance_id);
            $clearance = $this->savedHole($source, $fastener->face, $fastener->center_u, $fastener->center_v, 'clearance');
            $countersink = $this->savedHole($source, $fastener->face, $fastener->center_u, $fastener->center_v, 'countersink');
            $pilot = null;
            $point = $target === null ? null : $this->pilotPoint($fastener, $source, $target);
            if ($point !== null && $point['entry'] >= -self::Tolerance && $point['entry'] < $fastener->length - self::Tolerance) {
                $pilot = $this->savedHole($target, $point['face'], $point['u'], $point['v'], 'pilot');
            }

            return [
                'clearance' => $clearance === null ? [] : ['clearance_diameter' => (float) $clearance['diameter']],
                'countersink' => $countersink === null ? [] : [
                    'countersink' => true, 'countersink_diameter' => (float) $countersink['diameter'], 'countersink_depth' => (float) $countersink['depth'],
                ],
                'pilot' => $pilot === null ? [] : ['pilot' => true, 'pilot_diameter' => (float) $pilot['diameter'], 'pilot_depth' => (float) $pilot['depth']],
            ];
        });
        $settings = [];
        foreach (['clearance', 'countersink', 'pilot'] as $role) {
            $variants = $rows->pluck($role)->uniqueStrict();
            if ($variants->count() === 1) {
                $settings += $variants->first();
            }
        }

        return $settings;
    }

    private function savedHole(PartInstance $instance, string $face, float $u, float $v, string $role): ?array
    {
        $id = $this->operationId($instance->id, $face, $u, $v, $role);
        $operation = collect($instance->partDefinition->operations)->firstWhere('id', $id);

        return ($operation['type'] ?? null) === 'drill' && ($operation['enabled'] ?? true) && ($operation['status'] ?? 'applied') === 'applied'
            && $operation['face'] === $face && abs($operation['center_u'] - $u) <= self::Tolerance && abs($operation['center_v'] - $v) <= self::Tolerance
            ? $operation : null;
    }

    /** @param array<string, mixed> $settings */
    public function handle(Project $project, array $settings): array
    {
        return DB::transaction(function () use ($project, $settings): array {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $expected = $settings['expected_revision'] ?? null;
            if ($expected !== null && (int) $expected !== $project->revision) {
                throw new ProjectRevisionConflictException((int) $expected, $project->revision);
            }
            $fasteners = $project->projectFasteners()->whereKey($settings['fastener_ids'])->orderBy('id')->get();
            abort_unless($fasteners->count() === count($settings['fastener_ids']), 404);
            if ($fasteners->contains('fastener_type', 'nail')) {
                $this->invalid('Автоматическая подготовка отверстий доступна для шурупов.');
            }
            $instanceIds = $fasteners->pluck('source_instance_id')->merge($fasteners->pluck('target_instance_id'))->filter()->unique();
            $instances = $project->partInstances()->with('partDefinition')->whereKey($instanceIds->all())->get()->keyBy('id');
            $byInstance = [];

            foreach ($fasteners as $fastener) {
                $source = $instances->get($fastener->source_instance_id);
                $part = $source->partDefinition;
                $dimensions = $this->dimensions($part);
                $normal = self::Faces[$fastener->face][4];
                $byInstance[$source->id][] = $this->operation(
                    $fastener, 'clearance', $part, $fastener->face, $fastener->center_u, $fastener->center_v,
                    (float) $settings['clearance_diameter'], $dimensions[$normal], true,
                );

                if ($settings['countersink'] ?? false) {
                    $depth = (float) $settings['countersink_depth'];
                    if ($depth >= $dimensions[$normal]) {
                        $this->invalid("Глубина зенковки под шуруп #{$fastener->id} должна быть меньше размера заготовки «{$part->name}» по направлению сверления.");
                    }
                    $byInstance[$source->id][] = $this->operation(
                        $fastener, 'countersink', $part, $fastener->face, $fastener->center_u, $fastener->center_v,
                        (float) $settings['countersink_diameter'], $depth, false, (float) $settings['clearance_diameter'],
                    );
                }

                if ($settings['pilot'] && $fastener->target_instance_id !== null) {
                    $target = $instances->get($fastener->target_instance_id);
                    $byInstance[$target->id][] = $this->pilot($fastener, $source, $target, $settings);
                }
            }

            $plan = [];
            $newIds = [];
            $removedIds = [];
            $remove = $settings['remove_operation_ids'] ?? [];
            foreach ($byInstance as $id => $operations) {
                $instance = $instances->get($id);
                $part = $instance->partDefinition;
                $saved = collect($part->operations)->keyBy('id');
                $changes = [];
                foreach (collect($operations)->unique('id') as $operation) {
                    $operationId = $operation['id'];
                    $existing = $saved->get($operationId);

                    if ($remove !== [] && ! in_array($operationId, $remove, true)) {
                        continue;
                    }
                    if ($existing !== null && $operation != $existing) {
                        $this->invalid("В заготовке «{$part->name}» в этой точке уже есть отверстие с другими параметрами. Проверьте операции заготовки.");
                    }
                    if ($remove !== []) {
                        if ($existing !== null) {
                            $removedIds[] = $operationId;
                            $changes[] = $operationId;
                        }
                    } elseif ($existing === null) {
                        $newIds[] = $operationId;
                        $changes[] = $operation;
                    }
                }

                if ($remove === [] && count($part->operations ?? []) + count($changes) > 50) {
                    $this->invalid("Для заготовки «{$part->name}» будет превышен предел в 50 операций.");
                }
                $plan[$id] = ['instance_id' => $id, 'part_id' => $part->id, 'operations' => $remove === [] ? $changes : [], 'replaces' => $remove === [] ? [] : $changes];
            }

            if ($remove !== [] && count($removedIds) !== count($remove)) {
                $this->invalid('Часть отверстий изменилась или уже удалена.');
            }
            if (! $settings['dry_run'] && ($newIds !== [] || $removedIds !== [])) {
                foreach ($plan as $id => $entry) {
                    if ($entry['operations'] === [] && $entry['replaces'] === []) {
                        continue;
                    }
                    $instance = $instances->get($id);
                    $part = $this->editablePart($project, $instance);
                    $part->operations = [
                        ...collect($part->operations)->whereNotInStrict('id', $entry['replaces'])->values()->all(),
                        ...$entry['operations'],
                    ];
                    $part->save();
                }
                $project->revision++;
                $project->save();
            }

            return ['revision' => $project->revision, 'parts' => array_values($plan), 'created_operation_ids' => $newIds];
        }, attempts: 3);
    }

    private function editablePart(Project $project, PartInstance $instance): PartDefinition
    {
        $part = $project->partDefinitions()->findOrFail($instance->part_definition_id);
        if ($part->instances()->count() === 1) {
            return $part;
        }

        $variant = $part->replicate();
        $variant->name = $part->name.' — #'.$instance->id;
        $variant->save();
        $instance->part_definition_id = $variant->id;
        $instance->save();

        $plan = $project->material_plan;
        $materialId = collect($plan['assignments'] ?? [])->firstWhere('part_id', $part->id)['material_id'] ?? null;
        if ($materialId !== null) {
            $plan['assignments'][] = ['part_id' => $variant->id, 'material_id' => $materialId];
            $project->material_plan = $plan;
        }

        return $variant;
    }

    /** @param array<string, mixed> $settings */
    private function pilot(ProjectFastener $fastener, PartInstance $source, PartInstance $target, array $settings): array
    {
        $point = $this->pilotPoint($fastener, $source, $target);
        if ($point === null) {
            $this->invalid("Шуруп #{$fastener->id} подходит ко второй детали под углом. Направляющее отверстие доступно перпендикулярно грани.");
        }
        if ($point['entry'] < -self::Tolerance || $point['entry'] >= $fastener->length - self::Tolerance) {
            $this->invalid("Шуруп #{$fastener->id} не входит во вторую деталь по этой грани.");
        }
        $depth = (float) $settings['pilot_depth'];
        if ($depth > $point['depth'] + self::Tolerance) {
            $this->invalid("Направляющее отверстие шурупа #{$fastener->id} глубже второй детали.");
        }

        return $this->operation($fastener, 'pilot', $target->partDefinition, $point['face'], $point['u'], $point['v'], (float) $settings['pilot_diameter'], $depth, false);
    }

    /** @return array{face: string, u: float, v: float, entry: float, depth: float}|null */
    private function pilotPoint(ProjectFastener $fastener, PartInstance $source, PartInstance $target): ?array
    {
        [$u, $uSign, $v, $vSign, $axis, $sign] = self::Faces[$fastener->face];
        $size = $this->dimensions($source->partDefinition);
        $origin = [0.0, 0.0, 0.0];
        $origin[$u] = ($fastener->center_u - $size[$u] / 2) * $uSign;
        $origin[$v] = ($fastener->center_v - $size[$v] / 2) * $vSign;
        $origin[$axis] = ($fastener->sink - $size[$axis] / 2) * $sign;
        $direction = [0.0, 0.0, 0.0];
        $direction[$axis] = (float) $sign;

        $origin = $this->orient($origin, $source);
        foreach (['position_x', 'position_y', 'position_z'] as $index => $field) {
            $origin[$index] += $source->{$field} - $target->{$field};
        }
        $origin = $this->orient($origin, $target, true);
        $direction = $this->orient($this->orient($direction, $source), $target, true);
        $targetPart = $target->partDefinition;
        $size = $this->dimensions($targetPart);

        foreach (self::Faces as $face => [$u, $uSign, $v, $vSign, $axis, $sign]) {
            if (abs($direction[$axis] - $sign) > self::Tolerance || abs($direction[$u]) > self::Tolerance || abs($direction[$v]) > self::Tolerance) {
                continue;
            }

            return [
                'face' => $face, 'u' => $origin[$u] * $uSign + $size[$u] / 2, 'v' => $origin[$v] * $vSign + $size[$v] / 2,
                'entry' => -$size[$axis] / 2 - $origin[$axis] * $sign, 'depth' => $size[$axis],
            ];
        }

        return null;
    }

    private function operation(ProjectFastener $fastener, string $role, PartDefinition $part, string $face, float $centerU, float $centerV, float $diameter, float $depth, bool $through, ?float $bottomDiameter = null): array
    {
        [$u, , $v] = self::Faces[$face];
        $size = $this->dimensions($part);
        $centerU = round($centerU, 4);
        $centerV = round($centerV, 4);
        if ($centerU - $diameter / 2 < 0 || $centerV - $diameter / 2 < 0
            || $centerU + $diameter / 2 > $size[$u] || $centerV + $diameter / 2 > $size[$v]) {
            $feature = $role === 'countersink' ? 'Зенковка' : 'Отверстие';
            $this->invalid("{$feature} под шуруп #{$fastener->id} выходит за грань заготовки «{$part->name}».");
        }

        $instanceId = $role === 'pilot' ? $fastener->target_instance_id : $fastener->source_instance_id;

        return [
            'id' => $this->operationId($instanceId, $face, $centerU, $centerV, $role),
            'type' => 'drill', 'status' => 'applied', 'enabled' => true,
            'face' => $face, 'center_u' => $centerU, 'center_v' => $centerV,
            'diameter' => $diameter, 'depth' => $depth, 'through' => $through,
            ...($bottomDiameter === null ? [] : ['bottom_diameter' => $bottomDiameter]),
        ];
    }

    private function operationId(int $instanceId, string $face, float $centerU, float $centerV, string $role): string
    {
        $location = substr(hash('sha256', json_encode([$instanceId, $face, round($centerU, 4), round($centerV, 4)], JSON_THROW_ON_ERROR)), 0, 24);

        return "fastener-{$location}-{$role}";
    }

    private function dimensions(PartDefinition $part): array
    {
        return [$part->length, $part->width, $part->thickness];
    }

    private function orient(array $vector, PartInstance $instance, bool $inverse = false): array
    {
        $rotations = [[$instance->rotation_x, 1, 2], [$instance->rotation_y, 2, 0], [$instance->rotation_z, 0, 1]];
        if (! $inverse && $instance->mirrored) {
            $vector[0] *= -1;
        }
        foreach ($inverse ? array_reverse($rotations) : $rotations as [$angle, $a, $b]) {
            $radians = deg2rad($inverse ? -$angle : $angle);
            [$vector[$a], $vector[$b]] = [
                cos($radians) * $vector[$a] - sin($radians) * $vector[$b],
                sin($radians) * $vector[$a] + cos($radians) * $vector[$b],
            ];
        }
        if ($inverse && $instance->mirrored) {
            $vector[0] *= -1;
        }

        return $vector;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['drilling' => $message]);
    }
}
