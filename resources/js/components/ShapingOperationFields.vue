<template>
    <div class="grid gap-2">
        <label v-if="edgeTool" class="editor-field">Кромка
            <select :value="operation.edge" @change="setEdge($event.target.value)">
                <option v-for="(label, edge) in roundoverEdgeLabels" :key="edge" :value="edge">{{ label }}</option>
            </select>
        </label>
        <label v-if="operation.type !== 'edge_chamfer'" class="editor-field">Грань
            <select :value="operation.face" @change="setFace($event.target.value)">
                <option v-for="face in faces" :key="face" :value="face">{{ faceLabel(face) }}</option>
            </select>
        </label>
        <label v-if="operation.type === 'pocket'" class="editor-field">Форма
            <select :value="operation.pocket_shape" @change="setShape($event.target.value)">
                <option value="rectangle">Прямоугольная</option>
                <option value="circle">Круглая</option>
            </select>
        </label>
        <div v-if="fields.length" class="grid grid-cols-2 gap-2">
            <label v-for="{ key, label, min, max } in fields" :key="key" class="editor-field">{{ label }}, мм
                <input v-scrub-number type="number" :value="operation[key]" :min="min" :max="max" step="0.1"
                    @change="setNumber(key, $event.target.value)" @scrub-value="setNumber(key, $event.target.value)">
            </label>
        </div>
        <p v-if="operation.type === 'edge_chamfer'" class="text-xs text-stone-600">Shift + клик по соседней кромке добавит такую же фаску.</p>
        <template v-if="operation.type === 'jigsaw_cut'">
            <div class="flex flex-wrap gap-1.5">
                <button class="button-secondary button-compact" type="button" @click="preset('oval')">Овал</button>
                <button class="button-secondary button-compact" type="button" @click="preset('notch')">Арка у края</button>
                <button v-if="!drawing" class="button-secondary button-compact" type="button" @click="startDrawing">Нарисовать контур</button>
                <button v-else class="button-primary button-compact" type="button" :disabled="!validContour" @click="$emit('draw', false)">Замкнуть контур</button>
            </div>
            <p v-if="drawing" class="text-xs text-stone-600">Ставьте точки кликами по плоскости выбранной грани. Для выреза у края можно ставить точки за пределами доски.</p>
            <p v-if="!validContour" class="text-xs text-amber-800">Нужно от 3 до 64 точек. Стороны контура не должны пересекаться.</p>
            <details v-if="operation.points.length" class="text-xs">
                <summary class="cursor-pointer text-stone-700">Точки контура · {{ operation.points.length }}</summary>
                <div class="mt-2 grid max-h-60 gap-1 overflow-auto">
                    <div v-for="(point, index) in operation.points" :key="index" class="grid grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-1">
                        <span class="w-5 tabular-nums">{{ index + 1 }}</span>
                        <input v-scrub-number type="number" :aria-label="`U точки ${index + 1}, мм`" :value="point.u" step="0.1" min="-100000" max="200000"
                            @change="setPoint(index, 'u', $event.target.value)" @scrub-value="setPoint(index, 'u', $event.target.value)">
                        <input v-scrub-number type="number" :aria-label="`V точки ${index + 1}, мм`" :value="point.v" step="0.1" min="-100000" max="200000"
                            @change="setPoint(index, 'v', $event.target.value)" @scrub-value="setPoint(index, 'v', $event.target.value)">
                        <button class="icon-button" type="button" :aria-label="`Удалить точку ${index + 1}`" @click="removePoint(index)">×</button>
                    </div>
                </div>
            </details>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { vScrubNumber } from '../editor/scrubNumber.js';
import { roundoverEdgeLabels } from '../editor/operations/roundover.js';
import { contourPresets, faceAxes, fitShaping, isSimpleContour, maximumChamferWidth, rabbetAxes, roundContourPoint } from '../editor/operations/shaping.js';
import { faceLabel, surfaceDimensions } from '../editor/operations/shared.js';

const props = defineProps({
    operation: { type: Object, required: true },
    dimensions: { type: Object, required: true },
    drawing: Boolean,
});
const emit = defineEmits(['update', 'draw']);
const edgeTool = computed(() => ['rabbet', 'edge_chamfer'].includes(props.operation.type));
const faces = computed(() => edgeTool.value ? props.operation.edge.split('_') : Object.keys(faceAxes));
const validContour = computed(() => isSimpleContour(props.operation.points));
const fields = computed(() => {
    const operation = props.operation;
    const dimensions = props.dimensions;
    const field = (key, label, max, min = 0.1) => ({ key, label, min, max });

    if (operation.type === 'edge_chamfer') return [field('width', 'Ширина полки', maximumChamferWidth(operation.edge, dimensions))];
    if (operation.type === 'rabbet') {
        const axes = rabbetAxes(operation.edge, operation.face);
        return [
            field('width', 'Ширина', dimensions[axes.width] - 0.1),
            field('depth', 'Глубина', dimensions[axes.depth] - 0.1),
            field('start', 'От края', dimensions[axes.length] - 0.1, 0),
            field('cut_length', 'Длина', dimensions[axes.length] - Number(operation.start)),
        ];
    }
    if (operation.type !== 'pocket') return [];
    const surface = surfaceDimensions(operation.face, dimensions);
    const round = operation.pocket_shape === 'circle';
    return [
        ...(round ? [field('diameter', 'Диаметр', Math.min(surface.u, surface.v) - 0.2)] : [
            field('pocket_length', 'Длина по U', surface.u - 0.2), field('width', 'Ширина по V', surface.v - 0.2),
            field('corner_radius', 'Радиус углов', Math.min(Number(operation.width), Number(operation.pocket_length)) / 2, 0),
        ]),
        field('depth', 'Глубина', surface.depth - 0.1),
        field('center_u', 'Центр U', surface.u, 0),
        field('center_v', 'Центр V', surface.v, 0),
    ];
});
const update = changes => emit('update', fitShaping({ ...props.operation, ...changes }, props.dimensions));
const setNumber = (key, value) => {
    if (value !== '' && Number.isFinite(Number(value))) update({ [key]: Number(value) });
};
const setEdge = edge => {
    if (props.operation.type === 'edge_chamfer') {
        update({ edge });
        return;
    }
    const adjacent = edge.split('_');
    update({ edge, face: adjacent.includes(props.operation.face) ? props.operation.face : adjacent[0] });
};
const setFace = face => {
    const previous = surfaceDimensions(props.operation.face, props.dimensions);
    const next = surfaceDimensions(face, props.dimensions);
    const points = props.operation.points?.map(({ u, v }) => roundContourPoint({ u: u * next.u / previous.u, v: v * next.v / previous.v }));
    update(points ? { face, points } : { face });
};
const setShape = pocket_shape => update({ pocket_shape });
const preset = shape => {
    update({ points: contourPresets(props.operation.face, props.dimensions, shape) });
    emit('draw', false);
};
const startDrawing = () => {
    update({ points: [] });
    emit('draw', true);
};
const setPoint = (index, axis, value) => {
    if (value === '' || !Number.isFinite(Number(value))) return;
    const coordinate = Math.max(-100000, Math.min(200000, Number(value)));
    update({ points: props.operation.points.map((point, i) => i === index ? { ...point, [axis]: coordinate } : point) });
};
const removePoint = index => update({ points: props.operation.points.filter((_, i) => i !== index) });
</script>
