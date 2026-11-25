<template>
    <svg v-if="layout" viewBox="0 0 640 250" role="img" :aria-label="label" class="block h-auto w-full rounded border border-stone-200">
        <polygon v-for="(face, index) in layout.faces" :key="index" :points="face.points" :fill="face.color" stroke="#65503c" stroke-width=".65"/>
        <g v-for="mark in layout.marks" :key="mark.code" :transform="`translate(${mark.x} ${mark.y})`">
            <rect :x="-mark.width / 2" y="-8" :width="mark.width" height="16" rx="3" fill="#fff" stroke="#60564b"/>
            <text text-anchor="middle" y="4" fill="#18352a" font-size="10" font-weight="700">{{ mark.code }}</text>
        </g>
    </svg>
</template>

<script setup>
import { computed } from 'vue';
import { projectPreviewFaces } from '../editor/projectPreview.js';
import { partWorkshopCode } from '../editor/workshopPlan.js';

const props = defineProps({
    parts: { type: Array, required: true },
    groups: { type: Array, required: true },
    label: { type: String, required: true },
    highlightIds: { type: Array, default: null },
    camera: { type: Object, default: null },
});
const layout = computed(() => {
    const faces = projectPreviewFaces({
        parts: props.parts,
        assembly_groups: props.groups.map(group => ({ ...group, is_visible: true })),
    }, props.camera);
    if (!faces.length) return null;

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const { points } of faces) {
        for (const [x, y] of points) {
            minX = Math.min(minX, x);
            maxX = Math.max(maxX, x);
            minY = Math.min(minY, y);
            maxY = Math.max(maxY, y);
        }
    }
    const scale = Math.min(540 / Math.max(1, maxX - minX), 198 / Math.max(1, maxY - minY));
    const project = ([x, y]) => [320 + (x - (minX + maxX) / 2) * scale, 125 + (y - (minY + maxY) / 2) * scale];
    const marks = [];
    const labeled = new Set();
    for (const { partId, points } of faces.toReversed()) {
        if (labeled.has(partId)) continue;
        const centroid = points.reduce(([x, y], point) => [x + point[0] / points.length, y + point[1] / points.length], [0, 0]);
        const [x, y] = project(centroid);
        const code = partWorkshopCode(partId);
        const width = code.length * 6 + 8;
        if (marks.some(mark => Math.abs(mark.x - x) < (mark.width + width) / 2 + 5 && Math.abs(mark.y - y) < 19)) continue;
        marks.push({ code, x, y, width });
        labeled.add(partId);
    }
    const highlights = new Set(props.highlightIds ?? []);
    return {
        faces: faces.map(face => ({
            color: highlights.has(face.instanceId) ? '#8ac29d' : face.color,
            points: face.points.map(project).map(point => point.join(',')).join(' '),
        })),
        marks,
    };
});
</script>
