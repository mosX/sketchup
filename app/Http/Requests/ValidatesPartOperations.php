<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

trait ValidatesPartOperations
{
    use ValidatesAdvancedMachining;

    private const string StockEdges = 'top_left,top_right,bottom_left,bottom_right,top_start,top_end,bottom_start,bottom_end,start_left,start_right,end_left,end_right';

    /**
     * @return array<string, array<mixed>>
     */
    protected function operationRules(string $attribute = 'operations'): array
    {
        return [
            $attribute => ['sometimes', 'array', 'max:50'],
            "{$attribute}.*.id" => ['sometimes', 'string', 'max:100'],
            "{$attribute}.*.type" => ['required', 'string', 'in:cross_cut,rip_cut,groove,edge_roundover,plunge_route,drill,rabbet,pocket,edge_chamfer,jigsaw_cut,'.implode(',', self::AdvancedMachiningTypes)],
            "{$attribute}.*.status" => ['sometimes', 'string', 'in:draft,applied'],
            "{$attribute}.*.enabled" => ['sometimes', 'boolean'],
            "{$attribute}.*.position" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.miter_angle" => ['sometimes', 'numeric', 'between:-60,60'],
            "{$attribute}.*.bevel_angle" => ['sometimes', 'numeric', 'between:-45,45'],
            "{$attribute}.*.kerf" => ['sometimes', 'numeric', 'min:0', 'max:20'],
            "{$attribute}.*.cut_depth" => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            "{$attribute}.*.cut_direction" => ['sometimes', 'string', 'in:top_down,bottom_up'],
            "{$attribute}.*.keep_side" => ['sometimes', 'string', 'in:start,end,reference,opposite'],
            "{$attribute}.*.reference_side" => ['sometimes', 'string', 'in:left,right'],
            "{$attribute}.*.start_offset" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.end_offset" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.face" => ['sometimes', 'string', 'in:top,bottom,left,right,start,end'],
            "{$attribute}.*.direction" => ['sometimes', 'string', 'in:length,width'],
            "{$attribute}.*.offset" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.start" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.end" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.center_u" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.center_v" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.path_angle" => ['sometimes', 'numeric', 'between:-180,180'],
            "{$attribute}.*.groove_length" => ['sometimes', 'numeric', 'min:0.1', 'max:100000'],
            "{$attribute}.*.width" => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            "{$attribute}.*.depth" => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            "{$attribute}.*.blade_diameter" => ['sometimes', 'numeric', 'min:1', 'max:2000'],
            "{$attribute}.*.edge" => ['sometimes', 'string', 'in:'.self::StockEdges],
            "{$attribute}.*.radius" => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            "{$attribute}.*.route_mode" => ['sometimes', 'string', 'in:point,path'],
            "{$attribute}.*.start_u" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.start_v" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.travel_length" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.cutter_diameter" => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            "{$attribute}.*.cutter_profile" => ['sometimes', 'string', 'in:straight,dovetail,v_groove'],
            "{$attribute}.*.cutter_angle" => ['sometimes', 'numeric', 'between:0,170'],
            "{$attribute}.*.diameter" => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            "{$attribute}.*.bottom_diameter" => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            "{$attribute}.*.through" => ['sometimes', 'boolean'],
            "{$attribute}.*.cut_length" => ['sometimes', 'numeric', 'between:0.1,100000'],
            "{$attribute}.*.pocket_shape" => ['sometimes', 'string', 'in:rectangle,circle'],
            "{$attribute}.*.pocket_length" => ['sometimes', 'numeric', 'between:0.1,100000'],
            "{$attribute}.*.corner_radius" => ['sometimes', 'numeric', 'between:0,10000'],
            "{$attribute}.*.points" => ['sometimes', 'array', 'list', 'min:2', 'max:64'],
            "{$attribute}.*.points.*" => ['required', 'array:u,v'],
            "{$attribute}.*.points.*.u" => ['required', 'numeric', 'between:-100000,200000'],
            "{$attribute}.*.points.*.v" => ['required', 'numeric', 'between:-100000,200000'],
            ...$this->advancedMachiningRules("{$attribute}.*"),
        ];
    }

    /**
     * @return array<callable(Validator): void>
     */
    protected function operationValidation(float $length, float $width, float $thickness): array
    {
        return [fn (Validator $validator) => $this->validatePartOperations(
            $validator,
            $this->input('operations', []),
            $length,
            $width,
            $thickness,
        )];
    }

