<template>
    <section class="grid gap-3 p-3" aria-label="Инструкция по сборке">
        <header class="flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-stone-900">Инструкция по сборке</h2>
            <button class="button-secondary button-compact" type="button" :disabled="saving" @click="$emit('close')">Закрыть</button>
        </header>
        <p v-if="loading" class="text-xs text-stone-600" role="status">Загружаем шаги…</p>
        <p v-if="error" class="text-xs text-red-700" role="alert">{{ error }}</p>
        <template v-if="!loading && (available || editing)">
            <AssemblySequenceDiagnostics :report="sequence" @inspect="$emit('inspect-issue', $event)" />
            <label v-if="steps.length" class="editor-field">Шаг {{ index + 1 }} из {{ steps.length }}
                <select :value="index" @change="$emit('navigate', Number($event.target.value))">
                    <option v-for="(item, number) in steps" :key="number" :value="number">{{ number + 1 }}. {{ item.title || 'Новый шаг' }}</option>
                </select>
            </label>
            <template v-if="editing">
                <div class="flex flex-wrap gap-1.5">
                    <button class="button-secondary button-compact" type="button" :disabled="saving || steps.length >= 200" @click="$emit('add')">+ Шаг</button>
                    <button class="button-secondary button-compact" type="button" :disabled="saving || index <= 0" aria-label="Переместить шаг выше" @click="$emit('move', -1)">↑</button>
                    <button class="button-secondary button-compact" type="button" :disabled="saving || index >= steps.length - 1" aria-label="Переместить шаг ниже" @click="$emit('move', 1)">↓</button>
                    <button class="button-secondary button-compact" type="button" :disabled="saving || !step" @click="$emit('remove')">Удалить шаг</button>
                </div>
                <fieldset v-if="step" class="grid min-w-0 gap-2" :disabled="saving">
                    <label class="editor-field">Название
                        <input :value="step.title" maxlength="255" @input="set('title', $event.target.value)">
                    </label>
                    <label class="editor-field">Что сделать
                        <textarea :value="step.instruction" rows="3" maxlength="5000" @input="set('instruction', $event.target.value)"></textarea>
                    </label>
                    <label class="editor-field">Комментарии
                        <textarea :value="step.notes" rows="2" maxlength="5000" @input="set('notes', $event.target.value)"></textarea>
                    </label>
                    <details class="grid rounded-lg border border-stone-200 p-2">
                        <summary class="cursor-pointer text-xs font-medium">Детали и подсветка</summary>
                        <p class="mt-2 text-xs text-stone-600">Клик по детали на сцене переключает её подсветку.</p>
                        <label class="editor-field mt-2">Поиск детали
                            <input v-model="search" type="search" placeholder="Название, код или №">
                        </label>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button class="button-secondary button-compact" type="button" @click="set('highlight_instance_ids', null)">Подсветить детали шага</button>
                            <button class="button-secondary button-compact" type="button" @click="set('highlight_instance_ids', [])">Снять подсветку</button>
                        </div>
                        <div class="mt-2 grid max-h-48 gap-1 overflow-y-auto">
                            <div v-for="item in filteredInstances" :key="item.id" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 text-xs text-stone-700">
                                <label class="flex min-w-0 items-center gap-2">
                                    <input type="checkbox" :checked="step.instance_ids.includes(item.id)" @change="toggle('instance_ids', item.id)">
                                    <span class="truncate" :title="item.label">{{ item.label }}</span>
                                </label>
                                <button class="button-secondary button-compact" type="button" :aria-pressed="step.highlighted.includes(item.id)" :class="{ 'bg-emerald-100!': step.highlighted.includes(item.id) }" :aria-label="`Подсветка детали ${item.label}`" @click="toggle('highlight_instance_ids', item.id)">✦</button>
                            </div>
                            <p v-if="!filteredInstances.length" class="text-xs text-stone-500">Детали не найдены.</p>
                        </div>
                    </details>
                    <details class="rounded-lg border border-stone-200 p-2">
                        <summary class="cursor-pointer text-xs font-medium">Крепёж</summary>
                        <label class="mt-2 flex items-center gap-2 text-xs">
                            <input type="checkbox" :checked="step.fastener_ids === null" @change="set('fastener_ids', $event.target.checked ? null : step.activeFastenerIds)">
                            Назначать автоматически
                        </label>
                        <div v-if="step.fastener_ids !== null" class="mt-2 grid max-h-40 gap-1 overflow-y-auto">
                            <label v-for="item in fasteners" :key="item.id" class="flex items-center gap-2 text-xs">
                                <input type="checkbox" :checked="step.fastener_ids.includes(item.id)" @change="toggle('fastener_ids', item.id)">
                                {{ assemblyFastenerLabel(item) }} · №{{ item.id }}
                            </label>
                            <p v-if="!fasteners.length" class="text-xs text-stone-500">В проекте пока нет крепежа.</p>
                        </div>
                    </details>
                    <div v-if="missingReferences" class="grid gap-1 rounded-md bg-amber-50 p-2 text-xs text-amber-900">
                        <p>В шаге есть ссылки на удалённые детали или крепёж.</p>
                        <button class="button-secondary button-compact justify-self-start" type="button" @click="removeMissing">Убрать ссылки</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button class="button-secondary button-compact" type="button" @click="$emit('capture-camera')">{{ step.camera ? 'Обновить ракурс' : 'Запомнить ракурс' }}</button>
                        <button v-if="step.camera" class="button-secondary button-compact" type="button" @click="set('camera', null)">Сбросить ракурс</button>
                    </div>
                </fieldset>
                <div class="flex flex-wrap gap-2">
                    <button class="button-primary button-compact" type="button" :disabled="saving || steps.some(item => !item.title.trim())" @click="$emit('save')">{{ saving ? 'Сохраняем…' : 'Сохранить' }}</button>
                    <button class="button-secondary button-compact" type="button" :disabled="saving" @click="$emit('cancel')">Отменить</button>
                </div>
            </template>
            <template v-else>
                <p v-if="!saved && steps.length" class="text-xs text-stone-600">Черновик по узлам. Проверьте порядок и добавьте пояснения.</p>
                <p v-else-if="!step" class="text-xs text-stone-600">Добавьте первый шаг или поместите детали в сборку для подсказок.</p>
                <template v-if="step">
                    <h3 class="text-sm font-semibold text-stone-900">{{ step.title }}</h3>
                    <p v-if="step.instruction" class="whitespace-pre-wrap break-words text-sm text-stone-800">{{ step.instruction }}</p>
                    <p v-if="step.notes" class="whitespace-pre-wrap break-words rounded-md bg-amber-50 p-2 text-xs text-amber-900">{{ step.notes }}</p>
                    <div v-if="step.parts.length" class="grid gap-1">
                        <h4 class="text-xs font-semibold">Детали</h4>
                        <button v-for="part in step.parts" :key="part.id" class="rounded-md border border-stone-200 px-2 py-1.5 text-left text-xs hover:bg-emerald-50" type="button" @click="$emit('inspect', part.ids)">
                            <strong>{{ part.code }} · {{ part.name }} ×{{ part.ids.length }}</strong>
                            <span class="block text-stone-600">{{ part.size }} мм</span>
                        </button>
                    </div>
                    <div v-if="step.hardware.length" class="grid gap-1">
                        <h4 class="text-xs font-semibold">Крепёж</h4>
                        <ul class="grid gap-0.5 text-xs">
                            <li v-for="item in step.hardware" :key="item.label">{{ item.label }} ×{{ item.count }}</li>
                        </ul>
                    </div>
                    <p v-if="missingReferences" class="text-xs text-amber-800">Некоторые детали или крепёж были удалены из проекта. Обновите шаг.</p>
                    <div class="flex flex-wrap gap-2">
                        <button class="button-secondary button-compact" type="button" :disabled="index === 0" @click="$emit('navigate', index - 1)">Назад</button>
                        <button class="button-primary button-compact" type="button" @click="index === steps.length - 1 ? $emit('close') : $emit('navigate', index + 1)">{{ index === steps.length - 1 ? 'Готово' : 'Далее' }}</button>
                    </div>
                </template>
                <div class="flex flex-wrap gap-2 border-t border-stone-200 pt-2">
                    <button class="button-secondary button-compact" type="button" @click="$emit('edit')">{{ saved ? 'Редактировать' : 'Создать инструкцию' }}</button>
                    <button class="button-secondary button-compact" type="button" :disabled="!steps.length" @click="$emit('print')">Печать / PDF</button>
                </div>
            </template>
        </template>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { assemblyFastenerLabel } from '../editor/assemblyInstructions.js';
