<template>
    <section class="mt-7" aria-labelledby="fastener-diagnostics-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 id="fastener-diagnostics-title" class="text-base font-semibold text-stone-800">Проверка крепежа</h3>
            <span v-if="!loading && !report.pending && !error && report.total" class="status-pill">Проблемы · {{ report.issueCount }}</span>
        </div>
        <p v-if="loading" class="mt-2 text-sm text-stone-500" role="status">Проверяем крепёж…</p>
        <p v-else-if="error" class="mt-2 text-sm text-red-700" role="alert">{{ error }}</p>
        <p v-else-if="report.pending" class="mt-2 text-sm text-stone-500" role="status">Проверяем геометрию после обработки…</p>
        <p v-else-if="!report.total" class="mt-2 text-sm text-stone-500">В сборке пока нет крепежа.</p>
        <template v-else>
            <div v-if="report.issues.length" class="mt-3 grid gap-2 md:grid-cols-2">
                <button
                    v-for="issue in report.issues" :key="issue.key" type="button"
                    class="grid gap-1 rounded-xl border p-3 text-left transition hover:shadow-sm focus-visible:ring-2 focus-visible:ring-offset-2"
                    :class="issue.status === 'through' ? 'border-amber-200 bg-amber-50 text-amber-950 hover:border-amber-400 focus-visible:ring-amber-500' : 'border-red-200 bg-red-50 text-red-950 hover:border-red-400 focus-visible:ring-red-500'"
                    @click="$emit('inspect', issue)"
                >
                    <strong class="text-xs">{{ titles[issue.status] }} · {{ issue.context.fastener_ids.length }} шт.</strong>
                    <span class="text-xs">{{ location(issue) }}</span>
                    <span class="text-xs opacity-80">{{ detail(issue) }}</span>
                    <span class="mt-1 text-xs font-semibold">Показать на сцене →</span>
                </button>
            </div>
            <p v-else-if="report.total > report.unchecked" class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">Проблем с проверенным крепежом нет.</p>
            <p class="mt-2 text-xs text-stone-600">Проверено {{ report.total - report.unchecked }} из {{ report.total }}.</p>
            <p v-if="report.approximate" class="mt-1 text-xs text-amber-800">Часть деталей проверена по габаритам. Чтобы повторить расчёт геометрии, нажмите «Пересчитать».</p>
            <p v-if="report.unchecked" class="mt-1 text-xs text-stone-600">Без связанной второй детали · {{ report.unchecked }} шт.</p>
        </template>
    </section>
</template>

<script setup>
import { fastenerTypeLabels } from '../editor/materialCatalog.js';
import { faceLabel } from '../editor/operations/shared.js';

defineProps({
    report: { type: Object, required: true },
    loading: Boolean,
    error: { type: String, default: '' },
});
defineEmits(['inspect']);

const titles = {
    outside: 'За пределами грани',
    unsupported: 'Нет материала в первой детали',
    misses: 'Крепёж не входит в материал второй детали',
    short: 'Не достаёт до второй детали',
    through: 'Остриё выходит насквозь',
};
const millimeters = new Intl.NumberFormat('ru', { maximumFractionDigits: 1 });
const location = issue => [issue.group, issue.sourceName, faceLabel(issue.face)].filter(Boolean).join(' · ');
const detail = (issue) => {
    const prefix = [fastenerTypeLabels[issue.type], issue.targetName].filter(Boolean).join(' · ');
    if (issue.minimum === null) return prefix;

    const minimum = millimeters.format(issue.minimum);
    const maximum = millimeters.format(issue.maximum);
    if (issue.status === 'short' && issue.maximum < 0.05) return `${prefix} · остриё у поверхности`;
    const distance = maximum === '0' ? '< 0,1' : minimum === maximum ? minimum : `${minimum}–${maximum}`;
    return `${prefix} · ${issue.status === 'short' ? 'не хватает' : 'выступает на'} ${distance} мм`;
};
</script>
