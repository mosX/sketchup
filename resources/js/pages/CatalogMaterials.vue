<template>
    <AppLayout>
        <div class="mx-auto max-w-6xl px-5 py-10 lg:px-8">
            <h1 class="text-3xl font-semibold tracking-tight">Материалы</h1>
            <p class="mt-2 text-sm text-stone-600">Доски, листы и расходники для всех ваших проектов.</p>

            <p v-if="error" role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>

            <div class="mt-6 grid items-start gap-5 lg:grid-cols-[21rem_minmax(0,1fr)]">
                <form class="grid gap-3 rounded-xl border border-stone-200 bg-white p-4" @submit.prevent="save">
                    <h2 class="font-semibold">{{ editingId ? 'Редактирование позиции' : 'Новая позиция' }}</h2>
                    <label class="field-label">Название<input v-model.trim="form.name" class="field-input" maxlength="255" required placeholder="Сосна или шуруп 3,4 × 25 мм"></label>
                    <label class="field-label">Тип
                        <select v-model="form.kind" class="field-input" :disabled="editingId !== null">
                            <option v-for="(label, kind) in materialKindLabels" :key="kind" :value="kind">{{ label }}</option>
                        </select>
                    </label>
                    <div v-if="form.kind !== 'consumable'" class="grid grid-cols-3 gap-2">
                        <label v-for="dimension in dimensions" :key="dimension.key" class="field-label">{{ dimension.label }}
                            <input v-model.number="form[dimension.key]" class="field-input min-w-0 px-2" type="number" min="0.01" max="50000" step="0.01" required>
                        </label>
                    </div>
                    <div v-else class="grid grid-cols-2 gap-2">
                        <label class="field-label">Единица<input v-model.trim="form.unit" class="field-input" maxlength="24" required placeholder="шт., мл, г"></label>
                        <label class="field-label">Кол-во в упаковке<input v-model.number="form.pack_quantity" class="field-input" type="number" min="0.001" max="1000000" step="0.001" required></label>
                    </div>
                    <label class="field-label">{{ form.kind === 'consumable' ? 'Цена упаковки' : 'Цена за 1 шт.' }}, грн<input v-model.number="form.price" class="field-input" type="number" min="0" max="100000000" step="0.01" placeholder="Можно указать позже"></label>
                    <div class="flex flex-wrap gap-2">
                        <button class="button-primary" :disabled="busy" type="submit">{{ editingId ? 'Сохранить' : 'Добавить' }}</button>
                        <button v-if="editingId" class="button-secondary" type="button" @click="reset">Отмена</button>
                    </div>
                </form>

                <section class="overflow-hidden rounded-xl border border-stone-200 bg-white">
                    <div class="border-b border-stone-100 p-4">
                        <label class="field-label">Поиск по каталогу<input v-model.trim="search" class="field-input" type="search" placeholder="Название материала"></label>
                    </div>
                    <p v-if="loading" class="p-4 text-sm text-stone-500">Загружаем каталог…</p>
                    <p v-else-if="!filteredMaterials.length" class="p-4 text-sm text-stone-500">{{ search ? 'Ничего не найдено.' : 'Добавьте первую позицию.' }}</p>
                    <ul v-else class="divide-y divide-stone-100">
                        <li v-for="material in filteredMaterials" :key="material.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <strong class="block break-words text-sm">{{ material.name }}</strong>
                                <small class="text-stone-500">{{ materialKindLabels[material.kind] }} · {{ materialSize(material) }}</small>
                            </div>
                            <div class="flex items-center gap-3">
                                <strong class="text-sm font-semibold tabular-nums">{{ materialCost(material.price === null ? null : Number(material.price) * 100) }}</strong>
                                <button class="button-secondary button-compact" type="button" :disabled="busy" @click="edit(material)">Изменить</button>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import axios from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';
import { materialCost, materialKindLabels, materialSize } from '../editor/materialCatalog.js';

const dimensions = [
    { key: 'width_mm', label: 'Ширина, мм' },
    { key: 'thickness_mm', label: 'Толщина, мм' },
    { key: 'length_mm', label: 'Длина, мм' },
];
const emptyForm = () => ({ name: '', kind: 'board', length_mm: '', width_mm: '', thickness_mm: '', unit: 'шт.', pack_quantity: 1, price: '' });
const form = reactive(emptyForm());
const materials = ref([]);
const search = ref('');
const editingId = ref(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const filteredMaterials = computed(() => materials.value.filter(item => item.name.toLocaleLowerCase().includes(search.value.toLocaleLowerCase())));
const reset = () => {
    editingId.value = null;
    Object.assign(form, emptyForm());
};
const edit = (material) => {
    editingId.value = material.id;
    Object.assign(form, material, { price: material.price ?? '' });
};

const load = async () => {
    try {
        materials.value = (await axios.get('/v1/materials')).data.data;
    } catch {
        error.value = 'Не удалось загрузить каталог.';
    } finally {
        loading.value = false;
    }
};

const save = async () => {
    busy.value = true;
    error.value = '';
    try {
        const payload = { ...form, price: form.price === '' ? null : form.price };
        if (editingId.value) {
            await axios.put(`/v1/materials/${editingId.value}`, payload);
        } else {
            await axios.post('/v1/materials', payload);
        }
        reset();
        await load();
    } catch (requestError) {
        error.value = requestError.response?.data?.message ?? 'Не удалось сохранить позицию.';
    } finally {
        busy.value = false;
    }
};

onMounted(load);
</script>