    /**
     * @param  array<int, mixed>  $operations
     */
    protected function validatePartOperations(
        Validator $validator,
        array $operations,
        float $length,
        float $width,
        float $thickness,
        string $attribute = 'operations',
    ): void {
        foreach ($operations as $index => $operation) {
            if (! is_array($operation) || ! isset($operation['type'])) {
                continue;
            }

            $field = fn (string $name): string => "{$attribute}.{$index}.{$name}";
            $require = function (array $names) use ($validator, $operation, $field): bool {
                $valid = true;

                foreach ($names as $name) {
                    if (! array_key_exists($name, $operation)) {
                        $validator->errors()->add($field($name), 'Это поле обязательно для выбранной операции.');
                        $valid = false;
                    }
                }

                return $valid;
            };

            if ($operation['type'] === 'cross_cut') {
                if (! $require(['position', 'miter_angle', 'bevel_angle', 'kerf', 'keep_side'])) {
                    continue;
                }

                if (is_numeric($operation['position']) && (float) $operation['position'] > $length) {
                    $validator->errors()->add($field('position'), 'Положение реза не может быть больше длины заготовки.');
                }

                if (! in_array($operation['keep_side'], ['start', 'end'], true)) {
                    $validator->errors()->add($field('keep_side'), 'Для поперечного реза можно оставить начало или конец заготовки.');
                }

                if (isset($operation['cut_depth']) && is_numeric($operation['cut_depth']) && (float) $operation['cut_depth'] > $thickness) {
                    $validator->errors()->add($field('cut_depth'), 'Глубина пропила не может быть больше толщины заготовки.');
                }
            }

            if ($operation['type'] === 'rip_cut') {
                if (! $require(['reference_side', 'start_offset', 'end_offset', 'bevel_angle', 'kerf', 'keep_side'])) {
                    continue;
                }

                foreach (['start_offset', 'end_offset'] as $name) {
                    if (is_numeric($operation[$name]) && (float) $operation[$name] > $width) {
                        $validator->errors()->add($field($name), 'Отступ не может быть больше ширины заготовки.');
                    }
                }

                if (! in_array($operation['keep_side'], ['reference', 'opposite'], true)) {
                    $validator->errors()->add($field('keep_side'), 'Для продольного реза выберите сторону относительно базы.');
                }

                if (isset($operation['cut_depth']) && is_numeric($operation['cut_depth']) && (float) $operation['cut_depth'] > $thickness) {
                    $validator->errors()->add($field('cut_depth'), 'Глубина пропила не может быть больше толщины заготовки.');
                }
            }

            if ($operation['type'] === 'groove') {
                if (array_key_exists('center_u', $operation) || array_key_exists('center_v', $operation) || array_key_exists('path_angle', $operation) || array_key_exists('groove_length', $operation)) {
                    $this->validateSurfaceGroove($validator, $operation, $length, $width, $thickness, $field, $require);

                    continue;
                }

                if (! $require(['face', 'direction', 'offset', 'start', 'end', 'width', 'depth', 'blade_diameter'])) {
                    continue;
                }

                if (is_numeric($operation['depth']) && (float) $operation['depth'] > $thickness) {
                    $validator->errors()->add($field('depth'), 'Глубина паза не может быть больше толщины заготовки.');
                }

                if (is_numeric($operation['start']) && is_numeric($operation['end']) && (float) $operation['end'] <= (float) $operation['start']) {
                    $validator->errors()->add($field('end'), 'Конец паза должен находиться после его начала.');
                }

                $travel = $operation['direction'] === 'width' ? $width : $length;
                $across = $operation['direction'] === 'width' ? $length : $width;

                if (is_numeric($operation['end']) && (float) $operation['end'] > $travel) {
                    $validator->errors()->add($field('end'), 'Паз выходит за габарит заготовки.');
                }

                if (is_numeric($operation['offset']) && is_numeric($operation['width'])) {
                    $nearEdge = (float) $operation['offset'] - (float) $operation['width'] / 2;
                    $farEdge = (float) $operation['offset'] + (float) $operation['width'] / 2;

                    if ($nearEdge < 0 || $farEdge > $across) {
                        $validator->errors()->add($field('offset'), 'Паз с такой шириной выходит за край заготовки.');
                    }
                }
            }

            if ($operation['type'] === 'edge_roundover') {
                if (! $require(['edge', 'radius'])) {
                    continue;
                }

                $maximumRadius = match ($operation['edge']) {
                    'top_left', 'top_right', 'bottom_left', 'bottom_right' => min($width, $thickness) / 2,
                    'top_start', 'top_end', 'bottom_start', 'bottom_end' => min($length, $thickness) / 2,
                    'start_left', 'start_right', 'end_left', 'end_right' => min($length, $width) / 2,
                    default => null,
                };

                if ($maximumRadius !== null && is_numeric($operation['radius']) && (float) $operation['radius'] > $maximumRadius) {
                    $validator->errors()->add($field('radius'), 'Радиус скругления слишком большой для выбранной кромки.');
                }
            }

            if ($operation['type'] === 'plunge_route') {
                $this->validatePlungeRoute($validator, $operation, $length, $width, $thickness, $field, $require);
            }

            if ($operation['type'] === 'drill') {
                $this->validateDrill($validator, $operation, $length, $width, $thickness, $field, $require);
            }

            if (in_array($operation['type'], ['rabbet', 'pocket', 'edge_chamfer', 'jigsaw_cut'], true)) {
                $this->validateShaping($validator, $operation, $length, $width, $thickness, $field, $require);
            }
            if (in_array($operation['type'], self::AdvancedMachiningTypes, true)) {
                $this->validateAdvancedMachining($validator, $operation, $length, $width, $thickness, $field, $require);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  callable(string): string  $field
     * @param  callable(list<string>): bool  $require
     */
    private function validateShaping(Validator $validator, array $operation, float $length, float $width, float $thickness, callable $field, callable $require): void
    {
        $type = $operation['type'];
        $required = match ($type) {
            'rabbet' => ['edge', 'face', 'start', 'cut_length', 'width', 'depth'],
            'edge_chamfer' => ['edge', 'width'],
            'pocket' => ['face', 'pocket_shape', 'center_u', 'center_v', 'depth', ...(($operation['pocket_shape'] ?? null) === 'circle' ? ['diameter'] : ['pocket_length', 'width'])],
            'jigsaw_cut' => ['face', 'points'],
        };
        if (! $require($required) || $validator->errors()->hasAny(array_map($field, $required))) {
            return;
        }

        $normal = ['top' => $thickness, 'bottom' => $thickness, 'left' => $width, 'right' => $width, 'start' => $length, 'end' => $length];
        if ($type === 'edge_chamfer' || $type === 'rabbet') {
            $sides = explode('_', $operation['edge']);
            [$sideA, $sideB] = $sides;
            $axisSize = match ($sideA) {
                'start', 'end' => $thickness,
                default => in_array($sideB, ['left', 'right'], true) ? $length : $width,
            };
            if ($type === 'edge_chamfer') {
                if ((float) $operation['width'] >= min($normal[$sideA], $normal[$sideB])) {
                    $validator->errors()->add($field('width'), 'Фаска слишком широкая для выбранной кромки.');
                }

                return;
            }
            $face = $operation['face'];
            if (! in_array($face, $sides, true)) {
                $validator->errors()->add($field('face'), 'Для четверти выберите грань, примыкающую к кромке.');

                return;
            }
            $across = $face === $sideA ? $sideB : $sideA;
            if ((float) $operation['width'] >= $normal[$across]) {
                $validator->errors()->add($field('width'), 'Ширина четверти должна быть меньше размера заготовки по грани.');
            }
            if ((float) $operation['depth'] >= $normal[$face]) {
                $validator->errors()->add($field('depth'), 'Глубина четверти должна быть меньше размера заготовки по нормали грани.');
            }
            if ((float) $operation['start'] + (float) $operation['cut_length'] > $axisSize + 1e-8) {
                $validator->errors()->add($field('cut_length'), 'Выборка выходит за конец выбранной кромки.');
            }

            return;
        }

        [$surfaceU, $surfaceV] = match ($operation['face']) {
            'left', 'right' => [$length, $thickness],
            'start', 'end' => [$width, $thickness],
            default => [$length, $width],
        };
        if ($type === 'jigsaw_cut') {
            $points = $operation['points'];
            foreach ($points as $index => $point) {
                if ($validator->errors()->hasAny([$field("points.$index"), $field("points.$index.u"), $field("points.$index.v")])) {
                    return;
                }
            }
            $vertices = array_map(fn (array $point): array => [(float) $point['u'], (float) $point['v']], $points);
            if (! $this->validJigsawContour($vertices, $surfaceU, $surfaceV)) {
                $validator->errors()->add($field('points'), 'Контур должен быть замкнутым, без пересечений и удалять только часть грани.');
            }

            return;
        }

        $circular = $operation['pocket_shape'] === 'circle';
        $sizeU = (float) ($circular ? $operation['diameter'] : $operation['pocket_length']);
        $sizeV = (float) ($circular ? $operation['diameter'] : $operation['width']);
        foreach (['center_u' => [$sizeU, $surfaceU], 'center_v' => [$sizeV, $surfaceV]] as $key => [$size, $limit]) {
            $center = (float) $operation[$key];
            if ($center <= $size / 2 || $center >= $limit - $size / 2) {
                $validator->errors()->add($field($key), 'Карман должен целиком помещаться внутри выбранной грани.');
            }
        }
        if ((float) $operation['depth'] >= $normal[$operation['face']]) {
            $validator->errors()->add($field('depth'), 'У кармана должно оставаться дно.');
        }
        if (! $circular && is_numeric($operation['corner_radius'] ?? null) && (float) $operation['corner_radius'] > min($sizeU, $sizeV) / 2) {
            $validator->errors()->add($field('corner_radius'), 'Радиус угла слишком большой для этого кармана.');
        }
    }

    /** @param list<array{float, float}> $points */
    private function isSimpleMachiningPath(array $points, bool $closed): bool
    {
        $count = count($points);
        if ($count < ($closed ? 3 : 2)) {
            return false;
        }
        $segments = $count - ($closed ? 0 : 1);
        $cross = fn (array $a, array $b, array $c): float => ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        $turn = function (array $a, array $b, array $c) use ($cross): int {
            $value = $cross($a, $b, $c);

            return abs($value) <= 1e-8 ? 0 : ($value <=> 0);
        };
        $on = fn (array $p, array $a, array $b): bool => $p[0] >= min($a[0], $b[0]) - 1e-8 && $p[0] <= max($a[0], $b[0]) + 1e-8
            && $p[1] >= min($a[1], $b[1]) - 1e-8 && $p[1] <= max($a[1], $b[1]) + 1e-8;
        for ($index = 0; $index < $segments; $index++) {
            [$a, $b] = [$points[$index], $points[($index + 1) % $count]];
            $c = $closed ? $points[($index + 2) % $count] : ($points[$index + 2] ?? null);
            if (hypot($a[0] - $b[0], $a[1] - $b[1]) < 0.001
                || ($c !== null && $turn($a, $b, $c) === 0 && ($a[0] - $b[0]) * ($c[0] - $b[0]) + ($a[1] - $b[1]) * ($c[1] - $b[1]) > 0)) {
                return false;
            }
            for ($other = $index + 2; $other < $segments; $other++) {
                if ($closed && $index === 0 && $other === $segments - 1) {
                    continue;
                }
                [$c, $d] = [$points[$other], $points[($other + 1) % $count]];
                [$abc, $abd, $cda, $cdb] = [$turn($a, $b, $c), $turn($a, $b, $d), $turn($c, $d, $a), $turn($c, $d, $b)];
                if (($abc * $abd < 0 && $cda * $cdb < 0) || ($abc === 0 && $on($c, $a, $b)) || ($abd === 0 && $on($d, $a, $b))
                    || ($cda === 0 && $on($a, $c, $d)) || ($cdb === 0 && $on($b, $c, $d))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<array{float, float}> $points */
    private function validJigsawContour(array $points, float $u, float $v): bool
    {
        if (! $this->isSimpleMachiningPath($points, true)) {
            return false;
        }
        $clipped = $points;
        foreach ([[0, 0.0, 1], [0, $u, -1], [1, 0.0, 1], [1, $v, -1]] as [$axis, $boundary, $sign]) {
            $next = [];
            $count = count($clipped);
            for ($index = 0; $index < $count; $index++) {
                $a = $clipped[$index];
                $b = $clipped[($index + 1) % $count];
                $here = ($a[$axis] - $boundary) * $sign;
                $there = ($b[$axis] - $boundary) * $sign;
                if ($here >= 0) {
                    $next[] = $a;
                }
                if (($here < 0 && $there > 0) || ($here > 0 && $there < 0)) {
                    $fraction = $here / ($here - $there);
                    $next[] = [$a[0] + ($b[0] - $a[0]) * $fraction, $a[1] + ($b[1] - $a[1]) * $fraction];
                }
            }
            $clipped = $next;
        }

        $doubledArea = 0;
        $cross = fn (array $a, array $b, array $c): float => ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        for ($index = 1; $index < count($clipped) - 1; $index++) {
            $doubledArea += $cross($clipped[0], $clipped[$index], $clipped[$index + 1]);
        }
        $area = abs($doubledArea) / 2;

        return $area > 0.00005 && $area < $u * $v - 0.00005;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  callable(string): string  $field
     * @param  callable(array<int, string>): bool  $require
     */
    protected function validateDrill(
        Validator $validator,
        array $operation,
        float $length,
        float $width,
        float $thickness,
        callable $field,
        callable $require,
    ): void {
        if (! $require(['face', 'center_u', 'center_v', 'diameter', 'depth', 'through'])) {
            return;
        }

        [$surfaceU, $surfaceV, $maximumDepth] = match ($operation['face']) {
            'top', 'bottom' => [$length, $width, $thickness],
            'left', 'right' => [$length, $thickness, $width],
            'start', 'end' => [$width, $thickness, $length],
            default => [0.0, 0.0, 0.0],
        };

        if (is_numeric($operation['depth']) && (float) $operation['depth'] > $maximumDepth) {
            $validator->errors()->add($field('depth'), 'Глубина сверления не может превышать размер заготовки по нормали выбранной грани.');
        }

        if (! is_numeric($operation['center_u']) || ! is_numeric($operation['center_v']) || ! is_numeric($operation['diameter'])) {
            return;
        }

        $radius = (float) $operation['diameter'] / 2;
        if (isset($operation['bottom_diameter']) && is_numeric($operation['bottom_diameter']) && (float) $operation['bottom_diameter'] >= $radius * 2) {
            $validator->errors()->add($field('bottom_diameter'), 'Нижний диаметр должен быть меньше диаметра на поверхности.');
        }
        $centerU = (float) $operation['center_u'];
        $centerV = (float) $operation['center_v'];

        if ($centerU - $radius < 0 || $centerU + $radius > $surfaceU) {
            $validator->errors()->add($field('center_u'), 'Отверстие выходит за границу выбранной поверхности по оси U.');
        }

        if ($centerV - $radius < 0 || $centerV + $radius > $surfaceV) {
            $validator->errors()->add($field('center_v'), 'Отверстие выходит за границу выбранной поверхности по оси V.');
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  callable(string): string  $field
     * @param  callable(array<int, string>): bool  $require
     */
    protected function validatePlungeRoute(
        Validator $validator,
        array $operation,
        float $length,
        float $width,
        float $thickness,
        callable $field,
        callable $require,
    ): void {
        if (! $require(['face', 'route_mode', 'start_u', 'start_v', 'path_angle', 'travel_length', 'cutter_diameter', 'cutter_profile', 'cutter_angle', 'depth'])) {
            return;
        }

        [$surfaceU, $surfaceV, $maximumDepth] = match ($operation['face']) {
            'top', 'bottom' => [$length, $width, $thickness],
            'left', 'right' => [$length, $thickness, $width],
            'start', 'end' => [$width, $thickness, $length],
            default => [0.0, 0.0, 0.0],
        };

        if (is_numeric($operation['depth']) && (float) $operation['depth'] > $maximumDepth) {
            $validator->errors()->add($field('depth'), 'Глубина фрезерования не может превышать размер заготовки по нормали выбранной грани.');
        }

        if ($operation['route_mode'] === 'point' && is_numeric($operation['travel_length']) && (float) $operation['travel_length'] !== 0.0) {
            $validator->errors()->add($field('travel_length'), 'Для одиночного погружения расстояние прохода должно быть равно нулю.');
        }

        if ($operation['route_mode'] === 'path' && is_numeric($operation['travel_length']) && (float) $operation['travel_length'] < 0.1) {
            $validator->errors()->add($field('travel_length'), 'Для прохода укажите расстояние не меньше 0,1 мм.');
        }

        if (! is_numeric($operation['start_u']) || ! is_numeric($operation['start_v']) || ! is_numeric($operation['path_angle']) || ! is_numeric($operation['travel_length']) || ! is_numeric($operation['cutter_diameter']) || ! is_numeric($operation['cutter_angle']) || ! is_numeric($operation['depth'])) {
            return;
        }

        $radius = (float) $operation['cutter_diameter'] / 2;
        $cutterAngle = (float) $operation['cutter_angle'];
        $depth = (float) $operation['depth'];

        if ($operation['cutter_profile'] === 'straight' && $cutterAngle !== 0.0) {
            $validator->errors()->add($field('cutter_angle'), 'Для прямой фрезы угол профиля должен быть равен нулю.');
        }

        if ($operation['cutter_profile'] === 'dovetail') {
            if ($cutterAngle < 1 || $cutterAngle > 45) {
                $validator->errors()->add($field('cutter_angle'), 'Угол фрезы «ласточкин хвост» должен быть от 1 до 45 градусов.');
            } elseif ($radius - $depth * tan(deg2rad($cutterAngle)) < 0.05) {
                $validator->errors()->add($field('depth'), 'Для выбранного диаметра и угла глубина оставляет слишком узкую шейку фрезы.');
            }
        }

        if ($operation['cutter_profile'] === 'v_groove') {
            if ($cutterAngle < 10) {
                $validator->errors()->add($field('cutter_angle'), 'Угол V-образной фрезы должен быть от 10 до 170 градусов.');
            } elseif ($depth > $radius / tan(deg2rad($cutterAngle / 2))) {
                $validator->errors()->add($field('depth'), 'Для выбранного диаметра и угла V-образная фреза не может погрузиться на такую глубину.');
            }

            $radius = min($radius, $depth * tan(deg2rad($cutterAngle / 2)));
        }

        $travelLength = $operation['route_mode'] === 'path' ? (float) $operation['travel_length'] : 0.0;
        $angle = deg2rad((float) $operation['path_angle']);
        $startU = (float) $operation['start_u'];
        $startV = (float) $operation['start_v'];
        $endU = $startU + cos($angle) * $travelLength;
        $endV = $startV + sin($angle) * $travelLength;

        if (min($startU, $endU) - $radius < 0 || max($startU, $endU) + $radius > $surfaceU) {
            $validator->errors()->add($field('start_u'), 'Траектория фрезы выходит за границу выбранной поверхности по оси U.');
        }

        if (min($startV, $endV) - $radius < 0 || max($startV, $endV) + $radius > $surfaceV) {
            $validator->errors()->add($field('start_v'), 'Траектория фрезы выходит за границу выбранной поверхности по оси V.');
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  callable(string): string  $field
     * @param  callable(array<int, string>): bool  $require
     */
    protected function validateSurfaceGroove(
        Validator $validator,
        array $operation,
        float $length,
        float $width,
        float $thickness,
        callable $field,
        callable $require,
    ): void {
        if (! $require(['face', 'center_u', 'center_v', 'path_angle', 'groove_length', 'width', 'depth', 'blade_diameter'])) {
            return;
        }

        [$surfaceU, $surfaceV, $maximumDepth] = match ($operation['face']) {
            'top', 'bottom' => [$length, $width, $thickness],
            'left', 'right' => [$length, $thickness, $width],
            'start', 'end' => [$width, $thickness, $length],
            default => [0.0, 0.0, 0.0],
        };

        if (is_numeric($operation['depth']) && (float) $operation['depth'] > $maximumDepth) {
            $validator->errors()->add($field('depth'), 'Глубина паза не может быть больше размера заготовки по нормали выбранной грани.');
        }

        if (! is_numeric($operation['center_u']) || ! is_numeric($operation['center_v']) || ! is_numeric($operation['path_angle']) || ! is_numeric($operation['groove_length']) || ! is_numeric($operation['width'])) {
            return;
        }

        $angle = deg2rad((float) $operation['path_angle']);
        $halfLength = (float) $operation['groove_length'] / 2;
        $halfWidth = (float) $operation['width'] / 2;
        $extentU = abs(cos($angle)) * $halfLength + abs(sin($angle)) * $halfWidth;
        $extentV = abs(sin($angle)) * $halfLength + abs(cos($angle)) * $halfWidth;
        $centerU = (float) $operation['center_u'];
        $centerV = (float) $operation['center_v'];

        if ($centerU - $extentU < 0 || $centerU + $extentU > $surfaceU) {
            $validator->errors()->add($field('center_u'), 'Паз выходит за границу выбранной поверхности по оси U.');
        }

        if ($centerV - $extentV < 0 || $centerV + $extentV > $surfaceV) {
            $validator->errors()->add($field('center_v'), 'Паз выходит за границу выбранной поверхности по оси V.');
        }
    }
}
