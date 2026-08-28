<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

trait ValidatesPartOperations
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function operationRules(string $attribute = 'operations'): array
    {
        return [
            $attribute => ['sometimes', 'array', 'max:50'],
            "{$attribute}.*.id" => ['sometimes', 'string', 'max:100'],
            "{$attribute}.*.type" => ['required', 'string', 'in:cross_cut,rip_cut,groove'],
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
            "{$attribute}.*.face" => ['sometimes', 'string', 'in:top,bottom'],
            "{$attribute}.*.direction" => ['sometimes', 'string', 'in:length,width'],
            "{$attribute}.*.offset" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.start" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.end" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$attribute}.*.width" => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            "{$attribute}.*.depth" => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            "{$attribute}.*.blade_diameter" => ['sometimes', 'numeric', 'min:1', 'max:2000'],
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
        }
    }
}
