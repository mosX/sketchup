<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

trait ValidatesPartOperations
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function operationRules(): array
    {
        return [
            'operations' => ['sometimes', 'array', 'max:50'],
            'operations.*.id' => ['sometimes', 'string', 'max:100'],
            'operations.*.type' => ['required', 'string', 'in:cross_cut,rip_cut,groove'],
            'operations.*.enabled' => ['sometimes', 'boolean'],
            'operations.*.position' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'operations.*.miter_angle' => ['sometimes', 'numeric', 'between:-60,60'],
            'operations.*.bevel_angle' => ['sometimes', 'numeric', 'between:-45,45'],
            'operations.*.kerf' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'operations.*.keep_side' => ['sometimes', 'string', 'in:start,end,reference,opposite'],
            'operations.*.reference_side' => ['sometimes', 'string', 'in:left,right'],
            'operations.*.start_offset' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'operations.*.end_offset' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'operations.*.face' => ['sometimes', 'string', 'in:top,bottom'],
            'operations.*.direction' => ['sometimes', 'string', 'in:length,width'],
            'operations.*.offset' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'operations.*.start' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'operations.*.end' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'operations.*.width' => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            'operations.*.depth' => ['sometimes', 'numeric', 'min:0.1', 'max:10000'],
            'operations.*.blade_diameter' => ['sometimes', 'numeric', 'min:1', 'max:2000'],
        ];
    }

    /**
     * @return array<callable(Validator): void>
     */
    protected function operationValidation(float $length, float $width, float $thickness): array
    {
        return [function (Validator $validator) use ($length, $width, $thickness): void {
            foreach ($this->input('operations', []) as $index => $operation) {
                if (! is_array($operation) || ! isset($operation['type'])) {
                    continue;
                }

                $field = fn (string $name): string => "operations.{$index}.{$name}";
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
        }];
    }
}
