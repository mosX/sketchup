<template>
    <div class="grid gap-2">
        <svg ref="chart" viewBox="0 0 280 110" class="w-full touch-none rounded-lg border border-stone-200 bg-white"
            aria-label="Токарный профиль. Точки можно перетаскивать"
            @pointermove="movePoint" @pointerup="active = null" @pointercancel="active = null" @lostpointercapture="active = null">
            <path d="M15 55 H265" stroke="#a8a29e" stroke-dasharray="4 3" />
            <polygon :points="silhouette" fill="#dceee7" stroke="#287158" stroke-width="1.4" />
            <circle v-for="(point, index) in top" :key="index" :cx="point[0]" :cy="point[1]" r="5"
                class="cursor-grab fill-amber-300 stroke-emerald-900" @pointerdown.prevent="beginPoint($event, index)" />
        </svg>
        <div class="flex flex-wrap gap-1.5">
            <button class="button-secondary button-compact" type="button" :disabled="operation.stations.length >= 24" @click="addPoint">Добавить точку</button>
            <button class="button-secondary button-compact" type="button" @click="$emit('update', { stations: turningPresets(dimensions, false) })">Цилиндр</button>
            <button class="button-secondary button-compact" type="button" @click="$emit('update', { stations: turningPresets(dimensions) })">Фигурный профиль</button>
        </div>
        <label class="flex items-center gap-2 text-xs"><input type="checkbox" :checked="operation.smooth_profile" @change="$emit('update', { smooth_profile: $event.target.checked })"> Плавные переходы</label>
        <details class="text-xs">
            <summary class="cursor-pointer text-stone-700">Размеры профиля · {{ operation.stations.length }} точек</summary>
            <div class="mt-2 grid max-h-56 gap-1 overflow-auto">
                <div v-for="(station, index) in operation.stations" :key="index" class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-1">
                    <label class="editor-field">От торца, мм
                        <input v-scrub-number type="number" step="0.1" min="0" :max="dimensions.length" :value="station.at" :disabled="atEnd(index)" @change="setPoint(index, 'at', $event.target.value)">
                    </label>
                    <label class="editor-field">Диаметр, мм
                        <input v-scrub-number type="number" step="0.1" min="0.1" :max="diameterLimit" :value="station.diameter" @change="setPoint(index, 'diameter', $event.target.value)">
                    </label>
                    <button class="icon-button" type="button" :disabled="atEnd(index)" :aria-label="`Удалить точку ${index + 1}`" @click="removePoint(index)">×</button>
                </div>
            </div>
        </details>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { turningPresets } from '../editor/operations/advancedMachining.js';
import { turningProfile } from '../editor/geometry/advancedCutters.js';
import { millimeterScale } from '../editor/geometry/coordinates.js';
import { vScrubNumber } from '../editor/scrubNumber.js';

const props = defineProps({
    operation: { type: Object, required: true },
    dimensions: { type: Object, required: true },
});
const emit = defineEmits(['update']);
const chart = ref();
const active = ref(null);
const length = computed(() => Number(props.dimensions.length));
const diameterLimit = computed(() => Math.min(props.dimensions.width, props.dimensions.thickness));
const along = at => 15 + 250 * at / length.value;
const height = diameter => 55 - 42 * diameter / diameterLimit.value;
const top = computed(() => props.operation.stations.map(({ at, diameter }) => [along(at), height(diameter)]));
const silhouette = computed(() => {
    const profile = turningProfile({ dimensions: props.dimensions }, props.operation).map(point =>
        [along(point.y / millimeterScale + length.value / 2), height(point.x * 2 / millimeterScale)]);
    return [...profile, ...profile.slice().reverse().map(([x, y]) => [x, 110 - y])].map(point => point.join(',')).join(' ');
});
const atEnd = index => index === 0 || index === props.operation.stations.length - 1;
const bounded = (value, min, max) => Math.min(Math.max(Math.round(value * 10000) / 10000, min), max);
const updatePoint = (index, at, diameter) => {
    const stations = props.operation.stations.map(station => ({ ...station }));
    if (!atEnd(index)) stations[index].at = bounded(at, Number(stations[index - 1].at) + 0.01, Number(stations[index + 1].at) - 0.01);
    stations[index].diameter = bounded(diameter, 0.1, diameterLimit.value);
    emit('update', { stations });
};
const setPoint = (index, key, input) => {
    if (input === '' || !Number.isFinite(Number(input))) return;
    const point = { ...props.operation.stations[index], [key]: Number(input) };
    updatePoint(index, point.at, point.diameter);
};
const beginPoint = (event, index) => {
    if (event.button !== 0) return;
    active.value = index;
    chart.value.setPointerCapture(event.pointerId);
};
const movePoint = event => {
    if (active.value === null) return;
    const point = chart.value.createSVGPoint();
    point.x = event.clientX;
    point.y = event.clientY;
    const local = point.matrixTransform(chart.value.getScreenCTM().inverse());
    updatePoint(active.value, (local.x - 15) * length.value / 250, (55 - local.y) * diameterLimit.value / 42);
};
const addPoint = () => {
    const stations = props.operation.stations.slice();
    let widest = 1;
    for (let index = 2; index < stations.length; index++) {
        if (stations[index].at - stations[index - 1].at > stations[widest].at - stations[widest - 1].at) widest = index;
    }
    const [before, after] = stations.slice(widest - 1, widest + 1);
    if (after.at - before.at < 0.02) return;
    stations.splice(widest, 0, { at: (Number(before.at) + Number(after.at)) / 2, diameter: (Number(before.diameter) + Number(after.diameter)) / 2 });
    emit('update', { stations });
};
const removePoint = index => emit('update', { stations: props.operation.stations.filter((_, candidate) => index !== candidate) });
</script>
