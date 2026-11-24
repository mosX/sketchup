<template>
    <div class="inspector-section inspector-context">
        <div class="flex items-center justify-between gap-2">
            <h2 class="inspector-name">Крепёж</h2>
            <button class="icon-button" type="button" aria-label="Закрыть крепёж" @click="tool.close">×</button>
        </div>
        <p v-if="!tool.copyDraft" class="mt-1 text-xs text-stone-600">{{ tool.editingId ? 'Кликните в новое место на детали или измените координаты.' : 'Кликните по грани детали, чтобы выбрать место под головку.' }}</p>
    </div>
    <form v-if="tool.copyDraft" class="inspector-section" @submit.prevent="tool.applyCopy">
        <h3 class="inspector-heading">Копирование ряда · {{ tool.copyDraft.fastener_ids.length }}</h3>
        <fieldset class="mt-2 grid min-w-0 gap-2" :disabled="tool.busy">
            <p v-if="!tool.copyDraft.face" class="text-xs text-stone-600">Нажмите на грань, куда нужно перенести ряд.</p>
            <template v-else>
                <p class="text-xs font-semibold text-stone-700">{{ instanceName(tool.copyDraft.source_instance_id) }} · {{ faceLabel(tool.copyDraft.face) }}</p>
                <div class="flex flex-wrap gap-3 text-xs">
                    <label class="flex items-center gap-1.5"><input v-model="tool.copyDraft.mirror_u" type="checkbox" class="accent-emerald-700">Отразить по U</label>
                    <label class="flex items-center gap-1.5"><input v-model="tool.copyDraft.mirror_v" type="checkbox" class="accent-emerald-700">Отразить по V</label>
                </div>
                <label class="editor-field">Вторая деталь
                    <select v-model="tool.copyDraft.target_instance_id">
                        <option value="auto">Определить автоматически</option>
                        <option :value="null">Без проверки</option>
                        <option v-for="instance in instances.filter(item => item.id !== tool.copyDraft.source_instance_id)" :key="instance.id" :value="instance.id">{{ instance.name }} · #{{ instance.id }}</option>
                    </select>
                </label>
                <p v-if="tool.copyPending" role="status" class="text-xs text-stone-600">Проверяем расположение…</p>
                <FastenerReachSummary v-else :placements="tool.preview" :instances="instances" />
            </template>
            <div class="flex flex-wrap gap-2">
                <button class="button-primary button-compact" type="submit" :disabled="tool.copyPending || tool.checkingGeometry || !tool.preview.length">Скопировать ряд</button>
                <button class="button-secondary button-compact" type="button" @click="tool.cancelCopy">Отмена</button>
            </div>
        </fieldset>
    </form>
    <form v-else class="inspector-section" @submit.prevent="tool.save">
        <fieldset :disabled="tool.busy || Boolean(tool.drillPreview)" class="grid min-w-0 gap-3">
            <label class="editor-field">Крепёж из каталога
                <select v-model="tool.draft.catalog_material_id">
                    <option :value="null">Выберите крепёж</option>
                    <option v-for="item in catalog" :key="item.id" :value="item.id">
                        {{ item.name }}{{ item.fastener_length ? ` · Ø${Number(item.fastener_diameter)} × ${Number(item.fastener_length)} мм` : '' }}
                    </option>
                </select>
            </label>
            <p v-if="!catalog.length" class="text-xs leading-5 text-stone-600">
                Добавьте шуруп или гвоздь в каталог расходников.
                <router-link class="font-semibold text-emerald-800 underline" :to="{ name: 'materials' }" target="_blank" rel="noopener">Открыть каталог ↗</router-link>
                <button type="button" class="ml-2 font-semibold text-emerald-800 underline" @click="tool.open">Обновить</button>
            </p>
            <div v-if="tool.draft.face" class="grid gap-2">
                <p class="text-xs font-semibold text-stone-700">{{ instanceName(tool.draft.source_instance_id) }} · {{ faceLabel(tool.draft.face) }}</p>
                <div class="grid grid-cols-3 gap-2">
                    <label class="dimension-field"><span>U, мм</span><input v-scrub-number v-model.number="tool.draft.center_u" type="number" min="0" :max="tool.faceSize.u" step="0.01" required></label>
                    <label class="dimension-field"><span>V, мм</span><input v-scrub-number v-model.number="tool.draft.center_v" type="number" min="0" :max="tool.faceSize.v" step="0.01" required></label>
                    <label class="dimension-field"><span>Посадка, мм</span><input v-scrub-number v-model.number="tool.draft.sink" type="number" min="0" :max="Math.min(tool.faceSize.depth, 1000)" step="0.01" required></label>
                </div>
                <label class="editor-field">Вторая деталь
                    <select v-model="tool.draft.target_instance_id">
                        <option value="auto">Определить автоматически</option>
                        <option :value="null">Без проверки</option>
                        <option v-for="instance in instances.filter(item => item.id !== tool.draft.source_instance_id)" :key="instance.id" :value="instance.id">{{ instance.name }} · #{{ instance.id }}</option>
                    </select>
                </label>
                <div v-if="!tool.editingId" class="grid grid-cols-3 gap-2">
                    <label class="dimension-field"><span>Кол-во</span><input v-scrub-number v-model.number="tool.row.count" type="number" min="1" max="50" required></label>
                    <label v-if="tool.row.count > 1" class="dimension-field"><span>Ось ряда</span><select v-model="tool.row.row_axis"><option value="u">U</option><option value="v">V</option></select></label>
                    <label v-if="tool.row.count > 1" class="dimension-field"><span>Шаг, мм</span><input v-scrub-number v-model.number="tool.row.spacing" type="number" min="0.1" max="100000" step="0.01" required></label>
                </div>
                <FastenerReachSummary :placements="tool.preview" :instances="instances" />
                <p v-if="tool.draft.catalog_material_id && !tool.preview.length" class="text-xs text-amber-800">Проверьте границы детали, глубину посадки и шаг ряда.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button class="button-primary button-compact" type="submit" :disabled="tool.checkingGeometry || !tool.preview.length">{{ tool.editingId ? 'Сохранить положение' : 'Установить' }}</button>
                <button v-if="tool.editingId" class="button-secondary button-compact" type="button" @click="tool.clear">Новый</button>
                <button v-if="tool.editingId" class="danger-button button-compact" type="button" @click="tool.remove">Убрать</button>
            </div>
        </fieldset>
    </form>
    <p v-if="tool.error" role="alert" class="inspector-section text-xs text-red-700">{{ tool.error }}</p>
    <div v-if="tool.items.length" class="inspector-section">
        <div class="flex flex-wrap items-center justify-between gap-1">
            <h3 class="inspector-heading">На сцене · {{ tool.items.length }}</h3>
            <span class="text-xs text-stone-500">Выбрано · {{ tool.selectedItems.length }}</span>
        </div>
        <ul class="mt-2 grid max-h-44 gap-1 overflow-auto">
            <li v-for="item in tool.items" :key="item.id" class="flex items-center gap-2">
                <input class="accent-emerald-700" type="checkbox" :aria-label="'Выбрать крепёж №' + item.id" :checked="tool.selectedIds.includes(item.id)" :disabled="tool.busy || Boolean(tool.copyDraft) || (!tool.selectedIds.includes(item.id) && tool.selectedIds.length >= 50)" @change="tool.toggleSelection(item.id)">
                <button type="button" :aria-pressed="tool.editingId === item.id" :disabled="Boolean(tool.copyDraft)" class="w-full rounded-md px-2 py-1.5 text-left text-xs hover:bg-emerald-50 aria-pressed:bg-emerald-100" @click="tool.edit(item.id)">
                    <strong>{{ tool.catalog.find(material => material.id === item.catalog_material_id)?.name ?? fastenerLabel(item) }} · #{{ item.id }}</strong>
                    <small class="block text-stone-600">{{ fastenerLabel(item) }} · {{ instanceName(item.source_instance_id) }}</small>
                </button>
            </li>
        </ul>
        <div v-if="!tool.copyDraft && tool.selectedItems.length >= 2" class="mt-2">
            <button v-if="tool.canCopyRow" class="button-secondary button-compact" type="button" :disabled="tool.busy || Boolean(tool.drillPreview)" @click="tool.startCopy">Копировать ряд</button>
            <p v-else class="text-xs text-stone-600">Для копирования отметьте крепёж с одной грани.</p>
        </div>
    </div>
    <form v-if="!tool.copyDraft && tool.drillItems.length" class="inspector-section" @submit.prevent="tool.previewDrilling">
        <h3 class="inspector-heading">Подготовить отверстия</h3>
        <p v-if="tool.drillItems.length < tool.selectedItems.length" class="mt-1 text-xs text-stone-600">Сверление для выбранных шурупов · {{ tool.drillItems.length }}</p>
        <fieldset class="mt-2 grid min-w-0 gap-2" :disabled="tool.busy">
            <label class="editor-field">Диаметр проходного, мм
                <input v-scrub-number v-model.number="tool.drillSettings.clearance_diameter" type="number" min="0.1" max="10000" step="0.01" required>
            </label>
            <label class="flex items-center gap-2 text-xs">
                <input :checked="tool.drillSettings.countersink" class="accent-emerald-700" type="checkbox" @change="tool.toggleCountersink($event.target.checked)">Зенковка под головку
            </label>
            <div v-if="tool.drillSettings.countersink" class="grid grid-cols-2 gap-2">
                <label class="dimension-field"><span>Диаметр зенковки, мм</span><input v-scrub-number v-model.number="tool.drillSettings.countersink_diameter" type="number" :min="(Number(tool.drillSettings.clearance_diameter) * 100 + 1) / 100" max="10000" step="0.01" required></label>
                <label class="dimension-field"><span>Глубина зенковки, мм</span><input v-scrub-number v-model.number="tool.drillSettings.countersink_depth" type="number" min="0.1" max="10000" step="0.01" required></label>
            </div>
            <label v-if="tool.drillHasTarget" class="flex items-center gap-2 text-xs">
                <input v-model="tool.drillSettings.pilot" class="accent-emerald-700" type="checkbox">Направляющее во второй детали
            </label>
            <div v-if="tool.drillHasTarget && tool.drillSettings.pilot" class="grid grid-cols-2 gap-2">
                <label class="dimension-field"><span>Диаметр, мм</span><input v-scrub-number v-model.number="tool.drillSettings.pilot_diameter" type="number" min="0.1" max="10000" step="0.01" required></label>
                <label class="dimension-field"><span>Глубина, мм</span><input v-scrub-number v-model.number="tool.drillSettings.pilot_depth" type="number" min="0.1" max="10000" step="0.01" required></label>
            </div>
            <button v-if="!tool.drillPreview" class="button-primary button-compact" type="submit">{{ tool.busy ? 'Рассчитываем…' : 'Предпросмотр' }}</button>
            <template v-else>
                <p class="text-xs font-medium text-emerald-800" role="status">{{ tool.drillReady ? 'Голубым отмечена выборка. Операций: ' + tool.drillPreview.created_operation_ids.length : 'Строим геометрию…' }}</p>
                <div v-if="headFits.length && tool.drillReady" class="grid gap-1 text-xs text-stone-600">
                    <p class="font-semibold">Головка при полной посадке</p>
                    <p v-for="[label, count] in headFits" :key="label">
                        {{ label }}<span v-if="count > 1"> · {{ count }} шт.</span>
                    </p>
                </div>
                <button class="button-primary button-compact" type="button" :disabled="!tool.drillReady" @click="tool.applyDrilling">Применить отверстия</button>
                <button class="button-secondary button-compact" type="button" @click="tool.cancelDrillPreview">Закрыть предпросмотр</button>
            </template>
        </fieldset>
    </form>
