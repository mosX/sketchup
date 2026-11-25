<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

trait ValidatesAdvancedMachining
{
    private const array AdvancedMachiningTypes = ['drill_row', 'pocket_screw', 'curve_route', 'taper', 'turning'];

    /** @return array<string, array<string>> */
    private function advancedMachiningRules(string $prefix): array
    {
        return [
            "$prefix.hole_count" => ['sometimes', 'integer', 'between:1,40'],
            "$prefix.hole_spacing" => ['sometimes', 'numeric', 'between:0.1,100000'],
            "$prefix.entry_angle" => ['sometimes', 'numeric', 'between:10,75'],
            "$prefix.pilot_diameter" => ['sometimes', 'numeric', 'between:0.1,10000'],
            "$prefix.pilot_length" => ['sometimes', 'numeric', 'between:0.1,100000'],
            "$prefix.closed_path" => ['sometimes', 'boolean'],
            "$prefix.smooth_path" => ['sometimes', 'boolean'],
            "$prefix.taper_sides" => ['sometimes', 'string', 'in:two,four'],
            "$prefix.start_width" => ['sometimes', 'numeric', 'between:0.1,100000'],
            "$prefix.end_width" => ['sometimes', 'numeric', 'between:0.1,100000'],
            "$prefix.start_height" => ['sometimes', 'numeric', 'between:0.1,10000'],
            "$prefix.end_height" => ['sometimes', 'numeric', 'between:0.1,10000'],
            "$prefix.smooth_profile" => ['sometimes', 'boolean'],
            "$prefix.stations" => ['sometimes', 'array', 'list', 'between:2,24'],
            "$prefix.stations.*" => ['required', 'array:at,diameter'],
            "$prefix.stations.*.at" => ['required', 'numeric', 'between:0,100000'],
            "$prefix.stations.*.diameter" => ['required', 'numeric', 'between:0.1,10000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  callable(string): string  $field
     * @param  callable(list<string>): bool  $require
     */
    private function validateAdvancedMachining(Validator $validator, array $operation, float $length, float $width, float $thickness, callable $field, callable $require): void
    {
        $type = $operation['type'];
        $fields = match ($type) {
            'drill_row' => ['face', 'center_u', 'center_v', 'path_angle', 'diameter', 'depth', 'through', 'hole_count', 'hole_spacing'],
            'pocket_screw' => ['face', 'center_u', 'center_v', 'path_angle', 'entry_angle', 'diameter', 'depth', 'pilot_diameter', 'pilot_length'],
            'curve_route' => ['face', 'points', 'cutter_diameter', 'cutter_profile', 'cutter_angle', 'depth', 'closed_path'],
            'taper' => ['taper_sides', 'start_width', 'end_width', ...(($operation['taper_sides'] ?? null) === 'four' ? ['start_height', 'end_height'] : [])],
            'turning' => ['stations'],
        };
        if (! $require($fields) || $validator->errors()->hasAny(array_map($field, $fields))) {
            return;
        }
        if ($type === 'taper') {
            foreach (['start_width' => $width, 'end_width' => $width, 'start_height' => $thickness, 'end_height' => $thickness] as $key => $max) {
                if (in_array($key, $fields, true) && (float) $operation[$key] > $max) {
                    $validator->errors()->add($field($key), 'Размер торца не может быть больше исходной заготовки.');
                }
            }

            return;
        }
        if ($type === 'turning') {
            $this->validateTurningStations($validator, $operation['stations'], $length, min($width, $thickness), $field);

            return;
        }
        [$u, $v, $normal] = match ($operation['face']) {
            'left', 'right' => [$length, $thickness, $width],
            'start', 'end' => [$width, $thickness, $length],
            default => [$length, $width, $thickness],
        };
        if ($type === 'curve_route') {
            $this->validateCurveRoute($validator, $operation, $u, $v, $normal, $field);

            return;
        }

        $radius = (float) $operation['diameter'] / 2;
        $heading = deg2rad((float) $operation['path_angle']);
        $centerU = (float) $operation['center_u'];
        $centerV = (float) $operation['center_v'];
        $depth = (float) $operation['depth'];
        if ($type === 'drill_row') {
            $span = ((int) $operation['hole_count'] - 1) * (float) $operation['hole_spacing'];
            if ($span > 0 && (float) $operation['hole_spacing'] < 2 * $radius + 0.05 - 1e-7) {
                $validator->errors()->add($field('hole_spacing'), 'Увеличьте шаг ряда, чтобы отверстия не пересекались.');
            }
            $lastU = $centerU + cos($heading) * $span;
            $lastV = $centerV + sin($heading) * $span;
            if (min($centerU, $lastU) < $radius - 1e-7 || max($centerU, $lastU) > $u - $radius + 1e-7
                || min($centerV, $lastV) < $radius - 1e-7 || max($centerV, $lastV) > $v - $radius + 1e-7) {
                $validator->errors()->add($field('hole_count'), 'Ряд отверстий выходит за выбранную грань.');
            }
            if ($depth > $normal + 1e-7) {
                $validator->errors()->add($field('depth'), 'Глубина сверления больше размера заготовки.');
            }

            return;
        }

        $angle = deg2rad((float) $operation['entry_angle']);
        $spread = sin($angle);
        $radiusU = $radius * hypot(cos($heading) / $spread, sin($heading));
        $radiusV = $radius * hypot(sin($heading) / $spread, cos($heading));
        if ($centerU < $radiusU - 1e-7 || $centerU > $u - $radiusU + 1e-7
            || $centerV < $radiusV - 1e-7 || $centerV > $v - $radiusV + 1e-7) {
            $validator->errors()->add($field('center_u'), 'Вход ступенчатого сверла выходит за выбранную грань.');
        }
        if ($depth * $spread > $normal + 1e-7) {
            $validator->errors()->add($field('depth'), 'Ступень сверления выходит за противоположную грань.');
        }
        if ((float) $operation['pilot_diameter'] >= 2 * $radius) {
            $validator->errors()->add($field('pilot_diameter'), 'Диаметр пилота должен быть меньше диаметра ступени.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $stations
     * @param  callable(string): string  $field
     */
    private function validateTurningStations(Validator $validator, array $stations, float $length, float $maximumDiameter, callable $field): void
    {
        $previous = -0.01;
        foreach ($stations as $index => $station) {
            if ($validator->errors()->hasAny([$field("stations.$index"), $field("stations.$index.at"), $field("stations.$index.diameter")])) {
                return;
            }
            $at = (float) $station['at'];
            if ($at - $previous < 0.01 - 1e-7 || $at > $length + 1e-7
                || ($index === 0 && abs($at) > 1e-7) || ($index === count($stations) - 1 && abs($at - $length) > 1e-7)) {
                $validator->errors()->add($field("stations.$index.at"), 'Точки идут по порядку от нуля до полной длины заготовки.');
            }
            if ((float) $station['diameter'] > $maximumDiameter + 1e-7) {
                $validator->errors()->add($field("stations.$index.diameter"), 'Диаметр профиля больше поперечного размера заготовки.');
            }
            $previous = $at;
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  callable(string): string  $field
     */
    private function validateCurveRoute(Validator $validator, array $operation, float $u, float $v, float $normal, callable $field): void
    {
        $depth = (float) $operation['depth'];
        $angle = (float) $operation['cutter_angle'];
        $radius = (float) $operation['cutter_diameter'] / 2;
        $profile = $operation['cutter_profile'];
        $closed = (bool) $operation['closed_path'];
        if ($depth >= $normal) {
            $validator->errors()->add($field('depth'), 'Под траекторией должно оставаться дно.');
        }
        if (($profile === 'straight' && $angle !== 0.0)
            || ($profile === 'v_groove' && ($angle < 10 || $depth * tan(deg2rad($angle / 2)) > $radius + 1e-7))
            || ($profile === 'dovetail' && ($angle < 1 || $angle > 45 || $radius - $depth * tan(deg2rad($angle)) <= 0))) {
            $validator->errors()->add($field('cutter_angle'), 'Диаметр, угол и глубина не подходят для выбранного профиля фрезы.');
        }
        if ($profile === 'v_groove') {
            $radius = $depth * tan(deg2rad($angle / 2));
        }
        $points = $operation['points'];
        if (count($points) < ($closed ? 3 : 2) || count($points) > 32) {
            $validator->errors()->add($field('points'), 'Задайте от 2 до 32 точек. Для замкнутой траектории нужны минимум три точки.');

            return;
        }
        $vertices = [];
        foreach ($points as $index => $point) {
            if ($validator->errors()->hasAny([$field("points.$index"), $field("points.$index.u"), $field("points.$index.v")])) {
                return;
            }
            $x = (float) $point['u'];
            $y = (float) $point['v'];
            $previous = end($vertices);
            if ($x < $radius - 1e-7 || $x > $u - $radius + 1e-7 || $y < $radius - 1e-7 || $y > $v - $radius + 1e-7
                || ($previous !== false && hypot($x - $previous[0], $y - $previous[1]) < 0.01)) {
                $validator->errors()->add($field("points.$index"), 'Точки должны различаться, а фреза должна помещаться внутри грани.');
            }
            $vertices[] = [$x, $y];
        }
        if (! $this->isSimpleMachiningPath($vertices, $closed)) {
            $validator->errors()->add($field('points'), 'Траектория не должна пересекать себя.');
        }
    }
}
