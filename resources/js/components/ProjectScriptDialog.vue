<template>
    <dialog ref="dialog" class="fixed inset-0 m-auto h-[92dvh] max-h-none w-[96vw] max-w-none overflow-hidden rounded-2xl border border-stone-200 bg-stone-50 p-0 text-stone-800 shadow-2xl backdrop:bg-stone-950/60" aria-labelledby="script-title" @cancel.prevent="close" @keydown.stop>
        <div class="flex h-full min-h-0 flex-col">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-white px-5 py-4">
                <div><h2 id="script-title" class="text-lg font-semibold">Сценарий изделия <span class="ml-2 rounded-md bg-emerald-50 px-2 py-1 text-xs text-emerald-800">JavaScript</span></h2><p class="mt-1 text-xs text-stone-500">Код → предпросмотр → отдельный узел в сборке</p></div>
                <button class="icon-button" type="button" aria-label="Закрыть сценарий" :disabled="busy" @click="close">×</button>
            </header>
            <div class="grid min-h-0 flex-1 overflow-auto lg:grid-cols-2">
                <section class="flex min-h-[420px] flex-col gap-3 border-r border-stone-200 p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="text-xs text-stone-600">Пример <select class="ml-2 rounded-lg border border-stone-300 bg-white p-2" :disabled="busy" @change="loadExample($event)"><option value="">Выберите…</option><option value="bench">Скамья с повторяющимися ножками</option><option value="machining">Панель со сверлением</option><option value="joinery">Параметры, шип–паз и копии узла</option></select></label>
                        <span class="ml-auto text-xs text-stone-500">{{ dirty ? 'Есть несохранённые изменения' : 'Код сохранён' }}</span>
                    </div>
                    <div v-if="localDraft" class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900" role="status">Есть несохранённый локальный сценарий от {{ new Date(localDraft.savedAt).toLocaleString('ru') }}.<div class="mt-2 flex gap-3"><button type="button" class="underline" :disabled="busy" @click="restoreLocalDraft">Восстановить</button><button type="button" class="underline" :disabled="busy" @click="discardLocalDraft">Удалить черновик</button></div></div>
                    <p v-if="draftWarning" role="alert" class="text-xs text-amber-800">{{ draftWarning }}</p>
                    <label for="script-source" class="sr-only">Код сценария на JavaScript</label>
                    <textarea id="script-source" ref="editor" v-model="source" :disabled="busy || Boolean(localDraft)" spellcheck="false" autocapitalize="off" autocomplete="off" class="min-h-72 flex-1 resize-none rounded-xl border border-emerald-900 bg-emerald-950 p-4 font-mono text-sm leading-6 text-emerald-50 outline-offset-2 selection:bg-emerald-700" @keydown.tab.prevent="indent" @keydown.ctrl.enter.prevent="preview" @keydown.meta.enter.prevent="preview"></textarea>
                    <details class="rounded-xl border border-stone-200 bg-white p-3 text-xs leading-6">
                        <summary class="font-semibold text-emerald-900">Команды и правила координат</summary>
                        <div class="mt-2 space-y-2">
                            <p><code>part(name, { length, width, thickness, material })</code> — заготовка. Длина по X, ширина по Y, толщина по Z.</p>
                            <p><code>group(name, parent?)</code> — узел; <code>group.add(part, options)</code> или <code>add(part, options)</code> — экземпляр.</p>
                            <p><code>options = { position: [x, y, z], rotation: [x, y, z] }</code>. Позиция — центр детали в мм, углы — градусы. Z направлена вверх. В узле координаты локальные; при применении пересчитываются в мировые.</p>
                            <p><code>instance.move([x,y,z]).rotate([x,y,z])</code> — смещение и дополнительный поворот в осях родителя. Те же методы есть у узла; поворот узла — вокруг его начала координат.</p>
                            <p><code>instance.alignTo(target, { face: "start", targetFace: "end", gap: 0, offset: [0,0,0] })</code> совмещает центры граней и разворачивает нормали навстречу. Gap — зазор по нормали целевой грани, offset — мировое смещение. Отрицательный gap задаёт посадку.</p>
                            <p><code>repeat(part, { count: 5, step: [80,0,0], position: [0,0,0] })</code> создаёт 5 экземпляров. Для экземпляра или узла count — число дополнительных копий. <code>mirror(instanceOrGroup, { axis: "x", origin: [0,0,0] })</code> создаёт отражённую копию относительно мировой плоскости; внутренние соединения копируются.</p>
                            <p><code>parameter("width", { label: "Ширина", default: 800, min: 300, max: 2000, step: 10 })</code> — числовое поле. Чтобы появились поля, запустите первый предпросмотр.</p>
                            <p><code>connect(first, second, { type: "mortise_tenon", primary_face: "end", secondary_face: "start", tenon_length: 20, tenon_width: 30, tenon_thickness: 10 }).generateMachining()</code>. Типы: butt, dowel, half_lap, mortise_tenon. Для шипа первый экземпляр — шип, второй — паз; gap = -tenon_length. Для вполдерева gap — отрицательная сумма глубин двух выборок. Без generateMachining сохраняется только описание соединения.</p>
                            <p>Обработка проверяет положение исходных плоских граней и направления выборок; это не проверка прочности или полной собираемости изделия. Зазоры посадки и произвольный поворот профиля шипа пока не поддерживаются.</p>
                            <p>Короткие методы: <code>board.drill({ diameter: 8 }).groove({ depth: 6 }).roundover({ radius: 3 })</code>, <code>crossCut()</code>, <code>ripCut()</code>, <code>plungeRoute()</code>. Все возвращают заготовку; параметры можно задать явно через operation.</p>
                            <p><code>part.operation(type, parameters)</code> — обработка всех экземпляров заготовки. Типы: <code>cross_cut, rip_cut, groove, edge_roundover, plunge_route, drill</code>. Параметры совпадают с API инструментов.</p>
                            <p><code>log(value)</code> — сообщение. Доступны переменные, функции, циклы и Math. Импорт библиотек, сеть и доступ к странице недоступны.</p>
                            <p>Лимиты: 3 секунды, 99 узлов/заготовок/экземпляров, 30 соединений, 50 операций на заготовку, 100 операций всего, включая обработку соединений.</p>
                        </div>
                    </details>
                </section>
                <section class="flex min-h-[420px] flex-col gap-3 p-4">
                    <div v-if="parameterDefinitions.length" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                        <div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-semibold">Параметры изделия</h3><button type="button" class="text-xs text-emerald-800 underline" :disabled="busy" @click="parameterValues = {}">Сбросить</button></div>
                        <div class="grid max-h-48 grid-cols-2 gap-3 overflow-auto"><label v-for="item in parameterDefinitions" :key="item.name" class="flex flex-col gap-1 text-xs">{{ item.label }}<input v-model.number="parameterValues[item.name]" :placeholder="String(item.default)" :disabled="busy" type="number" :min="item.min" :max="item.max" :step="item.step" class="rounded-lg border border-emerald-200 bg-white p-2 text-sm"></label></div>
                        <p class="mt-2 text-xs text-emerald-800">После изменения нажмите «Предпросмотр».</p>
                    </div>
                    <div class="flex items-center justify-between text-sm"><h3 class="font-semibold">Предпросмотр результата</h3><span class="text-xs text-stone-500">{{ previewParts.length }} заготовок · {{ instanceCount }} деталей</span></div>
                    <div class="relative min-h-72 flex-1 overflow-hidden rounded-xl border border-stone-200 bg-stone-100">
                        <ThreeViewport v-if="previewParts.length" :key="previewVersion" :parts="previewParts" :connections="previewConnections" :locked-instance-ids="previewInstanceIds" />
                        <div v-else class="absolute inset-0 grid place-items-center p-8 text-center text-sm leading-6 text-stone-500">Запустите предпросмотр.<br>Существующее изделие не изменится.</div>
                    </div>
                    <p v-if="error" role="alert" class="max-h-36 overflow-auto whitespace-pre-wrap rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
                    <p v-if="notice" role="status" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900">{{ notice }}</p>
                    <pre v-if="logs.length" class="max-h-28 overflow-auto whitespace-pre-wrap rounded-xl bg-stone-200/50 p-3 text-xs">{{ logs.join('\n') }}</pre>
                    <p class="text-xs leading-5 text-stone-500">{{ hasResult ? 'Применение заменит предыдущий результат этого сценария. Ручные изменения защищены от перезаписи.' : 'Применение создаст новый узел Script result. Остальные детали останутся на месте.' }} Изменение кода сбрасывает предпросмотр.</p>
                </section>
            </div>
            <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-200 bg-white px-5 py-4">
                <div class="flex flex-wrap gap-2"><button class="button-secondary button-compact" type="button" :disabled="busy" @click="save">Сохранить код</button><button v-if="hasResult" class="button-secondary button-compact" type="button" :disabled="busy" @click="detach">Отвязать результат</button></div>
                <div class="flex gap-2"><button v-if="running" class="button-secondary button-compact" type="button" @click="abortController?.abort()">Остановить</button><button class="button-secondary button-compact" type="button" :disabled="busy" title="Ctrl+Enter" @click="preview">{{ busy ? 'Обработка…' : 'Предпросмотр' }}</button><button class="button-primary button-compact" type="button" :disabled="busy || !validatedPayload" @click="apply">Применить в сборке</button></div>
            </footer>
        </div>
    </dialog>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import ThreeViewport from './ThreeViewport.vue';
