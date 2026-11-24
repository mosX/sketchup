<template>
    <div class="grid gap-3">
        <article v-for="(cutting, index) in groups" :key="cutting.catalog_material_id ?? index" class="rounded-xl border border-stone-200 p-3">
            <header class="flex flex-wrap items-center justify-between gap-2">
                <strong class="text-sm">{{ cutting.material }} · {{ cutting.section.width }} × {{ cutting.section.thickness }} мм</strong>
                <span class="text-xs text-stone-600">{{ cutting.stock_length }} мм · {{ cutting.bars.length }} шт.</span>
            </header>

            <div v-for="board in cutting.bars" :key="board.number" class="workshop-keep mt-3 rounded-lg border border-stone-200 p-3">
                <h4 class="text-xs font-semibold">Доска №{{ board.number }}</h4>
                <p v-if="board.rip_cuts?.length" class="mt-1 text-xs text-stone-700">
                    <strong>1. Вдоль.</strong> Положения линии реза от одной базовой кромки:
                    {{ board.rip_cuts.map(cut => format(cut.position)).join(' → ') }} мм. Пропил со стороны большей координаты.
                </p>
                <p class="mt-2 text-xs font-semibold">{{ board.rip_cuts?.length ? '2. Поперёк каждой полосы' : 'Поперечные резы' }}</p>
                <p class="text-xs text-stone-600">Координаты от исходного торца. Пропил сразу после конца детали.</p>

                <div v-for="strip in strips(board, cutting)" :key="strip.number" class="mt-2 text-xs">
                    <span v-if="board.rip_cuts?.length" class="text-stone-600">Полоса {{ strip.number }} · {{ format(strip.width) }} мм · от {{ format(strip.offset) }} мм</span>
                    <div class="relative mt-1 h-5 overflow-hidden rounded border border-stone-400" aria-hidden="true">
                        <div v-for="cut in strip.cuts" :key="`${cut.part_id}-${cut.piece}`" class="absolute inset-y-0 overflow-hidden border-x border-emerald-950 bg-emerald-200 text-center leading-4 text-emerald-950" :style="cutStyle(cut, cutting.stock_length)">
                            {{ partWorkshopCode(cut.part_id) }}
                        </div>
                    </div>
                    <ul class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-stone-700">
                        <li v-for="cut in strip.cuts" :key="`${cut.part_id}-${cut.piece}`">
                            <strong>{{ partWorkshopCode(cut.part_id) }}</strong> · {{ format(cut.length) }} мм <span class="text-stone-500">от {{ format(cut.start) }} до {{ format(Number(cut.start) + Number(cut.length)) }} мм</span>
                        </li>
                    </ul>
                </div>
                <p v-if="board.offcuts?.length" class="mt-2 text-xs text-stone-600">Остатки: {{ board.offcuts.map(offcut => `${format(offcut.length)} × ${format(offcut.width)} мм`).join(' · ') }}</p>
                <p v-else-if="!board.offcuts && board.waste_length > 0" class="mt-2 text-xs text-stone-600">Остаток: {{ format(board.waste_length) }} мм</p>
            </div>
            <p v-if="cutting.oversized.length" class="mt-2 text-xs text-red-700">Не помещаются: {{ [...new Set(cutting.oversized.map(part => partWorkshopCode(part.part_id) + ' ' + part.name))].join(', ') }}</p>
        </article>
    </div>
</template>

<script setup>
import { partWorkshopCode } from '../editor/workshopPlan.js';

defineProps({ groups: { type: Array, required: true } });
const format = new Intl.NumberFormat('ru', { maximumFractionDigits: 4 }).format;
const strips = (board, group) => board.strips ?? [{ number: 1, width: group.section.width, offset: 0, cuts: board.cuts }];
const cutStyle = (cut, length) => ({ left: `${cut.start / length * 100}%`, width: `${cut.length / length * 100}%` });
</script>
