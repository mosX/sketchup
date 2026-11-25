<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <label
            v-for="item in definitions"
            :key="item.name"
            class="grid content-start gap-1.5 text-xs text-stone-700"
            :class="{ 'sm:col-span-2': item.kind === 'screw' }"
        >
            <span>{{ item.label }}<template v-if="item.unit">, {{ item.unit }}</template></span>
            <select
                v-if="item.kind === 'screw'"
                class="w-full rounded-lg border border-stone-300 bg-white px-2 py-1.5"
                :value="values[item.name] ?? item.default"
                :disabled="disabled"
                @change="$emit('change', item, Number($event.target.value))"
            >
                <option value="0">Без шурупов</option>
                <option v-if="missingScrew(item)" :value="values[item.name] ?? item.default" disabled>Сохранённый шуруп недоступен</option>
                <option v-for="screw in screws" :key="screw.id" :value="screw.id">
                    {{ screw.name }} · Ø{{ Number(screw.fastener_diameter) }} × {{ Number(screw.fastener_length) }} мм
                </option>
            </select>
            <input
                v-else
                v-scrub-number
                class="min-w-0 rounded-lg border border-stone-300 bg-white px-2 py-1.5 tabular-nums"
                type="number"
                :value="values[item.name] ?? item.default"
                :min="item.min"
                :max="item.max"
                :step="item.step"
                :disabled="disabled"
                @input="$emit('change', item, $event.target.value === '' ? '' : $event.target.valueAsNumber)"
            >
        </label>
    </div>
</template>

<script setup>
import { vScrubNumber } from '../editor/scrubNumber.js';

const props = defineProps({
    definitions: { type: Array, required: true },
    values: { type: Object, required: true },
    screws: { type: Array, required: true },
    disabled: Boolean,
});
defineEmits(['change']);

const missingScrew = item => {
    const id = props.values[item.name] ?? item.default;
    return id > 0 && !props.screws.some(screw => Number(screw.id) === id);
};
</script>
