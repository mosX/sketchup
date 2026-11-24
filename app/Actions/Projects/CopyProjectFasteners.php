<?php

namespace App\Actions\Projects;

use App\Exceptions\ProjectRevisionConflictException;
use App\Models\Project;
use App\Models\ProjectFastener;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CopyProjectFasteners
{
    public function __construct(private PrepareFastenerDrilling $drilling) {}

    /**
     * @param  array{fastener_ids: list<int>, source_instance_id: int, target_instance_id?: int|null, row_target_instance_ids?: list<int|null>, face: string, mirror_u?: bool, mirror_v?: bool, dry_run: bool, expected_revision?: int}  $settings
     */
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
            $original = $fasteners->first();
            if ($fasteners->contains(fn (ProjectFastener $fastener): bool => $fastener->source_instance_id !== $original->source_instance_id || $fastener->face !== $original->face)) {
                throw ValidationException::withMessages(['fastener_ids' => 'Выберите крепёж с одной грани одной детали.']);
            }

            $destinationId = (int) $settings['source_instance_id'];
            $rowTargets = $settings['row_target_instance_ids'] ?? array_fill(0, $fasteners->count(), $settings['target_instance_id'] ?? null);
            if (count($rowTargets) !== $fasteners->count()) {
                throw ValidationException::withMessages(['row_target_instance_ids' => 'Укажите вторую деталь или пустое значение для каждой точки ряда.']);
            }
            $targets = collect($rowTargets)->filter()->map(fn (int|string $id): int => (int) $id)->unique();
            $instanceIds = $fasteners->pluck('target_instance_id')->push($original->source_instance_id, $destinationId)->merge($targets)->filter()->unique();
            $instances = $project->partInstances()->with('partDefinition')->whereKey($instanceIds->all())->get()->keyBy('id');
            $destination = $instances->get($destinationId);
            if ($destination === null || $targets->contains($destinationId) || $targets->diff($instances->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['source_instance_id' => 'Выберите детали этого проекта.']);
            }

            $part = $destination->partDefinition;
            $face = $settings['face'];
            [$uSize, $vSize, $depth] = match ($face) {
                'top', 'bottom' => [$part->length, $part->width, $part->thickness],
                'left', 'right' => [$part->length, $part->thickness, $part->width],
                'start', 'end' => [$part->width, $part->thickness, $part->length],
            };
            $occupied = $project->projectFasteners()
                ->where('source_instance_id', $destinationId)->where('face', $face)
                ->get(['center_u', 'center_v'])->mapWithKeys(fn (ProjectFastener $fastener): array => ["{$fastener->center_u}:{$fastener->center_v}" => true])->all();
            $placements = [];
            foreach ($fasteners as $index => $fastener) {
                $u = round(($settings['mirror_u'] ?? false) ? $uSize - $fastener->center_u : $fastener->center_u, 2);
                $v = round(($settings['mirror_v'] ?? false) ? $vSize - $fastener->center_v : $fastener->center_v, 2);
                $radius = $fastener->diameter / 2;
                if ($u < $radius || $u > $uSize - $radius || $v < $radius || $v > $vSize - $radius || $fastener->sink > $depth) {
                    throw ValidationException::withMessages(['face' => 'Ряд не помещается на выбранной грани. Проверьте её размеры и глубину посадки.']);
                }
                if (isset($occupied["{$u}:{$v}"])) {
                    throw ValidationException::withMessages(['face' => 'Одна из точек уже занята. Выберите другую грань или отразите ряд.']);
                }
                $occupied["{$u}:{$v}"] = true;
                $placements[] = new ProjectFastener([
                    ...$fastener->only(['catalog_material_id', 'sink', 'fastener_type', 'diameter', 'length']),
                    'source_instance_id' => $destinationId, 'target_instance_id' => $rowTargets[$index],
                    'face' => $face, 'center_u' => $u, 'center_v' => $v,
                ]);
            }

            $drilling = $this->drilling->savedSettings($fasteners->where('fastener_type', 'screw'), $instances);
            if (! $settings['dry_run']) {
                $project->projectFasteners()->saveMany($placements);
                $project->increment('revision');
            }

            return ['revision' => $project->revision, 'placements' => $placements, 'drilling_settings' => $drilling];
        }, attempts: 3);
    }
}
