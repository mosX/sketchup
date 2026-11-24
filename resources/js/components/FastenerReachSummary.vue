<template>
    <div v-if="groups.length || pending" class="grid gap-1 rounded-lg bg-stone-100 p-2 text-xs" role="status">
        <p class="font-semibold text-stone-700">{{ pending ? 'Проверяем обработанные детали…' : 'Проверка крепежа' }}</p>
        <p v-if="approximate && !pending" class="text-amber-800">Часть деталей проверена по габаритам.</p>
        <ul v-if="!pending" class="grid max-h-32 gap-1 overflow-auto">
            <li v-for="group in groups" :key="group.key" :class="statusClasses[group.status]">
                <span v-if="group.count > 1">{{ group.count }} шт. · </span>{{ group.label }}
            </li>
        </ul>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    placements: { type: Array, required: true },
    instances: { type: Array, required: true },
});
const millimeters = new Intl.NumberFormat('ru', { maximumFractionDigits: 1 });
const statusClasses = { reaches: 'text-stone-700', short: 'text-red-800', misses: 'text-red-800', through: 'text-amber-800', unsupported: 'text-red-800' };
const pending = computed(() => props.placements.some(item => item.reach?.pending));
const approximate = computed(() => props.placements.some(item => item.reach?.approximate));

const groups = computed(() => {
    const names = new Map(props.instances.map(item => [item.id, item.name]));
    const results = new Map();
    for (const { reach, target_instance_id } of props.placements) {
        if (!reach) continue;
        const { status, targetId, depth, shortage, protrusion } = reach;
        const name = names.get(targetId ?? target_instance_id);
        const distance = value => value < 0.05 ? '< 0,1 мм' : `${millimeters.format(value)} мм`;
        let label;
        if (status === 'unsupported') label = 'На месте крепежа в первой детали нет материала';
        else if (status === 'reaches') label = `Входит в древесину на ${distance(depth)}`;
        else if (status === 'through') label = `В древесине ${distance(depth)} · остриё выступает на ${distance(protrusion)}`;
        else if (status === 'short') label = shortage > 0 ? `Не достаёт ${distance(shortage)}` : 'Остриё у поверхности';
        else label = name ? 'Крепёж не входит в материал второй детали' : 'На линии крепежа нет второй детали';

        const text = name && status !== 'unsupported' ? `${name} · ${label}` : label;
        const key = `${targetId ?? target_instance_id}:${label}`;
        if (!results.has(key)) results.set(key, { key, label: text, status, count: 0 });
        results.get(key).count++;
    }
    return [...results.values()];
});
</script>