</template>

<script setup>
import { computed } from 'vue';
import FastenerReachSummary from './FastenerReachSummary.vue';
import { countersinkHeadOffset } from '../editor/fasteners.js';
import { fastenerTypeLabels } from '../editor/materialCatalog.js';
import { vScrubNumber } from '../editor/scrubNumber.js';
import { faceLabel } from '../editor/operations/shared.js';

const props = defineProps({ tool: { type: Object, required: true }, instances: { type: Array, required: true } });
const catalog = computed(() => props.tool.catalog.filter(item => item.fastener_length || item.id === props.tool.draft.catalog_material_id));
const fastenerLabel = item => fastenerTypeLabels[item.fastener_type ?? 'screw'];
const instanceName = (id) => props.instances.find(instance => instance.id === id)?.name ?? 'Деталь';
const millimeters = new Intl.NumberFormat('ru', { maximumFractionDigits: 2 });
const headFits = computed(() => {
    const settings = props.tool.drillPreview?.payload;
    if (!settings?.countersink) return [];

    const counts = new Map();
    for (const item of props.tool.drillItems) {
        const offset = countersinkHeadOffset(item, settings);
        let label = 'Заподлицо';
        if (offset === null) label = 'Головка проходит в отверстие';
        else if (offset > 0.05) label = `Выступает на ${millimeters.format(offset)} мм`;
        else if (offset < -0.05) label = `Утоплена на ${millimeters.format(-offset)} мм`;
        counts.set(label, (counts.get(label) ?? 0) + 1);
    }
    return [...counts];
});
</script>
