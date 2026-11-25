<template>
    <TurningProfileFields v-if="operation.type === 'turning'" :operation="operation" :dimensions="dimensions" @update="$emit('update', $event)" />
    <div v-else class="grid gap-2">
        <label v-if="operation.face" class="editor-field">Грань
            <select :value="operation.face" @change="changeFace($event.target.value)">
                <option v-for="(_, face) in faceAxes" :key="face" :value="face">{{ faceLabel(face) }}</option>
            </select>
        </label>
        <label v-if="operation.type === 'taper'" class="editor-field">Обрабатываемые стороны
            <select :value="operation.taper_sides" @change="update({ taper_sides: $event.target.value })">
                <option value="two">Две по ширине</option>
                <option value="four">Четыре по ширине и толщине</option>
            </select>
        </label>
        <template v-if="operation.type === 'curve_route'">
            <label class="editor-field">Профиль фрезы
                <select :value="operation.cutter_profile" @change="setCutter($event.target.value)">
                    <option value="straight">Прямая</option>
                    <option value="v_groove">V-образная</option>
                    <option value="dovetail">Ласточкин хвост</option>
                </select>
            </label>
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs">
                <label class="flex items-center gap-1.5"><input type="checkbox" :checked="operation.closed_path" @change="update({ closed_path: $event.target.checked })">Замкнутая</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" :checked="operation.smooth_path" @change="update({ smooth_path: $event.target.checked })">Сгладить повороты</label>
            </div>
        </template>
        <div class="grid grid-cols-2 gap-2">
            <label v-for="{ key, label, min, max, step, unit, disabled } in fields" :key="key" class="editor-field">
                {{ label }}{{ unit ? `, ${unit}` : '' }}
                <input v-scrub-number type="number" :value="operation[key]" :min="min" :max="max" :step="step" :disabled="disabled"
                    @change="setNumber(key, $event.target.value)" @scrub-value="setNumber(key, $event.target.value)">
            </label>
        </div>
        <div v-if="operation.type === 'drill_row'" class="flex flex-wrap items-center gap-2">
            <label class="flex items-center gap-2 text-xs"><input type="checkbox" :checked="operation.through" @change="update({ through: $event.target.checked })">Насквозь</label>
            <button class="button-secondary button-compact" type="button" @click="$emit('copy-row')">Копировать ряд</button>
        </div>
        <template v-if="operation.type === 'curve_route'">
            <div class="flex flex-wrap gap-1.5">
                <button class="button-secondary button-compact" type="button" @click="preset(false)">Открытая дуга</button>
                <button class="button-secondary button-compact" type="button" @click="preset(true)">Кольцевая канавка</button>
                <button v-if="!drawing" class="button-secondary button-compact" type="button" @click="startPath">Нарисовать путь</button>
                <button v-else class="button-primary button-compact" type="button" :disabled="!valid" @click="$emit('draw', false)">Готово</button>
            </div>
            <p v-if="drawing" class="text-xs text-stone-600">Ставьте точки на выбранной грани. После создания их можно перетаскивать.</p>
            <p v-if="!valid" class="text-xs text-amber-800">Проверьте число точек, отступ от краёв и ширину фрезы. Траектория не должна пересекать себя.</p>
            <details v-if="operation.points.length" class="text-xs">
                <summary class="cursor-pointer text-stone-700">Точки прохода · {{ operation.points.length }}</summary>
                <div class="mt-2 grid max-h-56 gap-1 overflow-auto">
                    <div v-for="(point, index) in operation.points" :key="index" class="grid grid-cols-[1.5rem_minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-1">
                        <span class="tabular-nums">{{ index + 1 }}</span>
                        <input v-scrub-number type="number" :aria-label="`U точки ${index + 1}, мм`" :value="point.u" min="0" :max="size.u" step="0.1" @change="setPoint(index, 'u', $event.target.value)">
                        <input v-scrub-number type="number" :aria-label="`V точки ${index + 1}, мм`" :value="point.v" min="0" :max="size.v" step="0.1" @change="setPoint(index, 'v', $event.target.value)">
                        <button class="icon-button" type="button" :aria-label="`Удалить точку ${index + 1}`" @click="update({ points: operation.points.filter((_, candidate) => index !== candidate) })">×</button>
                    </div>
                </div>
            </details>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import TurningProfileFields from './TurningProfileFields.vue';
