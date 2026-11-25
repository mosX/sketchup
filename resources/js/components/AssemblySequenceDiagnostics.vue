<template>
    <details v-if="report?.step_count" class="rounded-lg border border-stone-200 p-2">
        <summary class="cursor-pointer text-xs font-semibold" :class="report.issue_count ? 'text-amber-900' : 'text-emerald-800'">
            {{ report.issue_count ? `Проверка порядка · ${report.issue_count} замеч.` : 'Порядок сборки проверен' }}
        </summary>
        <p v-if="!report.issue_count" class="mt-2 text-xs text-stone-600">Детали и связанный крепёж распределены по шагам.</p>
        <div v-else class="mt-2 grid max-h-56 gap-1.5 overflow-y-auto">
            <button v-for="issue in report.issues" :key="issue.key" class="grid gap-1 rounded-md border border-amber-200 bg-amber-50 p-2 text-left text-xs hover:border-amber-500"
                type="button" @click="$emit('inspect', issue)">
                <strong class="text-amber-950">{{ titles[issue.code] }}</strong>
                <span class="text-stone-700">{{ description(issue) }}</span>
            </button>
        </div>
    </details>
</template>

<script setup>
defineProps({ report: Object });
defineEmits(['inspect']);

const titles = {
    fastener_before_parts: 'Крепёж указан раньше деталей',
    missing_fastener: 'Связанный крепёж пропущен',
    missing_instance: 'Деталь пропущена',
    deleted_instance: 'Ссылка на удалённую деталь',
    deleted_fastener: 'Ссылка на удалённый крепёж',
};
const description = ({ step_index: step, context }) => {
    const pieces = [];
    if (step !== null) pieces.push(`Шаг ${step + 1}`);
    if (context.instance_names.length) pieces.push(context.instance_names.join(' и '));
    if (context.fastener_ids.length) pieces.push(`Крепёж №${context.fastener_ids.join(', ')}`);
    else if (context.reference_id !== undefined) pieces.push(`№${context.reference_id}`);
    if (context.earliest_step_index != null) pieces.push(`Можно с шага ${context.earliest_step_index + 1}`);
    return pieces.join(' · ');
};
</script>