import { joineryScript, machiningScript, starterScript } from '../editor/scriptApi.js';
import { runScript } from '../editor/scriptSandbox.js';
import { draftKey, readDraft, writeDraft, removeDraft } from '../editor/draftStorage.js';
import { useAuthStore } from '../stores/auth.js';

const props = defineProps({ projectId: { type: Number, required: true }, applyScript: { type: Function, required: true } });
const emit = defineEmits(['close', 'detach']);
const dialog = ref(null);
const editor = ref(null);
const source = ref('');
const savedSource = ref('');
const revision = ref(1);
const hasResult = ref(false);
const busy = ref(true);
const running = ref(false);
const error = ref('');
const notice = ref('');
const logs = ref([]);
const previewParts = ref([]);
const previewConnections = ref([]);
const parameterDefinitions = ref([]);
const parameterValues = ref({});
const savedValues = ref('{}');
const previewVersion = ref(0);
const validatedPayload = ref(null);
const dirty = computed(() => source.value !== savedSource.value || JSON.stringify(parameterValues.value) !== savedValues.value);
const previewInstanceIds = computed(() => previewParts.value.flatMap(part => part.instances.map(instance => instance.id)));
const instanceCount = computed(() => previewInstanceIds.value.length);
let abortController;
let previousFocus;
let recoveryReady = false;
const localDraft = ref(null), draftWarning = ref('');
const auth = useAuthStore();
const storageKey = () => draftKey(auth.user?.id ?? 'session', props.projectId, 'script');
const discardLocalDraft = () => { removeDraft(localStorage, storageKey()); localDraft.value = null; };
const restoreLocalDraft = () => {
    if (localDraft.value.baseTag !== revision.value && !window.confirm('Сохранённый проект изменился. Загрузить локальный код для просмотра? Сборка не изменится до применения.')) return;
    source.value = localDraft.value.value.source;
    parameterValues.value = localDraft.value.value.parameterValues ?? {};
    localDraft.value = null;
};
const persistLocalDraft = () => {
    if (!recoveryReady || localDraft.value) return;
    if (!dirty.value) { removeDraft(localStorage, storageKey()); return; }
    if (!writeDraft(localStorage, storageKey(), { source: source.value, parameterValues: parameterValues.value }, revision.value)) draftWarning.value = 'Не удалось сохранить локальный черновик. Сохраните код на сервере.';
};
watch([source, parameterValues], persistLocalDraft, { deep: true });

