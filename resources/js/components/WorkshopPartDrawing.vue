<template>
    <div class="workshop-keep grid gap-2">
        <p v-if="drawing.finished" class="text-xs text-stone-700">Габариты после обработки · {{ drawing.finished.map(number.format).join(' × ') }} мм</p>
        <p v-else-if="drawing.status === 'empty'" class="text-xs font-semibold text-amber-900">После обработки материал отсутствует.</p>
        <p v-else class="text-xs font-semibold text-amber-900">Контур после обработки не рассчитан. Показана разметка на исходной заготовке.</p>
        <div class="grid grid-cols-2 gap-2">
            <figure v-for="view in layouts" :key="view.face" class="min-w-0 rounded border border-stone-200 px-1.5 py-1">
                <figcaption class="text-xs font-semibold">{{ faceTitles[view.face] }}</figcaption>
                <svg viewBox="0 0 360 148" class="block h-auto w-full" role="img" :aria-label="faceTitles[view.face]">
                    <rect :x="view.left" :y="view.top" :width="view.widthPx" :height="view.heightPx" fill="none" stroke="#8b8b85" stroke-width=".8" stroke-dasharray="3 2"/>
                    <path :d="view.outline" fill="none" stroke="#242521" stroke-width="1.1" stroke-linecap="round"/>
                    <g fill="none" stroke="#55574f" stroke-width=".7">
                        <path :d="`M${view.left},${view.top - 3} V${view.top - 15} M${view.right},${view.top - 3} V${view.top - 15} M${view.left},${view.top - 10} H${view.right}`"/>
                        <path :d="`M${view.left - 3},${view.top} H${view.left - 17} M${view.left - 3},${view.bottom} H${view.left - 17} M${view.left - 12},${view.top} V${view.bottom}`"/>
                    </g>
                    <g fill="#34362e" font-size="10" text-anchor="middle">
                        <text :x="(view.left + view.right) / 2" :y="view.top - 15">U {{ number.format(view.width) }}</text>
                        <text :transform="`translate(${view.left - 17} ${(view.top + view.bottom) / 2}) rotate(-90)`">V {{ number.format(view.height) }}</text>
                    </g>
                    <circle :cx="view.left" :cy="view.bottom" r="2" fill="#333"/>
                    <text :x="view.left" :y="view.bottom + 12" font-size="9" fill="#55574f">0</text>
                    <g v-for="mark in view.marks" :key="mark.number">
                        <path v-if="mark.line" :d="mark.line" fill="none" stroke="#176855" stroke-width=".8" stroke-dasharray="4 2"/>
                        <circle v-for="([x, y], index) in mark.locations" :key="index" :cx="x" :cy="y" r="2" fill="none" stroke="#176855" stroke-width=".8"/>
                        <line :x1="mark.point[0]" :y1="mark.point[1]" :x2="mark.badge[0]" :y2="mark.badge[1]" stroke="#176855" stroke-width=".7"/>
                        <circle :cx="mark.point[0]" :cy="mark.point[1]" r="1.8" fill="#176855"/>
                        <circle :cx="mark.badge[0]" :cy="mark.badge[1]" r="7" fill="white" stroke="#176855" stroke-width=".9"/>
                        <text :x="mark.badge[0]" :y="mark.badge[1] + 3" fill="#185749" font-size="8.5" font-weight="700" text-anchor="middle">{{ mark.number }}</text>
                    </g>
                </svg>
            </figure>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ drawing: { type: Object, required: true } });
const number = new Intl.NumberFormat('ru', { maximumFractionDigits: 2 });
const faceTitles = {
    top: 'Верхняя пласть', bottom: 'Нижняя пласть', left: 'Левая кромка',
    right: 'Правая кромка', start: 'Начальный торец', end: 'Конечный торец',
};
const offsets = [[14, -14], [14, 14], [-14, -14], [-14, 14], [26, -14], [26, 14], [-26, -14], [-26, 14]];
const path = points => points.map(([u, v], index) => `${index === 0 ? 'M' : 'L'}${u.toFixed(2)},${v.toFixed(2)}`).join(' ');
const layouts = computed(() => props.drawing.views.map(view => {
    const scale = Math.min(290 / view.width, 94 / view.height);
    const widthPx = view.width * scale;
    const heightPx = view.height * scale;
    const left = 198 - widthPx / 2;
    const top = 76 - heightPx / 2;
    const bottom = top + heightPx;
    const project = ([u, v]) => [left + u * scale, bottom - v * scale];
    const taken = [];
    const marks = view.marks.filter(mark => mark.at.every(Number.isFinite)).map(mark => {
        const point = project([
            Math.max(0, Math.min(view.width, mark.at[0])),
            Math.max(0, Math.min(view.height, mark.at[1])),
        ]);
        const candidates = offsets.map(([dx, dy]) => [point[0] + dx, point[1] + dy]);
        const badge = candidates.find(([x, y]) => x > 14 && x < 350 && y > 12 && y < 136
            && !taken.some(([a, b]) => Math.hypot(a - x, b - y) < 15)) ?? candidates[0];
        badge[0] = Math.min(350, Math.max(10, badge[0]));
        badge[1] = Math.min(137, Math.max(10, badge[1]));
        taken.push(badge);
        return { number: mark.number, point, badge, line: mark.line ? path(mark.line.map(project)) : null, locations: mark.locations?.map(project) };
    });
    return {
        ...view, widthPx, heightPx, left, top, right: left + widthPx, bottom, marks,
        outline: view.lines.map(line => path(line.map(project))).join(' '),
    };
}));
</script>
