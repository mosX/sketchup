<template>
    <article class="workshop-print hidden text-stone-950">
        <header class="mb-5 border-b border-stone-400 pb-3">
            <h1 class="text-xl font-bold">{{ projectName }}</h1>
            <p class="text-sm">Инструкция по сборке</p>
            <p class="text-xs text-stone-600">Схемы показывают габариты заготовок. Точные пропилы и отверстия смотрите в 3D-модели.</p>
        </header>
        <section v-for="(step, index) in steps" :key="index" class="mb-5 grid gap-2 border-b border-stone-300 pb-4">
            <div class="workshop-keep grid gap-1">
                <h2 class="text-base font-bold">{{ index + 1 }}. {{ step.title }}</h2>
                <WorkshopAssemblyDiagram :parts="partsAtStep(step)" :groups="groups" :label="step.title" :highlight-ids="step.highlighted" :camera="step.camera" />
            </div>
            <p v-if="step.instruction" class="whitespace-pre-wrap break-words text-sm">{{ step.instruction }}</p>
            <p v-if="step.notes" class="whitespace-pre-wrap break-words rounded bg-amber-50 p-2 text-sm">{{ step.notes }}</p>
            <ul v-if="step.parts.length || step.hardware.length" class="grid gap-0.5 text-xs">
                <li v-for="part in step.parts" :key="part.id">{{ part.code }} · {{ part.name }} · {{ part.size }} мм · {{ part.ids.length }} шт.</li>
                <li v-for="item in step.hardware" :key="item.label">{{ item.label }} · {{ item.count }} шт.</li>
            </ul>
        </section>
    </article>
</template>

<script setup>
import WorkshopAssemblyDiagram from './WorkshopAssemblyDiagram.vue';

const props = defineProps({ projectName: String, parts: Array, groups: Array, steps: Array });
const partsAtStep = (step) => {
    const visible = new Set(step.visibleIds);
    return props.parts.flatMap(part => {
        const instances = part.instances?.filter(instance => visible.has(instance.id)).map(instance => ({ ...instance, is_visible: true }));
        return instances?.length ? [{ ...part, instances }] : [];
    });
};
</script>