import AssemblySequenceDiagnostics from './AssemblySequenceDiagnostics.vue';

const props = defineProps({
    steps: Array, step: Object, index: Number, instances: Array, fasteners: Array, sequence: Object,
    available: Boolean, editing: Boolean, saved: Boolean, loading: Boolean, saving: Boolean, error: String,
});
const emit = defineEmits(['close', 'navigate', 'edit', 'cancel', 'add', 'move', 'remove', 'patch', 'save', 'capture-camera', 'inspect', 'inspect-issue', 'print']);
const search = ref('');
const filteredInstances = computed(() => props.instances.filter(item => item.label.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase())));
const instanceIds = computed(() => new Set(props.instances.map(item => item.id)));
const fastenerIds = computed(() => new Set(props.fasteners.map(item => item.id)));
const fields = ['instance_ids', 'highlight_instance_ids', 'fastener_ids'];
const missingReferences = computed(() => props.step && fields.some(field => {
    const valid = field === 'fastener_ids' ? fastenerIds.value : instanceIds.value;
    return props.step[field]?.some(id => !valid.has(id));
}));
const set = (key, value) => emit('patch', { [key]: value });
const toggle = (key, id) => {
    const current = props.step[key] ?? props.step.highlighted;
    set(key, current.includes(id) ? current.filter(value => value !== id) : [...current, id]);
};
const removeMissing = () => emit('patch', Object.fromEntries(fields.map(field => {
    const valid = field === 'fastener_ids' ? fastenerIds.value : instanceIds.value;
    return [field, props.step[field]?.filter(id => valid.has(id)) ?? null];
})));
</script>
