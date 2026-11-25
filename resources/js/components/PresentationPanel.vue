<template>
    <div class="absolute right-3 top-14 z-4 grid max-h-[calc(100%_-_4.25rem)] w-64 max-w-[calc(100%_-_1.5rem)] gap-2 overflow-y-auto overscroll-contain rounded-xl border border-stone-200 bg-white/95 p-3 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between gap-2">
            <strong class="text-sm text-stone-800">Рендер</strong>
            <button class="button-secondary button-compact" type="button" :disabled="!hasModel" @click="$emit('frame')">В кадр</button>
        </div>
        <label class="editor-field">Древесина
            <select :value="settings.wood" @change="set('wood', $event.target.value)">
                <option value="pine">Сосна</option>
                <option value="spruce">Ель</option>
                <option value="oak">Светлый дуб</option>
                <option value="dark">Тёмная древесина</option>
            </select>
        </label>
        <label class="editor-field">Отделка
            <select :value="settings.finish" @change="set('finish', $event.target.value)">
                <option value="raw">Без покрытия</option>
                <option value="oil">Масло</option>
                <option value="lacquer">Лак</option>
            </select>
        </label>
        <label class="editor-field">Освещение
            <select :value="settings.light" @change="set('light', $event.target.value)">
                <option value="studio">Мягкий студийный</option>
                <option value="daylight">Дневной</option>
                <option value="contrast">Контрастный</option>
            </select>
        </label>
        <details class="rounded-md border border-stone-200 px-2 py-1.5">
            <summary class="cursor-pointer text-xs font-medium text-stone-700">Настроить свет</summary>
            <div class="mt-2 grid gap-1.5">
                <label v-for="control in lightingControls" :key="control.key" class="grid gap-0.5 text-xs text-stone-700">
                    <span class="flex justify-between gap-2">
                        {{ control.label }}
                        <span class="tabular-nums text-stone-600">{{ settings[control.key] }}{{ control.unit }}</span>
                    </span>
                    <input
                        type="range"
                        class="w-full cursor-pointer accent-emerald-700"
                        :min="control.min"
                        :max="control.max"
                        :step="control.step"
                        :value="settings[control.key]"
                        @input="set(control.key, $event.target.valueAsNumber)"
                    >
                </label>
                <button class="button-secondary button-compact justify-self-end" type="button" @click="resetLighting">Сбросить свет</button>
            </div>
        </details>
        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2">
            <label class="editor-field">Формат файла
                <select v-model="format" :disabled="saving">
                    <option value="png">PNG · изображение</option>
                    <option value="obj">OBJ · геометрия</option>
                    <option value="glb">GLB · с текстурами</option>
                </select>
            </label>
            <button class="button-primary button-compact" type="button" :disabled="saving || !hasModel" @click="$emit('save', format)">
                {{ saving ? 'Сохраняем…' : 'Скачать' }}
            </button>
        </div>
        <p v-if="status === 'loading'" class="text-xs text-stone-600" role="status">Загружаем материалы…</p>
        <p v-else-if="status === 'error'" class="text-xs text-red-700" role="alert">Не удалось открыть рендер. Вернитесь в редактор и попробуйте снова.</p>
        <p v-if="error" class="text-xs text-red-700" role="alert">{{ error }}</p>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { presentationLightingDefaults } from '../editor/woodPresentation.js';

defineProps({ status: String, hasModel: Boolean, saving: Boolean, error: String });
defineEmits(['frame', 'save']);
const settings = defineModel({ type: Object, required: true });
const format = ref('png');
const lightingControls = [
    { key: 'exposure', label: 'Экспозиция', min: -2, max: 1.5, step: 0.1, unit: ' EV' },
    { key: 'keyPower', label: 'Основной свет', min: 0, max: 200, step: 5, unit: '%' },
    { key: 'fillPower', label: 'Заполняющий свет', min: 0, max: 200, step: 5, unit: '%' },
    { key: 'azimuth', label: 'Поворот источника', min: -180, max: 180, step: 5, unit: '°' },
    { key: 'elevation', label: 'Наклон источника', min: -35, max: 30, step: 1, unit: '°' },
];
const set = (key, value) => { settings.value = { ...settings.value, [key]: value }; };
const resetLighting = () => { settings.value = { ...settings.value, ...presentationLightingDefaults }; };
</script>
