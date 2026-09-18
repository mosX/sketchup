<template>
    <details class="inspector-section" @keydown.stop>
        <summary class="cursor-pointer text-sm font-semibold text-emerald-900">Точное размещение</summary>
        <div class="mt-3 grid gap-3">
            <p class="text-xs leading-5 text-stone-500">По исходным габаритам заготовок, до обработки. Изменение можно отменить через Ctrl+Z.</p>
            <label class="editor-field">Способ<select v-model="options.mode"><option value="faces">Грань к грани</option><option value="center">По центру</option><option value="edge">По краю</option><option value="between">Между двумя деталями</option></select></label>
            <label class="editor-field">Опорная деталь<select v-model.number="targetId"><option :value="null">Выберите…</option><option v-for="item in targets" :key="item.instance.id" :value="item.instance.id">{{ item.part.name }} #{{ item.instance.id }}</option></select></label>
            <template v-if="options.mode === 'faces'">
                <label class="editor-field">Грань выбранной детали<select v-model="options.face"><option v-for="(label, face) in faces" :key="face" :value="face">{{ label }}</option></select></label>
                <label class="editor-field">Грань опорной детали<select v-model="options.targetFace"><option v-for="(label, face) in faces" :key="face" :value="face">{{ label }}</option></select></label>
                <p class="text-xs text-stone-500">Центры граней совместятся, деталь автоматически развернётся.</p>
            </template>
            <template v-else>
                <label class="editor-field">Мировая ось<select v-model="options.axis"><option value="x">X — длина</option><option value="y">Y — ширина</option><option value="z">Z — высота</option></select></label>
                <label v-if="options.mode === 'edge'" class="editor-field">Край<select v-model="options.side"><option value="min">Со стороны −</option><option value="max">Со стороны +</option></select></label>
                <label v-if="options.mode === 'between'" class="editor-field">Вторая опора<select v-model.number="secondTargetId"><option :value="null">Выберите…</option><option v-for="item in targets.filter(item => item.instance.id !== targetId)" :key="item.instance.id" :value="item.instance.id">{{ item.part.name }} #{{ item.instance.id }}</option></select></label>
            </template>
            <label class="editor-field">{{ options.mode === 'between' ? 'Минимальный зазор с каждой стороны' : options.mode === 'faces' ? 'Зазор' : 'Смещение по оси' }}, мм<input v-model.number="options.gap" type="number" step="0.1"></label>
            <p v-if="error" role="alert" class="text-xs text-red-700">{{ error }}</p>
            <p v-if="disabled" class="text-xs text-amber-700">Снимите блокировку детали и выключите разнесённый вид.</p>
            <button class="button-primary button-compact" :disabled="disabled || busy || !targetId" type="button" @click="apply">{{ busy ? 'Сохранение…' : 'Разместить' }}</button>
        </div>
    </details>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { placeAssemblyPart } from '../editor/assemblyPlacement.js';
const props = defineProps({ source: { type: Object, required: true }, parts: { type: Array, required: true }, disabled: Boolean, commit: { type: Function, required: true } });
const options = reactive({ mode: 'faces', face: 'bottom', targetFace: 'top', axis: 'z', side: 'max', gap: 0 });
const targetId = ref(null), secondTargetId = ref(null), busy = ref(false), error = ref('');
const targets = computed(() => props.parts.flatMap(part => (part.instances ?? []).filter(instance => instance.id !== props.source.instance.id).map(instance => ({ part, instance }))));
const faces = { top: 'Верхняя пласть (+Z)', bottom: 'Нижняя пласть (−Z)', start: 'Начальный торец (−X)', end: 'Конечный торец (+X)', left: 'Левая кромка (−Y)', right: 'Правая кромка (+Y)' };
const apply = async () => {
    if (props.disabled || busy.value) return;
    busy.value = true; error.value = '';
    try {
        const result = placeAssemblyPart(props.source, targets.value.find(item => item.instance.id === targetId.value), options, targets.value.find(item => item.instance.id === secondTargetId.value));
        const saved = await props.commit({ instanceId: props.source.instance.id, initialPosition: { ...props.source.instance.position }, initialRotation: { ...props.source.instance.rotation }, ...result });
        if (saved === false) error.value = 'Не удалось сохранить положение. Попробуйте ещё раз.';
    } catch (failure) { error.value = failure.message; } finally { busy.value = false; }
};
</script>
