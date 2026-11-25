<template>
    <article class="workshop-print hidden text-stone-950">
        <header class="mb-7 border-b-2 border-stone-800 pb-4">
            <p class="text-xs font-semibold uppercase tracking-wide">Карты для мастерской</p>
            <h1 class="mt-2 text-3xl font-semibold">{{ projectName }}</h1>
            <p class="mt-2 text-xs">Ревизия {{ revision }} · Все размеры в мм · Карты раскроя предварительные</p>
        </header>

        <section class="mb-7">
            <h2 class="mb-2 text-xl font-semibold">Общий вид и спецификация</h2>
            <WorkshopAssemblyDiagram :parts="parts" :groups="groups" label="Схема изделия по исходным заготовкам"/>
            <p class="mt-1 text-xs text-stone-600">Схемы показывают исходные заготовки в сборке. Точные операции приведены в карточках деталей.</p>
            <table class="mt-3 w-full text-left text-xs">
                <thead><tr class="border-b-2"><th class="py-1 pr-2">Номер</th><th>Заготовка</th><th>Габариты</th><th>Материал</th><th>Шт.</th></tr></thead>
                <tbody>
                    <tr v-for="part in report.bill_of_materials" :key="part.part_id" class="border-b border-stone-300">
                        <th class="py-1 pr-2">{{ partWorkshopCode(part.part_id) }}</th><td>{{ part.name }}</td>
                        <td class="whitespace-nowrap">{{ size(part.dimensions) }}</td><td>{{ stockByPart.get(part.part_id) ?? part.material }}</td><td>{{ part.quantity }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-if="nodes.length" class="mb-7">
            <h2 class="mb-3 text-xl font-semibold">Схемы узлов</h2>
            <div v-for="node in nodes" :key="node.id" class="workshop-keep mb-4">
                <h3 class="mb-1 text-base font-semibold">{{ node.name }}</h3>
                <WorkshopAssemblyDiagram :parts="node.parts" :groups="groups" :label="node.name"/>
                <p class="mt-1 text-xs">{{ node.parts.map(part => `${partWorkshopCode(part.id)} ×${part.instances.length}`).join(' · ') }}</p>
            </div>
        </section>

        <section v-if="report.linear_cutting.length" class="mb-7">
            <h2 class="mb-2 text-xl font-semibold">Раскрой досок</h2>
            <p class="mb-2 text-xs">Пропил {{ report.assumptions.kerf_mm }} мм. Отступы с торцов по {{ report.assumptions.edge_margin_mm }} мм.</p>
            <BoardCuttingDiagram :groups="report.linear_cutting"/>
        </section>

        <section v-if="report.sheet_cutting.length" class="mb-7">
            <h2 class="mb-2 text-xl font-semibold">Раскрой листов</h2>
            <div v-for="(cutting, index) in report.sheet_cutting" :key="index" class="mb-3">
                <h3 class="text-sm font-semibold">{{ cutting.material }} · {{ cutting.stock.length }} × {{ cutting.stock.width }} × {{ cutting.thickness }} мм</h3>
                <table class="mt-1 w-full text-left text-xs">
                    <thead><tr class="border-b"><th>Лист</th><th>Деталь</th><th>Размер на листе</th><th>X / Y от угла</th></tr></thead>
                    <tbody>
                        <template v-for="sheet in cutting.sheets" :key="sheet.number">
                            <tr v-for="piece in sheet.placements" :key="`${piece.part_id}-${piece.piece}`" class="border-b border-stone-200">
                                <td>{{ sheet.number }}</td><td>{{ partWorkshopCode(piece.part_id) }}</td><td>{{ piece.length }} × {{ piece.width }}</td><td>{{ piece.x }} / {{ piece.y }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="report.costing?.issues?.length" class="mb-7">
            <h2 class="mb-2 text-base font-semibold">Заготовки без готовой карты раскроя</h2>
            <p class="text-xs">{{ report.costing.issues.map(issue => partWorkshopCode(issue.part_id) + ' ' + issue.name).join(' · ') }}</p>
        </section>

        <section>
            <h2 class="mb-2 text-xl font-semibold">Чертежи и карточки обработки</h2>
            <p class="mb-3 text-xs">База — локальные грани заготовки. Для U/V отсчёт начинается от кромок выбранной грани.</p>
            <p class="mb-3 text-xs">Сплошные линии показывают видимый контур детали. Серый пунктир — исходная заготовка. Номера на видах соответствуют порядку операций. Масштаб видов условный.</p>
            <div v-if="hasFaceCoordinates" class="mb-4 text-xs">
                <strong>Начало координат на каждой грани</strong>
                <div>Верхняя пласть — U от начального торца, V от левой кромки. Нижняя — U от начального торца, V от правой кромки.</div>
                <div>Левая кромка — U от начального торца. Правая — U от конечного торца. V от нижней пласти на обеих кромках.</div>
                <div>Начальный торец — U от правой кромки. Конечный — U от левой кромки. V от нижней пласти на обоих торцах.</div>
            </div>
            <article v-for="card in cards" :key="card.id" class="mb-3 rounded border border-stone-400 p-3">
                <h3 class="workshop-keep text-sm font-semibold">{{ partWorkshopCode(card.id) }} · {{ card.name }} · ×{{ card.quantity }}</h3>
                <p class="mt-1 text-xs">Заготовка {{ size(card.dimensions) }} мм · {{ stockByPart.get(card.id) ?? card.material ?? 'Материал не указан' }} · волокна {{ card.grain_axis === 'width' ? 'по ширине' : 'по длине' }}</p>
                <p v-if="card.mirrored" class="mt-1 text-xs">Зеркальных экземпляров: {{ card.mirrored }}. Для них нужна зеркальная разметка операций.</p>
                <WorkshopPartDrawing v-if="drawings.has(card.id)" :drawing="drawings.get(card.id)" class="mt-2"/>
                <ol v-if="card.operations.length" class="mt-2 list-decimal space-y-1 pl-4 text-xs">
                    <li v-for="(operation, index) in card.operations" :key="operation.id ?? index" class="workshop-keep">
                        <strong>{{ operationLabel(operation) }}</strong> · {{ workshopOperationDetails(operation, card.dimensions.thickness) }}
                    </li>
                </ol>
                <p v-else class="mt-2 text-xs text-stone-600">Дополнительной обработки нет.</p>
            </article>
        </section>
    </article>
</template>

<script setup>
import { computed } from 'vue';
import BoardCuttingDiagram from './BoardCuttingDiagram.vue';
import WorkshopAssemblyDiagram from './WorkshopAssemblyDiagram.vue';
import WorkshopPartDrawing from './WorkshopPartDrawing.vue';
import { descendantGroupIds } from '../editor/assemblyTree.js';
import { operationLabel } from '../editor/operations/catalog.js';
import { partWorkshopCode, workshopOperationDetails, workshopPartOperations } from '../editor/workshopPlan.js';

const props = defineProps({
    projectName: { type: String, required: true },
    revision: { type: Number, required: true },
    parts: { type: Array, required: true },
    groups: { type: Array, required: true },
    report: { type: Object, required: true },
    drawings: { type: Map, required: true },
});

const size = (dimensions) => [dimensions.length, dimensions.width, dimensions.thickness].join(' × ');
const cards = computed(() => props.parts.filter(part => part.instances?.length).map(part => ({
    ...part,
    quantity: part.instances.length,
    mirrored: part.instances.filter(instance => instance.mirrored).length,
    operations: workshopPartOperations(part),
})));
const hasFaceCoordinates = computed(() => cards.value.some(card => card.operations.some(operation => ['groove', 'plunge_route', 'drill', 'pocket', 'jigsaw_cut', 'drill_row', 'pocket_screw', 'curve_route'].includes(operation.type))));
const stockByPart = computed(() => {
    const materials = new Map();
    for (const cutting of props.report.linear_cutting) {
        for (const bar of cutting.bars) {
            for (const cut of bar.cuts) materials.set(cut.part_id, cutting.material);
        }
    }
    for (const cutting of props.report.sheet_cutting) {
        for (const sheet of cutting.sheets) {
            for (const part of sheet.placements) materials.set(part.part_id, cutting.material);
        }
    }
    return materials;
});
const nodes = computed(() => props.groups.flatMap(group => {
    const ids = new Set(descendantGroupIds(props.groups, group.id));
    const parts = props.parts.flatMap(part => {
        const instances = part.instances?.filter(instance => ids.has(instance.assembly_group_id)) ?? [];
        return instances.length ? [{ ...part, instances }] : [];
    });
    return parts.length ? [{ id: group.id, name: group.name, parts }] : [];
}));
</script>
