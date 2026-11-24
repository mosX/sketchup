<template>
    <section class="mt-5 rounded-xl border border-emerald-900/15 bg-emerald-50/40 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-emerald-950">Материалы и смета</h3>
            <router-link class="text-xs font-semibold text-emerald-800 underline underline-offset-2" :to="{ name: 'materials' }" target="_blank" rel="noopener">Открыть каталог ↗</router-link>
        </div>
        <p v-if="error" class="mt-3 rounded-lg bg-red-50 p-2 text-sm text-red-800" role="alert">{{ error }}</p>
        <p v-if="loading" class="mt-2 text-sm text-stone-500">Загружаем материалы…</p>
        <template v-else>
            <details class="inspector-disclosure mt-2 border-b border-emerald-900/10 pb-2" :open="focusPartId !== null">
                <summary class="cursor-pointer">Назначить материал и добавить расходники<span v-if="dirty" class="text-amber-700"> · есть изменения</span></summary>
                <form class="mt-3 grid gap-4" @submit.prevent="savePlan">
                    <fieldset class="grid gap-3" :disabled="busy">
                        <label class="field-label">Материал проекта по умолчанию
                            <select v-model="plan.default_material_id" class="field-input max-w-lg">
                                <option :value="null">Выбрать для каждой заготовки</option>
                                <option v-for="material in stock" :key="material.id" :value="material.id">{{ catalogOption(material) }}</option>
                            </select>
                        </label>
                        <p v-if="!stock.length" class="text-xs text-stone-600">Добавьте доску, брус или лист в каталог.</p>
                        <div v-else-if="parts.length" class="max-h-60 divide-y divide-emerald-900/10 overflow-auto rounded-lg border border-emerald-900/10 bg-white">
                            <label v-for="part in parts" :key="part.id" class="grid items-center gap-2 px-3 py-2 text-sm sm:grid-cols-[minmax(9rem,1fr)_minmax(0,2fr)]">
                                <span class="min-w-0"><strong class="block truncate">{{ part.name }}</strong><small class="text-stone-500">{{ part.dimensions.length }} × {{ part.dimensions.width }} × {{ part.dimensions.thickness }} мм · {{ part.instance_count ?? part.instances?.length ?? 0 }} шт.</small></span>
                                <select v-model="plan.assignments[part.id]" :ref="element => { if (focusPartId === part.id) focusedPartSelect = element; }" class="field-input min-w-0">
                                    <option :value="undefined">Как в проекте</option>
                                    <option v-for="material in stock" :key="material.id" :value="material.id">{{ catalogOption(material) }}</option>
                                </select>
                            </label>
                        </div>

                        <div>
                            <h4 class="text-sm font-semibold">Расходники на весь проект</h4>
                            <div v-for="supply in plan.supplies" :key="supply.material_id" class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-white px-3 py-2">
                                <span class="min-w-32 flex-1 text-sm">{{ byId.get(supply.material_id)?.name }}</span>
                                <label class="flex items-center gap-2 text-xs text-stone-600"><span class="sr-only">Количество для {{ byId.get(supply.material_id)?.name }}</span><input v-model.number="supply.quantity" class="field-input w-24" type="number" min="0.001" max="1000000" step="0.001" required>{{ byId.get(supply.material_id)?.unit }}</label>
                                <button class="icon-button" type="button" :aria-label="'Убрать ' + byId.get(supply.material_id)?.name" @click="removeSupply(supply.material_id)">×</button>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <select v-model="newSupplyId" class="field-input max-w-lg min-w-0 flex-1" aria-label="Расходник из каталога">
                                    <option :value="null">Выбрать расходник</option>
                                    <option v-for="material in availableSupplies" :key="material.id" :value="material.id">{{ catalogOption(material) }}</option>
                                </select>
                                <button class="button-secondary button-compact" type="button" :disabled="!newSupplyId" @click="addSupply">Добавить</button>
                            </div>
                        </div>
                    </fieldset>
                    <div class="flex flex-wrap gap-2">
                        <button class="button-primary button-compact" type="submit" :disabled="busy || !dirty">Сохранить материалы и пересчитать</button>
                        <button class="button-secondary button-compact" type="button" :disabled="busy" @click="loadCatalog">Обновить каталог</button>
                    </div>
                </form>
            </details>

            <div v-if="displayCost" class="mt-3 grid grid-cols-2 gap-2">
                <div class="rounded-lg bg-white p-3"><div class="text-xs text-stone-500">Стоимость расхода</div><strong>{{ materialCost(displayCost.consumed_minor) }}</strong></div>
                <div class="rounded-lg bg-white p-3"><div class="text-xs text-stone-500">К закупке</div><strong>{{ materialCost(displayCost.purchase_minor) }}</strong></div>
            </div>
            <p v-if="displayCost && !displayCost.complete" class="mt-2 text-xs font-semibold text-amber-800">Смета неполная. Для части позиций нет цены или подходящего материала.</p>
            <p v-if="!estimate && dirty" class="mt-2 text-xs text-amber-800">Показан расчёт по сохранённым материалам.</p>

            <ul v-if="displayCost?.issues?.length" class="mt-2 max-h-40 overflow-auto rounded-lg bg-amber-50 p-3 text-xs text-amber-950">
                <li v-for="issue in displayCost.issues" :key="issue.part_id" class="py-0.5"><strong>{{ issue.name }} · {{ issue.quantity }} шт.</strong> — {{ reasons[issue.reason] }}<span v-if="issue.material"> ({{ issue.material }})</span></li>
            </ul>
            <div v-if="displayCost?.items?.length" class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[34rem] text-left text-xs">
                    <thead class="text-stone-500"><tr><th class="py-2 pr-2">Позиция</th><th>Расход</th><th>Закупка</th><th>Остаток</th><th>Цена</th><th>Расход, грн</th><th>Закупка, грн</th></tr></thead>
                    <tbody class="divide-y divide-emerald-900/10">
                        <tr v-for="item in displayCost.items" :key="item.material_id">
                            <th class="py-2 pr-2 font-medium">{{ item.name }}<small v-if="item.scene_quantity" class="block text-stone-500">На модели {{ item.scene_quantity }} · вручную {{ item.manual_quantity }}</small></th><td>{{ item.quantity }} {{ item.unit }}</td><td>{{ item.packs }} {{ item.kind === 'consumable' ? 'уп.' : 'шт.' }}</td>
                            <td>{{ item.remaining_quantity }} {{ item.unit }}</td>
                            <td>{{ materialCost(item.pack_price_minor) }}</td><td>{{ materialCost(item.consumed_minor) }}</td><td>{{ materialCost(item.purchase_minor) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button class="button-secondary button-compact" type="button" :disabled="busy || analysisBusy || dirty || !costing?.items?.length || Boolean(estimate)" @click="freezeEstimate">Зафиксировать смету</button>
                <select v-if="estimates.length" :value="estimate?.id ?? ''" class="field-input max-w-56" aria-label="Сохранённые сметы" :disabled="busy" @change="openEstimate($event.target.value)">
                    <option value="">Текущие цены</option>
                    <option v-for="saved in estimates" :key="saved.id" :value="saved.id">{{ new Date(saved.created_at).toLocaleString('ru-UA') }}</option>
                </select>
                <button v-if="nextPage" class="button-secondary button-compact" type="button" :disabled="busy" @click="loadMoreEstimates">Ещё сметы</button>
            </div>
        </template>
    </section>
</template>

<script setup>
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { materialCost, materialSize } from '../editor/materialCatalog.js';

const props = defineProps({
    projectId: { type: Number, required: true },
    parts: { type: Array, required: true },
    costing: { type: Object, default: null },
    settings: { type: Object, required: true },
    analysisBusy: Boolean,
    focusPartId: { type: Number, default: null },
});
const emit = defineEmits(['refresh']);
const path = `/v1/projects/${props.projectId}`;
const reasons = {
    unassigned: 'не назначен материал',
    incompatible: 'сечение, толщина или направление волокон не подходят',
    oversized: 'не помещается с учётом пропила и отступов',
};
const plan = reactive({ default_material_id: null, assignments: {}, supplies: [] });
const catalog = ref([]);
const stock = computed(() => catalog.value.filter(item => item.kind !== 'consumable'));
const byId = computed(() => new Map(catalog.value.map(item => [item.id, item])));
const availableSupplies = computed(() => catalog.value.filter(item => item.kind === 'consumable' && !plan.supplies.some(supply => supply.material_id === item.id)));
const newSupplyId = ref(null);
const loading = ref(true);
let focusedPartSelect = null;
const busy = ref(false);
const error = ref('');
const estimates = ref([]);
const nextPage = ref(null);
const estimate = ref(null);
const displayCost = computed(() => estimate.value?.manufacturing.costing ?? props.costing);
const savedPlan = ref(null);
const payload = computed(() => ({
    default_material_id: plan.default_material_id,
    assignments: props.parts.flatMap(part => plan.assignments[part.id] == null ? [] : [{ part_id: part.id, material_id: plan.assignments[part.id] }]),
    supplies: plan.supplies.map(supply => ({ material_id: supply.material_id, quantity: Number(supply.quantity) })),
}));
const dirty = computed(() => savedPlan.value !== null && JSON.stringify(payload.value) !== savedPlan.value);
const catalogOption = (material) => `${material.name} · ${materialSize(material)}`;

const request = async (action) => {
    busy.value = true;
    error.value = '';
    try {
        await action();
    } catch (requestError) {
        const data = requestError.response?.data;
        error.value = Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? 'Не удалось обновить материалы или смету.';
    } finally {
        busy.value = false;
    }
};

const fetchCatalog = async () => {
    catalog.value = (await axios.get('/v1/materials')).data.data;
};
const loadCatalog = () => request(async () => {
    await fetchCatalog();
    if (savedPlan.value === null) await fetchPlan();
    emit('refresh');
});
const fetchEstimates = async (page = 1) => {
    const result = (await axios.get(`${path}/estimates`, { params: { page } })).data.data;
    estimates.value = page === 1 ? result.data : [...estimates.value, ...result.data];
    nextPage.value = result.current_page < result.last_page ? result.current_page + 1 : null;
};
const loadMoreEstimates = () => request(() => fetchEstimates(nextPage.value));
const fetchPlan = async () => {
    const current = (await axios.get(`${path}/materials`)).data.data;
    if (current) {
        plan.default_material_id = current.default_material_id == null ? null : Number(current.default_material_id);
        plan.assignments = Object.fromEntries(current.assignments.map(row => [row.part_id, Number(row.material_id)]));
        plan.supplies = current.supplies.map(row => ({ material_id: Number(row.material_id), quantity: Number(row.quantity) }));
    }
    savedPlan.value = JSON.stringify(payload.value);
};
const savePlan = () => request(async () => {
    await axios.put(`${path}/materials`, payload.value);
    savedPlan.value = JSON.stringify(payload.value);
    estimate.value = null;
    emit('refresh');
});
const addSupply = () => {
    plan.supplies.push({ material_id: newSupplyId.value, quantity: 1 });
    newSupplyId.value = null;
};
const removeSupply = (id) => {
    plan.supplies = plan.supplies.filter(item => item.material_id !== id);
};
const openEstimate = (id) => request(async () => {
    estimate.value = id ? (await axios.get(`${path}/estimates/${id}`)).data.data : null;
});
const freezeEstimate = () => request(async () => {
    estimate.value = (await axios.post(`${path}/estimates`, props.settings)).data.data;
    await fetchEstimates();
});

onMounted(async () => {
    await request(() => Promise.all([fetchCatalog(), fetchPlan(), fetchEstimates()]));
    loading.value = false;
    await nextTick();
    focusedPartSelect?.focus();
});
</script>