import { fitAdvanced, routePresets, validRoute } from '../editor/operations/advancedMachining.js';
import { faceAxes, roundContourPoint } from '../editor/operations/shaping.js';
import { faceLabel, surfaceDimensions } from '../editor/operations/shared.js';
import { vScrubNumber } from '../editor/scrubNumber.js';

const props = defineProps({
    operation: { type: Object, required: true },
    dimensions: { type: Object, required: true },
    drawing: Boolean,
});
const emit = defineEmits(['update', 'draw', 'copy-row']);
const size = computed(() => surfaceDimensions(props.operation.face, props.dimensions));
const valid = computed(() => validRoute(props.operation, props.dimensions));
const fields = computed(() => {
    const operation = props.operation;
    const { u, v, depth } = size.value;
    const { width, thickness } = props.dimensions;
    const mm = (key, label, max, min = 0.1, disabled = false) => ({ key, label, min, max, unit: 'мм', step: 0.1, disabled });
    const angle = (key, label, min = -180, max = 180) => ({ key, label, min, max, unit: '°', step: 1 });
    if (operation.type === 'taper') return [
        mm('start_width', 'Ширина начала', width), mm('end_width', 'Ширина конца', width),
        ...(operation.taper_sides === 'four' ? [mm('start_height', 'Толщина начала', thickness), mm('end_height', 'Толщина конца', thickness)] : []),
    ];
    if (operation.type === 'curve_route') return [
        mm('cutter_diameter', 'Диаметр фрезы', Math.min(u, v)), mm('depth', 'Глубина', depth - 0.01),
        ...(operation.cutter_profile === 'straight' ? [] : [angle('cutter_angle', operation.cutter_profile === 'dovetail' ? 'Угол стенки' : 'Угол раскрытия', operation.cutter_profile === 'dovetail' ? 1 : 10, operation.cutter_profile === 'dovetail' ? 45 : 170)]),
    ];
    const center = [mm('center_u', 'Старт U', u, 0), mm('center_v', 'Старт V', v, 0), angle('path_angle', 'Направление')];
    if (operation.type === 'drill_row') return [
        ...center, { key: 'hole_count', label: 'Количество', min: 1, max: 40, step: 1 },
        mm('hole_spacing', 'Шаг', Math.max(u, v)), mm('diameter', 'Диаметр', Math.min(u, v)),
        mm('depth', 'Глубина', depth, 0.1, operation.through),
    ];
    return [
        ...center, angle('entry_angle', 'Угол к грани', 10, 75),
        mm('diameter', 'Диаметр ступени', Math.min(u, v)), mm('depth', 'Длина ступени', Math.hypot(u, v, depth)),
        mm('pilot_diameter', 'Диаметр пилота', operation.diameter), mm('pilot_length', 'Длина пилота', Math.max(u, v, depth) * 2),
    ];
});
const update = changes => emit('update', fitAdvanced({ ...props.operation, ...changes }, props.dimensions));
const setNumber = (key, value) => {
    if (value === '' || !Number.isFinite(Number(value))) return;
    const field = fields.value.find(item => item.key === key);
    update({ [key]: Math.min(field.max, Math.max(field.min, Number(value))) });
};
const changeFace = face => {
    const next = surfaceDimensions(face, props.dimensions);
    if (props.operation.type === 'curve_route') {
        update({ face, points: props.operation.points.map(({ u, v }) => roundContourPoint({ u: u * next.u / size.value.u, v: v * next.v / size.value.v })) });
        return;
    }
    update(props.operation.type === 'drill_row' ? { face } : { face, center_u: next.u / 2, center_v: next.v / 2 });
};
const setCutter = cutter_profile => update({ cutter_profile, cutter_angle: { straight: 0, v_groove: 60, dovetail: 14 }[cutter_profile] });
const preset = closed_path => {
    update({ closed_path, points: routePresets(props.operation.face, props.dimensions, closed_path) });
    emit('draw', false);
};
const startPath = () => {
    update({ points: [] });
    emit('draw', true);
};
const setPoint = (index, axis, value) => {
    if (value === '' || !Number.isFinite(Number(value))) return;
    update({ points: props.operation.points.map((point, candidate) => candidate === index
        ? { ...point, [axis]: Math.min(size.value[axis], Math.max(0, Number(value))) } : point) });
};
</script>
