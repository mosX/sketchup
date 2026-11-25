<template>
    <section class="mt-7 grid gap-3" aria-labelledby="assembly-geometry-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 id="assembly-geometry-title" class="text-base font-semibold text-stone-800">Геометрия после обработки</h3>
            <button class="button-secondary button-compact" type="button" :disabled="loading" @click="$emit('check')">
                {{ loading ? 'Проверяем…' : report ? 'Проверить повторно' : 'Проверить геометрию' }}
            </button>
        </div>
        <p v-if="loading" class="text-sm text-stone-600" role="status">Рассчитываем пересечения деталей и положение крепежа после пропилов, пазов и сверления…</p>
        <p v-else-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
        <p v-else-if="!report" class="text-sm text-stone-600">Проверка учитывает сохранённую обработку и скрытые детали сборки.</p>
        <template v-else>
            <p v-if="report.status === 'partial'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                Проверка неполная. {{ report.time_limit_reached ? 'Расчёт достиг ограничения по времени.' : '' }}
                {{ report.failed_pairs ? `Не удалось проверить пар деталей: ${report.failed_pairs}.` : '' }}
            </p>
            <p v-if="!report.instance_count" class="text-sm text-stone-600">Добавьте детали в сборку, чтобы проверить пересечения.</p>
            <p v-else-if="!report.issue_count && report.status === 'complete'" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">Пересечений материала не найдено.</p>
            <p v-if="report.issue_count" class="text-sm text-stone-700">
                Пересечения · {{ report.issue_count }}
                <span v-if="report.issues_truncated"> · показано {{ report.issues.length }}</span>
            </p>
            <div v-if="report.issues.length" class="grid max-h-96 gap-2 overflow-y-auto md:grid-cols-2">
                <button v-for="issue in report.issues" :key="issue.context.instance_ids.join('-')" type="button"
                    class="grid gap-1 rounded-xl border border-red-200 bg-red-50 p-3 text-left text-red-950 hover:border-red-400 focus-visible:ring-2 focus-visible:ring-red-500"
                    @click="$emit('inspect', issue)"
                >
                    <strong class="text-xs">{{ issue.part_names.join(' и ') }}</strong>
                    <span class="text-xs">Объём пересечения ≈ {{ volume(issue.volume_mm3) }}</span>
                    <span class="text-xs font-semibold">Показать на сцене →</span>
                </button>
            </div>
        </template>
    </section>
</template>

<script setup>
defineProps({ report: Object, loading: Boolean, error: String });
defineEmits(['check', 'inspect']);

const number = new Intl.NumberFormat('ru', { maximumFractionDigits: 2 });
const volume = (value) => value >= 1000 ? `${number.format(value / 1000)} см³` : `${number.format(value)} мм³`;
</script>
