<template>
    <div ref="preview" class="absolute inset-0" aria-hidden="true">
        <div class="preview-grid"></div>
        <canvas v-show="status === 'ready'" ref="canvas" width="720" height="320" class="absolute inset-0 size-full object-contain"></canvas>
        <div v-if="status !== 'ready'" class="absolute inset-0 grid place-items-center text-xs font-medium text-stone-600">
            <span v-if="status === 'empty'">В сборке нет видимых деталей</span>
            <span v-else-if="status === 'error'">Превью недоступно</span>
            <span v-else class="size-5 animate-pulse rounded-full bg-stone-500/20"></span>
        </div>
    </div>
</template>

<script setup>
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { drawProjectPreview } from '../editor/projectPreview.js';

const props = defineProps({
    projectId: { type: Number, required: true },
    revision: { type: Number, required: true },
});
const preview = ref(null);
const canvas = ref(null);
const inView = ref(false);
const status = ref('loading');
let observer;

watch(() => [inView.value, props.projectId, props.revision], async ([visible, id], previous, onCleanup) => {
    if (!visible) return;

    const controller = new AbortController();
    onCleanup(() => controller.abort());
    status.value = 'loading';

    try {
        const { data } = await axios.get(`/v1/projects/${id}`, { signal: controller.signal });
        if (!controller.signal.aborted) {
            status.value = drawProjectPreview(canvas.value, data.data) ? 'ready' : 'empty';
        }
    } catch {
        if (!controller.signal.aborted) status.value = 'error';
    }
});

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') {
        inView.value = true;
        return;
    }

    observer = new IntersectionObserver(([entry]) => {
        if (!entry.isIntersecting) return;
        inView.value = true;
        observer.disconnect();
    }, { rootMargin: '150px' });
    observer.observe(preview.value);
});

onBeforeUnmount(() => observer?.disconnect());
</script>