const invalidate = () => { validatedPayload.value = null; previewParts.value = []; previewConnections.value = []; notice.value = ''; };
watch(source, () => { invalidate(); parameterDefinitions.value = []; });
watch(parameterValues, invalidate, { deep: true });
const reportError = (failure) => {
    error.value = failure.response?.data?.errors
        ? Object.entries(failure.response.data.errors).map(([field, messages]) => `${field}: ${messages.join(' ')}`).join('\n')
        : failure.response?.status === 409 ? 'Проект изменился. Запустите предпросмотр заново; для сохранения кода повторите действие.' : failure.response?.data?.message ?? failure.message;
    if (failure.response?.status === 409) validatedPayload.value = null;
    if (failure.response?.data?.current_revision) revision.value = failure.response.data.current_revision;
};
const perform = async (action) => {
    if (busy.value) return;
    if (localDraft.value) { error.value = 'Сначала восстановите или удалите найденный локальный черновик.'; return; }
    busy.value = true;
    error.value = '';
    notice.value = '';
    try { await action(); } catch (failure) { reportError(failure); } finally { busy.value = false; }
};
const save = () => perform(async () => {
    const { data } = await axios.put(`/projects/${props.projectId}/script`, { source: source.value, parameter_values: parameterValues.value, expected_revision: revision.value });
    revision.value = data.data.revision;
    savedSource.value = source.value;
    savedValues.value = JSON.stringify(parameterValues.value);
    discardLocalDraft();
    validatedPayload.value = null;
    notice.value = 'Код сохранён. Сборка не изменена.';
});
const preview = () => perform(async () => {
    validatedPayload.value = null;
    previewParts.value = [];
    logs.value = [];
    abortController = new AbortController();
    running.value = true;
    let result;
    try { result = await runScript(source.value, { signal: abortController.signal, values: parameterValues.value }); } finally { running.value = false; }
    parameterDefinitions.value = (result.parameters ?? []).slice(0, 30).filter(item => typeof item.name === 'string' && /^[A-Za-z][A-Za-z0-9_]*$/.test(item.name) && ['default', 'min', 'max', 'step', 'value'].every(key => Number.isFinite(item[key])));
    parameterValues.value = Object.fromEntries(parameterDefinitions.value.map(item => [item.name, item.value]));
    const { data: current } = await axios.get(`/projects/${props.projectId}/script`);
    revision.value = current.data.revision;
    hasResult.value = current.data.has_result;
    const payload = { source: source.value, commands: result.commands, connections: result.connections ?? [], parameter_values: parameterValues.value, expected_revision: revision.value };
    const { data } = await axios.post(`/projects/${props.projectId}/script/run`, { ...payload, dry_run: true });
    previewParts.value = data.data.parts;
    previewConnections.value = data.data.connections ?? [];
    previewVersion.value++;
    logs.value = result.logs.slice(0, 50).map(value => String(value).slice(0, 500));
    validatedPayload.value = payload;
    notice.value = 'Проверка пройдена. Результат ещё не применён.';
});
const apply = () => perform(async () => {
    if (!validatedPayload.value) return;
    const result = await props.applyScript(JSON.parse(JSON.stringify(validatedPayload.value)));
    revision.value = result.revision;
    savedSource.value = source.value;
    savedValues.value = JSON.stringify(parameterValues.value);
    discardLocalDraft();
    hasResult.value = result.root_group_id !== null;
    validatedPayload.value = null;
    notice.value = 'Применено. Закройте окно, чтобы продолжить работу в сборке. Ctrl+Z отменит запуск целиком.';
});
const detach = () => {
    if (!window.confirm('Сохранить результат как обычные детали и отвязать от сценария? Следующий запуск создаст новый узел. История отмены будет очищена.')) return;
    perform(async () => {
        const { data } = await axios.post(`/projects/${props.projectId}/script/detach`, { source: source.value, parameter_values: parameterValues.value, expected_revision: revision.value });
        revision.value = data.data.revision;
        savedSource.value = source.value;
        savedValues.value = JSON.stringify(parameterValues.value);
        discardLocalDraft();
        hasResult.value = false;
        validatedPayload.value = null;
        emit('detach');
        notice.value = 'Детали сохранены в сборке и больше не связаны со сценарием.';
    });
};
const close = () => {
    if (busy.value || (dirty.value && !window.confirm('Закрыть без сохранения изменений кода?'))) return;
    dialog.value.close();
    emit('close');
};
const loadExample = (event) => {
    if (localDraft.value) { event.target.value = ''; return; }
    if (event.target.value && (!source.value || window.confirm('Заменить текст сценария примером?'))) {
        source.value = { bench: starterScript, machining: machiningScript, joinery: joineryScript }[event.target.value];
        parameterValues.value = {};
    }
    event.target.value = '';
};
const indent = (event) => {
    const input = event.target;
    input.setRangeText('    ', input.selectionStart, input.selectionEnd, 'end');
    source.value = input.value;
};
onMounted(async () => {
    previousFocus = document.activeElement;
    dialog.value.showModal();
    try {
        const { data } = await axios.get(`/projects/${props.projectId}/script`);
        source.value = data.data.source ?? starterScript;
        savedSource.value = data.data.source ?? '';
        revision.value = data.data.revision;
        hasResult.value = data.data.has_result;
        parameterValues.value = Object.fromEntries(Object.entries(data.data.parameter_values ?? {}).map(([key, value]) => [key, Number(value)]));
        savedValues.value = JSON.stringify(parameterValues.value);
        const record = readDraft(localStorage, storageKey());
        if (typeof record?.value?.source === 'string') localDraft.value = record;
        await nextTick();
        recoveryReady = true;
    } catch (failure) { reportError(failure); } finally { busy.value = false; }
    await nextTick();
    editor.value?.focus();
});
onBeforeUnmount(() => { persistLocalDraft(); abortController?.abort(); previousFocus?.focus(); });
</script>
