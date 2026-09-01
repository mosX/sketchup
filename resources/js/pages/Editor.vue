<template>
    <div class="editor-shell">
        <header class="editor-header">
            <router-link :to="{ name: 'projects' }" class="editor-logo" aria-label="К списку проектов">
                <span class="brand-mark brand-mark-small">W</span>
            </router-link>
            <div class="min-w-0 flex-1">
                <input v-model="projectName" class="editor-title" aria-label="Название проекта" @change="saveProject">
                <div class="flex items-center gap-2 text-[11px] text-stone-400">
                    <span class="save-dot" :class="{ 'save-dot-active': saveState === 'saving' }"></span>
                    {{ saveLabel }}
                </div>
            </div>

            <div class="editor-mode-switch" aria-label="Режим редактора">
                <button type="button" :class="{ active: editorMode === 'assembly' }" @click="editorMode = 'assembly'">Сборка</button>
                <button type="button" :class="{ active: editorMode === 'part' }" @click="openPart(activePart ?? projects.parts[0])">Заготовка</button>
            </div>

            <div class="flex items-center gap-1 rounded-xl border border-stone-200 bg-white p-1" aria-label="История изменений">
                <button
                    class="grid size-8 place-items-center rounded-lg text-base text-stone-500 transition hover:bg-stone-100 hover:text-emerald-900 disabled:cursor-not-allowed disabled:opacity-30"
                    type="button"
                    :disabled="!canUndo"
                    :title="`${undoLabel} · Ctrl+Z`"
                    aria-label="Отменить изменение"
                    @click="undoEditor"
                >↶</button>
                <button
                    class="grid size-8 place-items-center rounded-lg text-base text-stone-500 transition hover:bg-stone-100 hover:text-emerald-900 disabled:cursor-not-allowed disabled:opacity-30"
                    type="button"
                    :disabled="!canRedo"
                    :title="`${redoLabel} · Ctrl+Shift+Z`"
                    aria-label="Повторить изменение"
                    @click="redoEditor"
                >↷</button>
            </div>

            <button class="button-secondary button-compact" type="button" @click="openAiAccess">AI-доступ</button>
            <button class="button-primary button-compact" type="button" :disabled="saveState === 'saving'" @click="saveCurrent">
                {{ editorMode === 'part' ? 'Сохранить заготовку' : 'Сохранить проект' }}
            </button>
        </header>

        <div v-if="loading" class="grid flex-1 place-items-center bg-stone-100">
            <div class="text-center"><div class="loader mx-auto"></div><p class="mt-4 text-sm text-stone-500">Открываем мастерскую…</p></div>
        </div>

        <div v-else class="editor-workspace editor-workspace-parts">
            <aside class="parts-library">
                <div class="grid grid-cols-2 border-b border-stone-200 bg-stone-50 p-2">
                    <button class="rounded-lg px-2 py-2 text-xs font-semibold" :class="leftPanelMode === 'library' ? 'bg-white text-emerald-900 shadow-sm' : 'text-stone-400'" type="button" @click="leftPanelMode = 'library'">Заготовки</button>
                    <button class="rounded-lg px-2 py-2 text-xs font-semibold" :class="leftPanelMode === 'assembly' ? 'bg-white text-emerald-900 shadow-sm' : 'text-stone-400'" type="button" @click="leftPanelMode = 'assembly'; editorMode = 'assembly'">Структура</button>
                </div>

                <template v-if="leftPanelMode === 'library'">
                <div class="parts-library-header">
                    <div>
                        <span class="inspector-heading">Библиотека</span>
                        <p>{{ projects.parts.length }} {{ pluralParts(projects.parts.length) }}</p>
                    </div>
                    <button class="library-add-button" type="button" aria-label="Создать заготовку" @click="showCreatePart = true">＋</button>
                </div>

                <div v-if="projects.parts.length" class="parts-list">
                    <article v-for="part in projects.parts" :key="part.id" class="part-library-card" :class="{ active: selectedPartId === part.id }" @click="selectPart(part)">
                        <div class="part-library-thumb" aria-hidden="true"><span></span></div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <strong class="truncate">{{ part.name }}</strong>
                                <span class="part-count">×{{ part.instance_count }}</span>
                            </div>
                            <small>{{ formatDimensions(part) }}</small>
                            <small>{{ part.material || 'Материал не указан' }}</small>
                        </div>
                        <div class="part-card-actions">
                            <button type="button" @click.stop="openPart(part)">Редактировать</button>
                            <div class="instance-adder">
                                <input v-model.number="instanceQuantities[part.id]" type="number" min="1" max="20" aria-label="Количество экземпляров">
                                <button type="button" @click.stop="addInstances(part)">На сцену</button>
                            </div>
                        </div>
                    </article>
                </div>

                <button v-else class="library-empty" type="button" @click="showCreatePart = true">
                    <span>＋</span>
                    <strong>Создать первую заготовку</strong>
                    <small>Панель, брус или ножку по точным размерам</small>
                </button>
                </template>

                <template v-else>
                    <div class="parts-library-header">
                        <div class="min-w-0">
                            <span class="inspector-heading">Сборочные узлы</span>
                            <p class="truncate">{{ activeAssemblyGroup?.name ?? 'Всё изделие' }}</p>
                        </div>
                        <button class="library-add-button" type="button" title="Создать сборочный узел" aria-label="Создать сборочный узел" @click="createAssemblyGroup">＋</button>
                    </div>
                    <div class="border-b border-stone-100 px-3 pb-3">
                        <input v-model="assemblySearch" class="w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-xs outline-none focus:border-emerald-500" placeholder="Поиск по структуре">
                    </div>
                    <div v-if="assemblyRows.length" class="min-h-0 flex-1 overflow-y-auto p-2">
                        <template v-for="row in assemblyRows" :key="row.id">
                            <div
                                v-if="row.kind === 'group'"
                                class="group flex items-center gap-1 rounded-lg py-1 pr-1 text-xs hover:bg-stone-100"
                                :class="{ 'bg-emerald-50 text-emerald-900': selectedGroupId === row.group.id }"
                                :style="{ paddingLeft: `${6 + row.depth * 14}px` }"
                            >
                                <button class="grid size-6 shrink-0 place-items-center text-stone-400" type="button" @click="toggleGroupCollapsed(row.group.id)">{{ isGroupCollapsed(row.group.id) ? '›' : '⌄' }}</button>
                                <button class="min-w-0 flex-1 truncate text-left font-semibold" type="button" @click="selectAssemblyGroup(row.group)">▰ {{ row.group.name }}</button>
                                <button class="grid size-6 place-items-center rounded text-[11px] text-stone-400 hover:bg-white" type="button" :title="row.group.is_visible ? 'Скрыть узел' : 'Показать узел'" @click="toggleGroupVisibility(row.group)">{{ row.group.is_visible ? '◉' : '○' }}</button>
                                <button class="grid size-6 place-items-center rounded text-[11px] text-stone-400 hover:bg-white" type="button" :class="{ 'bg-amber-100 text-amber-700': isolatedGroupId === row.group.id }" title="Изолировать узел" @click="toggleGroupIsolation(row.group.id)">S</button>
                                <button class="grid size-6 place-items-center rounded text-stone-400 hover:bg-white" type="button" title="Открыть как отдельное изделие" @click="openAssemblyGroup(row.group.id)">↗</button>
                            </div>
                            <button
                                v-else
                                class="flex w-full items-center gap-2 rounded-lg py-1.5 pr-2 text-left text-xs text-stone-500 hover:bg-stone-100"
                                :class="{ 'bg-emerald-50 text-emerald-900': selectedInstanceId === row.instance.id }"
                                :style="{ paddingLeft: `${18 + row.depth * 14}px` }"
                                type="button"
                                @click="selectInstance(row.instance.id)"
                            ><span>◇</span><span class="min-w-0 flex-1 truncate">{{ row.part.name }}</span><small>#{{ row.instance.id }}</small></button>
                        </template>
                    </div>
                    <div v-else class="p-5 text-center text-xs leading-5 text-stone-400">
                        Создайте узел, например «Каркас» или «Крыша», затем назначьте ему детали.
                    </div>
                </template>
            </aside>

            <main class="relative min-h-0 min-w-0">
                <div class="view-tabs">
                    <template v-if="editorMode === 'assembly'">
                        <button class="view-tab" :class="{ 'view-tab-active': activeAssemblyGroupId === null }" type="button" @click="openAssemblyGroup(null)">Всё изделие</button>
                        <button v-for="group in assemblyBreadcrumbItems" :key="group.id" class="view-tab" :class="{ 'view-tab-active': group.id === activeAssemblyGroupId }" type="button" @click="openAssemblyGroup(group.id)">› {{ group.name }}</button>
                        <span v-if="isolatedGroup" class="ml-2 self-center rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-semibold text-amber-700">Solo: {{ isolatedGroup.name }}</span>
                    </template>
                    <button v-else class="view-tab view-tab-active" type="button">{{ activePart?.name || 'Заготовка' }}</button>
                    <button class="view-tab" type="button" disabled>Спереди</button>
                    <button class="view-tab" type="button" disabled>Сверху</button>
                </div>

                <ThreeViewport
                    :mode="editorMode"
                    :parts="projects.parts"
                    :active-part="partPreview"
                    :selected-instance-id="selectedInstanceId"
                    :selected-operation-id="selectedOperationId"
                    :visible-instance-ids="assemblyInstanceState.visibleInstanceIds"
                    :locked-instance-ids="assemblyInstanceState.lockedInstanceIds"
                    @select-instance="selectInstance"
                    @select-operation="selectedOperationId = $event"
                    @update-operation="updateOperationFromScene"
                    @commit-instance-transform="commitInstanceTransform"
                />

                <div class="status-bar">
                    <span>Сетка: 50 мм</span>
                    <span>Единицы: мм</span>
                    <span class="ml-auto">{{ editorMode === 'assembly' ? `${totalInstances} деталей на сцене` : 'Редактор заготовки' }}</span>
                </div>
            </main>

            <aside class="inspector-panel">
                <template v-if="editorMode === 'part' && partDraft">
                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">Заготовка</h2>
                            <span class="status-pill">Связанная</span>
                        </div>
                        <label class="editor-field mt-4">Название<input v-model="partDraft.name"></label>
                        <label class="editor-field mt-3">Материал<input v-model="partDraft.material" placeholder="Например, дуб"></label>
                    </div>

                    <div class="inspector-section">
                        <h2 class="inspector-heading">Размеры, мм</h2>
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <label class="dimension-field"><span>Длина</span><input v-model.number="partDraft.dimensions.length" type="number" min="1"></label>
                            <label class="dimension-field"><span>Ширина</span><input v-model.number="partDraft.dimensions.width" type="number" min="1"></label>
                            <label class="dimension-field"><span>Толщина</span><input v-model.number="partDraft.dimensions.thickness" type="number" min="1"></label>
                        </div>
                        <label class="editor-field mt-4">Направление волокон
                            <select v-model="partDraft.grain_axis"><option value="length">Вдоль длины</option><option value="width">Вдоль ширины</option></select>
                        </label>
                    </div>

                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">Операции</h2>
                            <span class="operation-badge">{{ partDraft.operations.length }}</span>
                        </div>

                        <div class="operation-add-grid">
                            <button type="button" @click="addOperation('cross_cut')"><span>╱</span>Поперечный рез</button>
                            <button type="button" @click="addOperation('rip_cut')"><span>↗</span>Продольный рез</button>
                            <button type="button" @click="addOperation('groove')"><span>⊔</span>Паз пилой</button>
                            <button type="button" @click="addOperation('edge_roundover')"><span>◜</span>Кромочный фрезер</button>
                            <button type="button" @click="addOperation('plunge_route')"><span>⌾</span>Погружной фрезер</button>
                            <button type="button" @click="addOperation('drill')"><span>●</span>Сверление</button>
                        </div>

                        <div class="operation-section-label">
                            <span>Текущие операции</span>
                            <b>{{ currentOperations.length }}</b>
                        </div>

                        <div v-if="currentOperations.length" class="operation-stack">
                            <article
                                v-for="(operation, index) in currentOperations"
                                :key="operation.id"
                                class="operation-row"
                                :class="{ active: operation.id === selectedOperationId, disabled: operation.enabled === false }"
                                @click="selectedOperationId = operation.id"
                            >
                                <input v-model="operation.enabled" type="checkbox" aria-label="Включить операцию" @click.stop>
                                <div><strong>{{ operationLabel(operation) }}</strong><small>{{ operationSummary(operation) }}</small></div>
                                <div class="operation-row-actions">
                                    <button type="button" title="Выше" :disabled="index === 0" @click.stop="moveOperation(operation.id, -1)">↑</button>
                                    <button type="button" title="Ниже" :disabled="index === currentOperations.length - 1" @click.stop="moveOperation(operation.id, 1)">↓</button>
                                    <button type="button" title="Удалить" @click.stop="removeOperation(operation.id)">×</button>
                                </div>
                            </article>
                        </div>
                        <p v-else class="operation-empty">Новых операций нет. Добавьте рез или выберите операцию из истории.</p>

                        <div v-if="historyOperations.length" class="operation-history">
                            <button class="operation-history-toggle" type="button" @click="historyExpanded = !historyExpanded">
                                <span><i>✓</i> История обработки</span>
                                <b>{{ historyOperations.length }}</b>
                                <small :class="{ expanded: historyExpanded }">⌄</small>
                            </button>
                            <div v-if="historyExpanded" class="operation-history-list">
                                <article v-for="operation in historyOperations" :key="operation.id" class="operation-history-row" :class="{ disabled: operation.enabled === false }" @click="editAppliedOperation(operation)">
                                    <span class="history-check">✓</span>
                                    <div><strong>{{ operationLabel(operation) }}</strong><small>{{ operationSummary(operation) }}</small></div>
                                    <button type="button" @click.stop="editAppliedOperation(operation)">Изменить</button>
                                </article>
                            </div>
                        </div>
                    </div>

                    <div v-if="selectedOperation" class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">{{ operationLabel(selectedOperation) }}</h2>
                            <span class="preview-legend"><i></i> плоскость реза</span>
                        </div>

                        <template v-if="selectedOperation.type === 'cross_cut'">
                            <label class="editor-field mt-4">Положение от начала, мм<input v-model.number="selectedOperation.position" type="number" min="0" :max="partDraft.dimensions.length"></label>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="cut-field"><span>Угол в плане</span><div><input v-model.number="selectedOperation.miter_angle" type="number" min="-60" max="60"><b>°</b></div></label>
                                <label class="cut-field"><span>Наклон диска</span><div><input v-model.number="selectedOperation.bevel_angle" type="number" min="-45" max="45"><b>°</b></div></label>
                            </div>
                            <label v-if="isFullDepthCut" class="editor-field mt-3">Оставить часть<select v-model="selectedOperation.keep_side"><option value="start">До линии реза</option><option value="end">После линии реза</option></select></label>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Глубина, мм<input v-model.number="selectedOperation.cut_depth" type="number" min="0.1" :max="partDraft.dimensions.thickness" step="0.1"></label>
                                <label class="editor-field">Направление<select v-model="selectedOperation.cut_direction"><option value="top_down">Сверху вниз</option><option value="bottom_up">Снизу вверх</option></select></label>
                            </div>
                            <label class="editor-field mt-3">Ширина пропила, мм<input v-model.number="selectedOperation.kerf" type="number" min="0" max="20" step="0.1"></label>
                            <p class="operation-tip">При полной глубине часть отделяется. При меньшей глубине остаётся узкий пропил шириной диска.</p>
                        </template>

                        <template v-else-if="selectedOperation.type === 'rip_cut'">
                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <label class="editor-field">Базовая кромка<select v-model="selectedOperation.reference_side"><option value="left">Левая</option><option value="right">Правая</option></select></label>
                                <label v-if="isFullDepthCut" class="editor-field">Что оставить<select v-model="selectedOperation.keep_side"><option value="opposite">Внутреннюю часть</option><option value="reference">Часть у базы</option></select></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Отступ в начале, мм<input v-model.number="selectedOperation.start_offset" type="number" min="0" :max="partDraft.dimensions.width"></label>
                                <label class="editor-field">Отступ в конце, мм<input v-model.number="selectedOperation.end_offset" type="number" min="0" :max="partDraft.dimensions.width"></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="cut-field"><span>Наклон диска</span><div><input v-model.number="selectedOperation.bevel_angle" type="number" min="-45" max="45"><b>°</b></div></label>
                                <label class="editor-field">Пропил, мм<input v-model.number="selectedOperation.kerf" type="number" min="0" max="20" step="0.1"></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Глубина, мм<input v-model.number="selectedOperation.cut_depth" type="number" min="0.1" :max="partDraft.dimensions.thickness" step="0.1"></label>
                                <label class="editor-field">Направление<select v-model="selectedOperation.cut_direction"><option value="top_down">Сверху вниз</option><option value="bottom_up">Снизу вверх</option></select></label>
                            </div>
                            <p class="operation-tip">Разные отступы в начале и конце образуют продольный рез под углом — например, коническую ножку.</p>
                        </template>

                        <template v-else-if="selectedOperation.type === 'groove'">
                            <label class="editor-field mt-4">Рабочая поверхность
                                <select :value="selectedOperation.face" @change="setGrooveFace($event.target.value)">
                                    <option value="top">Верхняя пласть</option>
                                    <option value="bottom">Нижняя пласть</option>
                                    <option value="left">Левая кромка</option>
                                    <option value="right">Правая кромка</option>
                                    <option value="start">Начальный торец</option>
                                    <option value="end">Конечный торец</option>
                                </select>
                            </label>
                            <p class="operation-tip">Кликните по другой грани прямо на заготовке, чтобы перенести паз на неё. Выбрано: {{ grooveFaceLabel }}.</p>
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <button class="button-secondary" type="button" @click="setGrooveDirection(0)">Вдоль</button>
                                <button class="button-secondary" type="button" @click="setGrooveDirection(90)">Поперёк</button>
                                <label class="cut-field"><span>Угол</span><div><input v-model.number="selectedOperation.path_angle" type="number" min="-180" max="180" step="1"><b>°</b></div></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Центр U, мм<input v-model.number="selectedOperation.center_u" type="number" min="0" :max="grooveSurfaceSize.u" step="0.1"></label>
                                <label class="editor-field">Центр V, мм<input v-model.number="selectedOperation.center_v" type="number" min="0" :max="grooveSurfaceSize.v" step="0.1"></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Длина, мм<input v-model.number="selectedOperation.groove_length" type="number" min="0.1" :max="Math.max(grooveSurfaceSize.u, grooveSurfaceSize.v)" step="0.1"></label>
                                <label class="editor-field">Ширина, мм<input v-model.number="selectedOperation.width" type="number" min="0.1" step="0.1"></label>
                            </div>
                            <label class="editor-field mt-3">Глубина, мм<input v-model.number="selectedOperation.depth" type="number" min="0.1" :max="grooveSurfaceSize.depth" step="0.1"></label>
                            <label class="editor-field mt-3">Диаметр диска, мм<input v-model.number="selectedOperation.blade_diameter" type="number" min="1"></label>
                            <p class="operation-tip">Перетаскивайте паз по поверхности за красный объём. Потяните круглую ручку на конце, чтобы изменить его длину и угол.</p>
                        </template>

                        <template v-else-if="selectedOperation.type === 'edge_roundover'">
                            <label class="editor-field mt-4">Кромка
                                <select :value="selectedOperation.edge" @change="setRoundoverEdge($event.target.value)">
                                    <optgroup label="Продольные кромки">
                                        <option value="top_left">Верхняя левая</option>
                                        <option value="top_right">Верхняя правая</option>
                                        <option value="bottom_left">Нижняя левая</option>
                                        <option value="bottom_right">Нижняя правая</option>
                                    </optgroup>
                                    <optgroup label="Поперечные кромки">
                                        <option value="top_start">Верхняя начальная</option>
                                        <option value="top_end">Верхняя конечная</option>
                                        <option value="bottom_start">Нижняя начальная</option>
                                        <option value="bottom_end">Нижняя конечная</option>
                                    </optgroup>
                                    <optgroup label="Вертикальные кромки торцов">
                                        <option value="start_left">Начальная левая</option>
                                        <option value="start_right">Начальная правая</option>
                                        <option value="end_left">Конечная левая</option>
                                        <option value="end_right">Конечная правая</option>
                                    </optgroup>
                                </select>
                            </label>
                            <p class="operation-tip">Кликните рядом с нужной кромкой непосредственно на заготовке. Выбрано: {{ roundoverEdgeLabel }}.</p>
                            <label class="editor-field mt-3">Радиус скругления, мм
                                <input :value="selectedOperation.radius" type="range" min="0.1" :max="roundoverMaximumRadius" step="0.1" @input="setRoundoverRadius($event.target.value)">
                            </label>
                            <label class="cut-field mt-3"><span>Точный радиус</span><div><input :value="selectedOperation.radius" type="number" min="0.1" :max="roundoverMaximumRadius" step="0.1" @change="setRoundoverRadius($event.target.value)"><b>мм</b></div></label>
                            <p class="operation-tip">Скругление применяется по всей длине выбранной кромки. Удаляемый фрезой материал показан полупрозрачным.</p>
                        </template>

                        <template v-else-if="selectedOperation.type === 'plunge_route'">
                            <label class="editor-field mt-4">Рабочая поверхность
                                <select :value="selectedOperation.face" @change="setPlungeFace($event.target.value)">
                                    <option value="top">Верхняя пласть</option>
                                    <option value="bottom">Нижняя пласть</option>
                                    <option value="left">Левая кромка</option>
                                    <option value="right">Правая кромка</option>
                                    <option value="start">Начальный торец</option>
                                    <option value="end">Конечный торец</option>
                                </select>
                            </label>
                            <p class="operation-tip">Кликните по нужной грани заготовки, чтобы перенести фрезу на неё. Выбрано: {{ grooveFaceLabel }}.</p>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <button class="button-secondary" :class="{ active: selectedOperation.route_mode === 'point' }" type="button" @click="setPlungeMode('point')">Погружение</button>
                                <button class="button-secondary" :class="{ active: selectedOperation.route_mode === 'path' }" type="button" @click="setPlungeMode('path')">Проход</button>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Профиль фрезы
                                    <select :value="selectedOperation.cutter_profile" @change="setPlungeProfile($event.target.value)">
                                        <option value="straight">Прямая</option>
                                        <option value="dovetail">Ласточкин хвост</option>
                                        <option value="v_groove">V-образная</option>
                                    </select>
                                </label>
                                <label v-if="selectedOperation.cutter_profile !== 'straight'" class="cut-field">
                                    <span>{{ selectedOperation.cutter_profile === 'dovetail' ? 'Угол боковой стенки' : 'Угол раскрытия' }}</span>
                                    <div><input :value="selectedOperation.cutter_angle" type="number" :min="selectedOperation.cutter_profile === 'dovetail' ? 1 : 10" :max="selectedOperation.cutter_profile === 'dovetail' ? 45 : 170" step="1" @change="setPlungeAngle($event.target.value)"><b>°</b></div>
                                </label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Начало U, мм<input v-model.number="selectedOperation.start_u" type="number" min="0" :max="grooveSurfaceSize.u" step="0.1"></label>
                                <label class="editor-field">Начало V, мм<input v-model.number="selectedOperation.start_v" type="number" min="0" :max="grooveSurfaceSize.v" step="0.1"></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">{{ selectedOperation.cutter_profile === 'straight' ? 'Диаметр фрезы, мм' : 'Макс. диаметр, мм' }}<input :value="selectedOperation.cutter_diameter" type="number" min="0.1" :max="Math.min(grooveSurfaceSize.u, grooveSurfaceSize.v)" step="0.1" @change="setPlungeDiameter($event.target.value)"></label>
                                <label class="editor-field">Глубина, мм<input :value="selectedOperation.depth" type="number" min="0.1" :max="plungeMaximumDepth" step="0.1" @change="setPlungeDepth($event.target.value)"></label>
                            </div>
                            <div v-if="selectedOperation.route_mode === 'path'" class="mt-3 grid grid-cols-2 gap-2">
                                <label class="cut-field"><span>Угол прохода</span><div><input v-model.number="selectedOperation.path_angle" type="number" min="-180" max="180" step="1"><b>°</b></div></label>
                                <label class="editor-field">Расстояние прохода, мм<input v-model.number="selectedOperation.travel_length" type="number" min="0.1" :max="Math.hypot(grooveSurfaceSize.u, grooveSurfaceSize.v)" step="0.1"></label>
                            </div>
                            <p class="operation-tip">Профиль «{{ plungeProfileLabel }}» формирует реальное сечение выреза. Жёлтая ручка задаёт точку погружения, а красная ручка прохода одновременно меняет его направление и расстояние.</p>
                        </template>

                        <template v-else-if="selectedOperation.type === 'drill'">
                            <label class="editor-field mt-4">Рабочая поверхность
                                <select :value="selectedOperation.face" @change="setDrillFace($event.target.value)">
                                    <option value="top">Верхняя пласть</option>
                                    <option value="bottom">Нижняя пласть</option>
                                    <option value="left">Левая кромка</option>
                                    <option value="right">Правая кромка</option>
                                    <option value="start">Начальный торец</option>
                                    <option value="end">Конечный торец</option>
                                </select>
                            </label>
                            <p class="operation-tip">Кликните по грани заготовки, затем перетащите красный цилиндр в точку сверления. Выбрано: {{ grooveFaceLabel }}.</p>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Центр U, мм<input v-model.number="selectedOperation.center_u" type="number" min="0" :max="grooveSurfaceSize.u" step="0.1"></label>
                                <label class="editor-field">Центр V, мм<input v-model.number="selectedOperation.center_v" type="number" min="0" :max="grooveSurfaceSize.v" step="0.1"></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Диаметр, мм<input :value="selectedOperation.diameter" type="number" min="0.1" :max="Math.min(grooveSurfaceSize.u, grooveSurfaceSize.v)" step="0.1" @change="setDrillDiameter($event.target.value)"></label>
                                <label class="editor-field">Глубина, мм<input :value="selectedOperation.through ? grooveSurfaceSize.depth : selectedOperation.depth" type="number" min="0.1" :max="grooveSurfaceSize.depth" step="0.1" :disabled="selectedOperation.through" @change="setDrillDepth($event.target.value)"></label>
                            </div>
                            <label class="mt-3 flex items-center gap-2 text-sm font-semibold text-stone-600">
                                <input :checked="selectedOperation.through" type="checkbox" @change="setDrillThrough($event.target.checked)">
                                Сквозное отверстие
                            </label>
                        </template>
                    </div>

                    <div class="inspector-section grid gap-2">
                        <button class="button-primary w-full" type="button" :disabled="saveState === 'saving' || currentOperations.length === 0" @click="savePart">
                            {{ saveState === 'saving' ? 'Применяем…' : `Применить операции ×${currentOperations.length}` }}
                        </button>
                        <p class="apply-operations-note">После применения плоскости скроются. Связанных экземпляров на сцене: {{ activePart?.instance_count ?? 0 }}.</p>
                        <button class="danger-button" type="button" @click="removePart">Удалить заготовку</button>
                    </div>
                </template>

                <template v-else-if="selectedGroup">
                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">Сборочный узел</h2>
                            <span class="status-pill">{{ selectedGroupDescendantCount }} деталей</span>
                        </div>
                        <label class="editor-field mt-4">Название<input v-model="selectedGroup.name" maxlength="255" @change="saveSelectedGroup"></label>
                        <label class="editor-field mt-3">Родительский узел
                            <select :value="selectedGroup.parent_id ?? ''" @change="moveSelectedGroup($event.target.value)">
                                <option value="">Всё изделие</option>
                                <option v-for="group in availableGroupParents" :key="group.id" :value="group.id">{{ group.name }}</option>
                            </select>
                        </label>
                    </div>
                    <div class="inspector-section grid gap-3">
                        <label class="mirror-toggle"><input v-model="selectedGroup.is_visible" type="checkbox" @change="saveSelectedGroup"><span>Показывать узел на сцене</span></label>
                        <label class="mirror-toggle"><input v-model="selectedGroup.is_locked" type="checkbox" @change="saveSelectedGroup"><span>Заблокировать детали узла</span></label>
                        <button class="button-secondary w-full" type="button" @click="toggleGroupIsolation(selectedGroup.id)">{{ isolatedGroupId === selectedGroup.id ? 'Показать окружение' : 'Изолировать узел' }}</button>
                        <button class="button-primary w-full" type="button" @click="openAssemblyGroup(selectedGroup.id)">Открыть как отдельное изделие</button>
                    </div>
                    <div class="inspector-section">
                        <p class="mb-3 text-xs leading-5 text-stone-400">Удаление без содержимого перенесёт дочерние узлы и детали на уровень выше.</p>
                        <button class="danger-button w-full" type="button" @click="removeSelectedGroup">Удалить узел</button>
                    </div>
                </template>

                <template v-else-if="selectedInstance">
                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">Экземпляр</h2>
                            <span class="status-pill">{{ selectedPart?.name }}</span>
                        </div>
                        <p class="mt-3 text-xs text-stone-400">Изменяется только размещение этой детали.</p>
                        <label class="editor-field mt-4">Сборочный узел
                            <select :value="selectedInstance.assembly_group_id ?? ''" @change="assignSelectedInstance($event.target.value)">
                                <option value="">Без группы</option>
                                <option v-for="group in projects.assemblyGroups" :key="group.id" :value="group.id">{{ group.name }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="inspector-section">
                        <h2 class="inspector-heading">Позиция, мм</h2>
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <label v-for="axis in axes" :key="`position-${axis}`" class="dimension-field"><span>{{ axis.toUpperCase() }}</span><input v-model.number="selectedInstance.position[axis]" type="number" @focus="beginInstanceInspectorEdit" @change="saveInstance"></label>
                        </div>
                    </div>

                    <div class="inspector-section">
                        <h2 class="inspector-heading">Поворот, °</h2>
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <label v-for="axis in axes" :key="`rotation-${axis}`" class="dimension-field"><span>{{ axis.toUpperCase() }}</span><input v-model.number="selectedInstance.rotation[axis]" type="number" @focus="beginInstanceInspectorEdit" @change="saveInstance"></label>
                        </div>
                        <label class="mirror-toggle mt-4"><input v-model="selectedInstance.mirrored" type="checkbox" @pointerdown="beginInstanceInspectorEdit" @change="saveInstance"><span>Зеркальный экземпляр</span></label>
                    </div>

                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">Копии</h2>
                            <span class="text-[10px] font-semibold text-stone-400">Shift+D</span>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <button class="button-secondary" type="button" :disabled="instanceToolsBusy" @click="duplicateSelectedInstance">Дублировать</button>
                            <button class="button-secondary" type="button" :disabled="instanceToolsBusy" @click="mirrorSelectedInstance">Зеркальная копия</button>
                        </div>
                        <p class="mt-2 text-[11px] leading-4 text-stone-400">Копия создаётся в том же положении и сразу выделяется. Зеркалирование выполняется по локальной оси длины детали.</p>

                        <div class="mt-5 rounded-xl border border-stone-200 bg-stone-50 p-3">
                            <strong class="text-xs text-stone-700">Линейный массив</strong>
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <label class="dimension-field"><span>Ось</span><select v-model="arraySettings.axis" class="min-w-0 rounded-lg border border-stone-200 bg-stone-100 px-2 py-2 text-xs text-stone-600"><option value="x">X</option><option value="y">Y</option><option value="z">Z</option></select></label>
                                <label class="dimension-field"><span>Копий</span><input v-model.number="arraySettings.copies" type="number" min="1" max="20"></label>
                                <label class="dimension-field"><span>Шаг, мм</span><input v-model.number="arraySettings.spacing" type="number" min="-100000" max="100000" step="0.1"></label>
                            </div>
                            <button class="button-primary mt-3 w-full" type="button" :disabled="instanceToolsBusy" @click="createLinearArray">
                                {{ instanceToolsBusy ? 'Создаём…' : `Создать копии ×${arraySettings.copies}` }}
                            </button>
                        </div>
                    </div>

                    <div class="inspector-section">
                        <button class="danger-button w-full" type="button" @click="removeInstance">Удалить со сцены</button>
                    </div>
                </template>

                <template v-else>
                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-4"><h2 class="inspector-heading">Проект</h2><span class="status-pill">Сборка</span></div>
                        <label class="mt-5 block text-xs font-semibold text-stone-500">Описание</label>
                        <textarea v-model="projectDescription" class="editor-textarea" placeholder="Добавьте заметки к проекту" @change="saveProject"></textarea>
                    </div>
                    <div class="inspector-section">
                        <h2 class="inspector-heading">Состав изделия</h2>
                        <div v-for="part in projects.parts" :key="part.id" class="scene-item" :class="{ 'scene-item-active': selectedPartId === part.id }" @click="selectedPartId = part.id">
                            <span class="scene-cube">◇</span><span class="truncate">{{ part.name }}</span><span class="ml-auto text-stone-400">×{{ part.instance_count }}</span>
                        </div>
                        <p v-if="!projects.parts.length" class="mt-4 text-xs leading-5 text-stone-400">Создайте заготовку в библиотеке слева.</p>
                    </div>
                    <div class="inspector-section"><p class="text-xs leading-5 text-stone-400">Щёлкните по детали на сцене, чтобы изменить её позицию и поворот.</p></div>
                </template>
            </aside>
        </div>

        <div v-if="showCreatePart" class="editor-modal-backdrop" @click.self="showCreatePart = false">
            <form class="editor-modal" @submit.prevent="createPart">
                <div class="flex items-start justify-between gap-4">
                    <div><p class="eyebrow">Новая деталь</p><h2 class="mt-2 text-2xl font-semibold tracking-tight">Создать заготовку</h2></div>
                    <button class="icon-button" type="button" aria-label="Закрыть" @click="showCreatePart = false">×</button>
                </div>
                <div class="mt-7 grid gap-4 sm:grid-cols-2">
                    <label class="field-label sm:col-span-2">Название<input v-model="newPart.name" class="field-input" placeholder="Например, ножка стола" required></label>
                    <label class="field-label sm:col-span-2">Материал<input v-model="newPart.material" class="field-input" placeholder="Дуб, ясень, фанера"></label>
                    <label class="field-label">Длина, мм<input v-model.number="newPart.length" class="field-input" type="number" min="1" required></label>
                    <label class="field-label">Ширина, мм<input v-model.number="newPart.width" class="field-input" type="number" min="1" required></label>
                    <label class="field-label">Толщина, мм<input v-model.number="newPart.thickness" class="field-input" type="number" min="1" required></label>
                    <label class="field-label">Количество на сцене<input v-model.number="newPart.quantity" class="field-input" type="number" min="0" max="20"></label>
                </div>
                <p v-if="createError" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ createError }}</p>
                <div class="mt-7 flex justify-end gap-3"><button class="button-secondary" type="button" @click="showCreatePart = false">Отмена</button><button class="button-primary" type="submit" :disabled="creatingPart">{{ creatingPart ? 'Создаём…' : 'Создать заготовку' }}</button></div>
            </form>
        </div>

        <div v-if="showAiAccess" class="editor-modal-backdrop" @click.self="showAiAccess = false">
            <section class="editor-modal max-w-2xl" aria-labelledby="ai-access-title">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="eyebrow">Интеграции</p>
                        <h2 id="ai-access-title" class="mt-2 text-2xl font-semibold tracking-tight">AI-доступ к проекту</h2>
                        <p class="mt-2 text-sm leading-6 text-stone-500">Ключ работает только с этим проектом через API v1 и MCP-сервер.</p>
                    </div>
                    <button class="icon-button" type="button" aria-label="Закрыть" @click="showAiAccess = false">×</button>
                </div>

                <div v-if="createdApiToken" class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <strong class="text-sm text-emerald-900">Скопируйте ключ сейчас</strong>
                        <span class="text-xs font-semibold text-emerald-700">Повторно он не показывается</span>
                    </div>
                    <textarea class="mt-3 h-24 w-full resize-none rounded-lg border border-emerald-200 bg-white p-3 font-mono text-xs text-stone-700 outline-none" readonly :value="createdApiToken"></textarea>
                    <button class="button-primary mt-3" type="button" @click="copyApiToken">{{ tokenCopied ? 'Скопировано' : 'Скопировать ключ' }}</button>
                </div>

                <form class="mt-6 grid gap-4 rounded-xl border border-stone-200 bg-stone-50 p-4 sm:grid-cols-[minmax(0,1fr)_8rem_auto] sm:items-end" @submit.prevent="createApiToken">
                    <label class="field-label">Название ключа<input v-model="apiTokenName" class="field-input" maxlength="100" required></label>
                    <label class="field-label">Срок, дней<input v-model.number="apiTokenExpiresInDays" class="field-input" type="number" min="1" max="365" required></label>
                    <button class="button-primary h-[42px]" type="submit" :disabled="apiTokensLoading">{{ apiTokensLoading ? 'Создаём…' : 'Создать ключ' }}</button>
                </form>

                <p v-if="apiTokenError" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ apiTokenError }}</p>

                <div class="mt-6">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-stone-700">Активные ключи проекта</h3>
                        <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-500">{{ projectApiTokens.length }}</span>
                    </div>
                    <div v-if="projectApiTokens.length" class="mt-3 grid gap-2">
                        <article v-for="token in projectApiTokens" :key="token.id" class="flex items-center gap-3 rounded-xl border border-stone-200 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <strong class="block truncate text-sm text-stone-700">{{ token.name }}</strong>
                                <small class="mt-1 block text-xs text-stone-400">До {{ formatApiTokenDate(token.expires_at) }} · чтение и изменение</small>
                            </div>
                            <button class="rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50" type="button" @click="revokeApiToken(token.id)">Отозвать</button>
                        </article>
                    </div>
                    <p v-else class="mt-3 rounded-xl border border-dashed border-stone-200 p-5 text-center text-sm text-stone-400">Для этого проекта ещё нет AI-ключей.</p>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ThreeViewport from '../components/ThreeViewport.vue';
import {
    assemblyBreadcrumbs,
    assemblyInstanceState as calculateAssemblyInstanceState,
    descendantGroupIds,
    flattenAssemblyTree,
} from '../editor/assemblyTree.js';
import { duplicateBlueprint, instanceBlueprintPayload, linearArrayBlueprints, mirroredBlueprint } from '../editor/instanceCopies.js';
import { createOperation, normalizeOperations, operationLabel, operationSummary } from '../editor/operations/catalog.js';
import { fitDrillToFace } from '../editor/operations/drill.js';
import {
    fitPlungeOperationToFace,
    plungeOperationRadius,
    plungeProfileMaximumDepth,
    plungeProfileName,
} from '../editor/operations/plungeRoute.js';
import { maximumRoundoverRadius, roundoverEdgeLabels } from '../editor/operations/roundover.js';
import { faceLabel, surfaceDimensions } from '../editor/operations/shared.js';
import { useProjectApiTokens } from '../editor/useProjectApiTokens.js';
import { useEditorHistory } from '../editor/useEditorHistory.js';
import { useProjectsStore } from '../stores/projects';

const route = useRoute();
const router = useRouter();
const projects = useProjectsStore();
const projectId = Number(route.params.id);
const axes = ['x', 'y', 'z'];
const loading = ref(true);
const projectName = ref('');
const projectDescription = ref('');
const saveState = ref('saved');
const editorMode = ref('assembly');
const leftPanelMode = ref('library');
const selectedGroupId = ref(null);
const activeAssemblyGroupId = ref(null);
const isolatedGroupId = ref(null);
const collapsedGroupIds = ref([]);
const assemblySearch = ref('');
const selectedPartId = ref(null);
const selectedInstanceId = ref(null);
const selectedOperationId = ref(null);
const partDraft = ref(null);
const historyExpanded = ref(false);
const showCreatePart = ref(false);
const creatingPart = ref(false);
const createError = ref('');
const instanceQuantities = reactive({});
const newPart = reactive({ name: '', material: '', length: 720, width: 60, thickness: 60, quantity: 0 });
const arraySettings = reactive({ axis: 'x', copies: 3, spacing: 100 });
const instanceToolsBusy = ref(false);
const {
    canRedo,
    canUndo,
    record: recordHistory,
    redo: redoHistory,
    redoLabel,
    undo: undoHistory,
    undoLabel,
} = useEditorHistory();
let partHistoryBaseline = null;
let partHistoryTimer = null;
let partHistoryLabel = 'Изменение заготовки';
let suppressPartHistory = false;
let instanceInspectorBaseline = null;

const activePart = computed(() => projects.parts.find((part) => part.id === selectedPartId.value) ?? null);
const selectedPart = computed(() => projects.parts.find((part) => part.instances?.some((instance) => instance.id === selectedInstanceId.value)) ?? null);
const selectedInstance = computed(() => selectedPart.value?.instances.find((instance) => instance.id === selectedInstanceId.value) ?? null);
const selectedGroup = computed(() => projects.assemblyGroups.find((group) => group.id === selectedGroupId.value) ?? null);
const activeAssemblyGroup = computed(() => projects.assemblyGroups.find((group) => group.id === activeAssemblyGroupId.value) ?? null);
const isolatedGroup = computed(() => projects.assemblyGroups.find((group) => group.id === isolatedGroupId.value) ?? null);
const assemblyBreadcrumbItems = computed(() => assemblyBreadcrumbs(projects.assemblyGroups, activeAssemblyGroupId.value));
const assemblyInstanceState = computed(() => calculateAssemblyInstanceState(
    projects.assemblyGroups,
    projects.parts,
    activeAssemblyGroupId.value,
    isolatedGroupId.value,
));
const assemblyRows = computed(() => {
    const rows = flattenAssemblyTree(
        projects.assemblyGroups,
        projects.parts,
        activeAssemblyGroupId.value,
        collapsedGroupIds.value,
    );
    const search = assemblySearch.value.trim().toLocaleLowerCase();

    if (!search) return rows;

    return rows.filter((row) => (row.kind === 'group' ? row.group.name : row.part.name).toLocaleLowerCase().includes(search));
});
const selectedGroupDescendantIds = computed(() => selectedGroup.value
    ? new Set(descendantGroupIds(projects.assemblyGroups, selectedGroup.value.id))
    : new Set());
const selectedGroupDescendantCount = computed(() => projects.parts.reduce(
    (count, part) => count + (part.instances ?? []).filter((instance) => selectedGroupDescendantIds.value.has(instance.assembly_group_id)).length,
    0,
));
const availableGroupParents = computed(() => projects.assemblyGroups.filter((group) => !selectedGroupDescendantIds.value.has(group.id)));
const totalInstances = computed(() => projects.parts.reduce((total, part) => total + (part.instances?.length ?? 0), 0));
const currentOperations = computed(() => partDraft.value?.operations.filter((operation) => operation.status !== 'applied') ?? []);
const historyOperations = computed(() => partDraft.value?.operations.filter((operation) => operation.status === 'applied') ?? []);
const {
    apiTokenError,
    apiTokenExpiresInDays,
    apiTokenName,
    apiTokensLoading,
    copyApiToken,
    createApiToken,
    createdApiToken,
    openAiAccess,
    projectApiTokens,
    revokeApiToken,
    showAiAccess,
    tokenCopied,
} = useProjectApiTokens(projectId);
const saveLabel = computed(() => ({ saving: 'Сохраняем…', saved: 'Все изменения сохранены', applied: 'Операции применены', error: 'Ошибка сохранения' }[saveState.value]));
const selectedOperation = computed(() => partDraft.value?.operations.find((operation) => operation.id === selectedOperationId.value) ?? null);
const isFullDepthCut = computed(() => Number(selectedOperation.value?.cut_depth) >= Number(partDraft.value?.dimensions.thickness) - 0.01);
const grooveSurfaceDimensions = (face, dimensions = partDraft.value?.dimensions) => surfaceDimensions(face, dimensions);
const grooveSurfaceSize = computed(() => grooveSurfaceDimensions(selectedOperation.value?.face));
const plungeMaximumDepth = computed(() => plungeProfileMaximumDepth(selectedOperation.value, grooveSurfaceSize.value.depth));
const plungeProfileLabel = computed(() => plungeProfileName(selectedOperation.value?.cutter_profile));
const grooveFaceLabel = computed(() => faceLabel(selectedOperation.value?.face));
const roundoverMaximumRadius = computed(() => maximumRoundoverRadius(selectedOperation.value?.edge, partDraft.value?.dimensions));
const roundoverEdgeLabel = computed(() => roundoverEdgeLabels[selectedOperation.value?.edge] ?? 'Кромка');
const partPreview = computed(() => {
    if (!activePart.value || !partDraft.value) {
        return activePart.value;
    }

    return {
        ...activePart.value,
        name: partDraft.value.name,
        material: partDraft.value.material,
        dimensions: { ...partDraft.value.dimensions },
        grain_axis: partDraft.value.grain_axis,
        operations: partDraft.value.operations.map((operation) => ({ ...operation })),
    };
});

const cloneEditorValue = (value) => JSON.parse(JSON.stringify(value));

const capturePartHistoryState = () => partDraft.value ? {
    partId: selectedPartId.value,
    draft: cloneEditorValue(partDraft.value),
    selectedOperationId: selectedOperationId.value,
} : null;

const restorePartHistoryState = async (state) => {
    window.clearTimeout(partHistoryTimer);
    partHistoryTimer = null;
    suppressPartHistory = true;
    selectedPartId.value = state.partId;
    selectedInstanceId.value = null;
    selectedOperationId.value = state.selectedOperationId;
    partDraft.value = cloneEditorValue(state.draft);
    editorMode.value = 'part';
    partHistoryBaseline = cloneEditorValue(state);
    await nextTick();
    suppressPartHistory = false;
};

const resetPartHistoryBaseline = async () => {
    window.clearTimeout(partHistoryTimer);
    partHistoryTimer = null;
    suppressPartHistory = true;
    partHistoryBaseline = capturePartHistoryState();
    await nextTick();
    suppressPartHistory = false;
};

const flushPartHistory = () => {
    window.clearTimeout(partHistoryTimer);
    partHistoryTimer = null;

    if (suppressPartHistory || !partHistoryBaseline || !partDraft.value) return;

    const before = cloneEditorValue(partHistoryBaseline);
    const after = capturePartHistoryState();

    if (JSON.stringify(before.draft) === JSON.stringify(after.draft)) return;

    const label = partHistoryLabel;
    recordHistory({
        label,
        undo: () => restorePartHistoryState(before),
        redo: () => restorePartHistoryState(after),
    });
    partHistoryBaseline = cloneEditorValue(after);
    partHistoryLabel = 'Изменение заготовки';
};

const labelPartHistory = (label) => {
    partHistoryLabel = label;
};

watch(partDraft, () => {
    if (suppressPartHistory || !partHistoryBaseline) return;

    window.clearTimeout(partHistoryTimer);
    partHistoryTimer = window.setTimeout(flushPartHistory, 300);
}, { deep: true });

const undoEditor = async () => {
    flushPartHistory();

    try {
        await undoHistory();
    } catch {
        saveState.value = 'error';
    }
};

const redoEditor = async () => {
    flushPartHistory();

    try {
        await redoHistory();
    } catch {
        saveState.value = 'error';
    }
};

onMounted(async () => {
    try {
        const [project, parts] = await Promise.all([
            projects.fetchProject(projectId),
            projects.fetchParts(projectId),
            projects.fetchAssemblyGroups(projectId),
        ]);
        projectName.value = project.name;
        projectDescription.value = project.description ?? '';
        selectedPartId.value = parts[0]?.id ?? null;
        parts.forEach((part) => { instanceQuantities[part.id] = 1; });
    } catch (error) {
        if (error.response?.status === 404) {
            await router.replace({ name: 'projects' });
        }
    } finally {
        loading.value = false;
    }
});

const draftFromPart = (part) => {
    return {
        name: part.name,
        material: part.material ?? '',
        dimensions: { ...part.dimensions },
        grain_axis: part.grain_axis,
        operations: normalizeOperations(part),
    };
};

const openPart = async (part) => {
    if (!part) {
        showCreatePart.value = true;
        return;
    }

    flushPartHistory();
    selectedPartId.value = part.id;
    selectedInstanceId.value = null;
    partDraft.value = draftFromPart(part);
    selectedOperationId.value = null;
    historyExpanded.value = false;
    editorMode.value = 'part';
    await resetPartHistoryBaseline();
};

const addOperation = (type) => {
    labelPartHistory('Добавление операции');
    const operation = createOperation(type, partDraft.value.dimensions);
    partDraft.value.operations.push(operation);
    selectedOperationId.value = operation.id;
};

const removeOperation = (operationIdToRemove) => {
    const index = partDraft.value.operations.findIndex((operation) => operation.id === operationIdToRemove);

    if (index < 0) return;

    labelPartHistory('Удаление операции');
    const wasSelected = operationIdToRemove === selectedOperationId.value;
    partDraft.value.operations.splice(index, 1);

    if (wasSelected) {
        selectedOperationId.value = currentOperations.value[0]?.id ?? null;
    }
};

const moveOperation = (operationIdToMove, direction) => {
    const currentIndex = currentOperations.value.findIndex((operation) => operation.id === operationIdToMove);
    const targetOperation = currentOperations.value[currentIndex + direction];

    if (currentIndex < 0 || !targetOperation) return;

    labelPartHistory('Порядок операций');
    const sourceIndex = partDraft.value.operations.findIndex((operation) => operation.id === operationIdToMove);
    const targetIndex = partDraft.value.operations.findIndex((operation) => operation.id === targetOperation.id);
    [partDraft.value.operations[sourceIndex], partDraft.value.operations[targetIndex]] = [partDraft.value.operations[targetIndex], partDraft.value.operations[sourceIndex]];
};

const editAppliedOperation = (operation) => {
    labelPartHistory('Редактирование операции');
    operation.status = 'draft';
    selectedOperationId.value = operation.id;
};

const updateOperationFromScene = (operationId, changes) => {
    const operation = partDraft.value?.operations.find((item) => item.id === operationId);

    if (operation) {
        labelPartHistory('Изменение операции на сцене');
        Object.assign(operation, changes);
    }
};

const setGrooveFace = (face) => {
    if (selectedOperation.value?.type !== 'groove') return;

    const surface = grooveSurfaceDimensions(face);
    const grooveLength = Math.min(Number(selectedOperation.value.groove_length), Math.max(surface.u * 0.8, 0.1));

    Object.assign(selectedOperation.value, {
        face,
        center_u: surface.u / 2,
        center_v: surface.v / 2,
        path_angle: 0,
        groove_length: grooveLength,
        depth: Math.min(Number(selectedOperation.value.depth), surface.depth),
    });
};

const setGrooveDirection = (angle) => {
    if (selectedOperation.value?.type !== 'groove') return;

    selectedOperation.value.path_angle = angle;
};

const setRoundoverEdge = (edge) => {
    if (selectedOperation.value?.type !== 'edge_roundover') return;

    selectedOperation.value.edge = edge;
    selectedOperation.value.radius = Math.min(
        Number(selectedOperation.value.radius),
        maximumRoundoverRadius(edge, partDraft.value.dimensions),
    );
};

const setRoundoverRadius = (value) => {
    if (selectedOperation.value?.type !== 'edge_roundover') return;

    selectedOperation.value.radius = Math.min(Math.max(Number(value), 0.1), roundoverMaximumRadius.value);
};

const setPlungeFace = (face) => {
    if (selectedOperation.value?.type !== 'plunge_route') return;

    Object.assign(
        selectedOperation.value,
        fitPlungeOperationToFace(selectedOperation.value, face, partDraft.value.dimensions),
    );
};

const setPlungeMode = (mode) => {
    if (selectedOperation.value?.type !== 'plunge_route') return;

    const surface = grooveSurfaceDimensions(selectedOperation.value.face);
    const diameter = plungeOperationRadius(selectedOperation.value) * 2;
    const travelLength = mode === 'path'
        ? Math.max(Math.min(Number(selectedOperation.value.travel_length) || surface.u * 0.25, surface.u - diameter), 0.1)
        : 0;

    Object.assign(selectedOperation.value, {
        route_mode: mode,
        start_u: mode === 'path' ? Math.max((surface.u - travelLength) / 2, diameter / 2) : surface.u / 2,
        start_v: surface.v / 2,
        path_angle: 0,
        travel_length: travelLength,
    });
};

const fitPlungeDepth = () => {
    selectedOperation.value.depth = Math.min(
        Math.max(Number(selectedOperation.value.depth), 0.1),
        plungeProfileMaximumDepth(
            selectedOperation.value,
            grooveSurfaceDimensions(selectedOperation.value.face).depth,
        ),
    );
};

const setPlungeProfile = (profile) => {
    if (selectedOperation.value?.type !== 'plunge_route') return;

    selectedOperation.value.cutter_profile = profile;
    selectedOperation.value.cutter_angle = { straight: 0, dovetail: 14, v_groove: 90 }[profile];
    fitPlungeDepth();
};

const setPlungeAngle = (value) => {
    if (selectedOperation.value?.type !== 'plunge_route') return;

    const limits = selectedOperation.value.cutter_profile === 'dovetail' ? [1, 45] : [10, 170];
    selectedOperation.value.cutter_angle = Math.min(Math.max(Number(value), limits[0]), limits[1]);
    fitPlungeDepth();
};

const setPlungeDiameter = (value) => {
    if (selectedOperation.value?.type !== 'plunge_route') return;

    const surface = grooveSurfaceDimensions(selectedOperation.value.face);
    selectedOperation.value.cutter_diameter = Math.min(Math.max(Number(value), 0.1), surface.u, surface.v);
    fitPlungeDepth();
};

const setPlungeDepth = (value) => {
    if (selectedOperation.value?.type !== 'plunge_route') return;

    selectedOperation.value.depth = Number(value);
    fitPlungeDepth();
};

const fitDrillCenter = () => {
    const surface = grooveSurfaceDimensions(selectedOperation.value.face);
    const radius = Number(selectedOperation.value.diameter) / 2;
    selectedOperation.value.center_u = Math.min(Math.max(Number(selectedOperation.value.center_u), radius), surface.u - radius);
    selectedOperation.value.center_v = Math.min(Math.max(Number(selectedOperation.value.center_v), radius), surface.v - radius);
};

const setDrillFace = (face) => {
    if (selectedOperation.value?.type !== 'drill') return;

    Object.assign(
        selectedOperation.value,
        fitDrillToFace(selectedOperation.value, face, partDraft.value.dimensions),
    );
};

const setDrillDiameter = (value) => {
    if (selectedOperation.value?.type !== 'drill') return;

    const surface = grooveSurfaceDimensions(selectedOperation.value.face);
    selectedOperation.value.diameter = Math.min(Math.max(Number(value), 0.1), surface.u, surface.v);
    fitDrillCenter();
};

const setDrillDepth = (value) => {
    if (selectedOperation.value?.type !== 'drill') return;

    selectedOperation.value.depth = Math.min(Math.max(Number(value), 0.1), grooveSurfaceSize.value.depth);
};

const setDrillThrough = (through) => {
    if (selectedOperation.value?.type !== 'drill') return;

    selectedOperation.value.through = through;

    if (through) selectedOperation.value.depth = grooveSurfaceSize.value.depth;
};

const selectPart = (part) => {
    if (editorMode.value === 'part') {
        openPart(part);
        return;
    }

    selectedPartId.value = part.id;
};

const createPart = async () => {
    creatingPart.value = true;
    createError.value = '';

    try {
        const part = await projects.createPart(projectId, {
            name: newPart.name,
            material: newPart.material || null,
            length: newPart.length,
            width: newPart.width,
            thickness: newPart.thickness,
            grain_axis: 'length',
            operations: [],
        });
        instanceQuantities[part.id] = 1;

        if (newPart.quantity > 0) {
            await projects.createInstances(projectId, part.id, {
                quantity: newPart.quantity,
                assembly_group_id: activeAssemblyGroupId.value,
            });
        }

        showCreatePart.value = false;
        newPart.name = '';
        newPart.material = '';
        openPart(projects.parts.find((item) => item.id === part.id));
    } catch (error) {
        createError.value = error.response?.data?.message ?? 'Не удалось создать заготовку.';
    } finally {
        creatingPart.value = false;
    }
};

const savePart = async () => {
    if (!activePart.value || !partDraft.value) {
        return;
    }

    flushPartHistory();
    saveState.value = 'saving';

    try {
        const appliedOperations = partDraft.value.operations.map((operation) => ({ ...operation, status: 'applied' }));
        const updated = await projects.updatePart(projectId, activePart.value.id, {
            name: partDraft.value.name,
            material: partDraft.value.material || null,
            ...partDraft.value.dimensions,
            grain_axis: partDraft.value.grain_axis,
            operations: appliedOperations,
        });
        partDraft.value = draftFromPart(updated);
        selectedOperationId.value = null;
        historyExpanded.value = false;
        await resetPartHistoryBaseline();
        saveState.value = 'applied';
    } catch {
        saveState.value = 'error';
    }
};

const removePart = async () => {
    if (!activePart.value || !window.confirm(`Удалить заготовку «${activePart.value.name}» и все её экземпляры?`)) {
        return;
    }

    await projects.deletePart(projectId, activePart.value.id);
    selectedPartId.value = projects.parts[0]?.id ?? null;
    partDraft.value = selectedPartId.value ? draftFromPart(projects.parts[0]) : null;
    selectedOperationId.value = null;
    editorMode.value = projects.parts.length ? 'part' : 'assembly';
    await resetPartHistoryBaseline();
};

const addInstances = async (part) => {
    const quantity = instanceQuantities[part.id] || 1;
    const assemblyGroupId = activeAssemblyGroupId.value;
    let instances = await projects.createInstances(projectId, part.id, { quantity, assembly_group_id: assemblyGroupId });
    selectedPartId.value = part.id;
    selectedInstanceId.value = instances[0]?.id ?? null;
    editorMode.value = 'assembly';

    recordHistory({
        label: quantity === 1 ? 'Добавление детали' : `Добавление деталей ×${quantity}`,
        undo: async () => {
            await Promise.all(instances.map((instance) => projects.deleteInstance(projectId, instance.id)));
            selectedInstanceId.value = null;
        },
        redo: async () => {
            instances = await projects.createInstances(projectId, part.id, { quantity, assembly_group_id: assemblyGroupId });
            selectedPartId.value = part.id;
            selectedInstanceId.value = instances[0]?.id ?? null;
            editorMode.value = 'assembly';
        },
    });
};

const selectInstance = (instanceId) => {
    if (assemblyInstanceState.value.lockedInstanceIds.includes(instanceId)) return;

    selectedInstanceId.value = instanceId;
    selectedGroupId.value = null;

    if (instanceId) {
        selectedPartId.value = selectedPart.value?.id ?? selectedPartId.value;
    }
};

const selectAssemblyGroup = (group) => {
    editorMode.value = 'assembly';
    leftPanelMode.value = 'assembly';
    selectedGroupId.value = group.id;
    selectedInstanceId.value = null;
};

const createAssemblyGroup = async () => {
    const name = window.prompt('Название сборочного узла', 'Новый узел')?.trim();

    if (!name) return;

    const group = await projects.createAssemblyGroup(projectId, {
        name,
        parent_id: selectedGroupId.value ?? activeAssemblyGroupId.value,
    });
    selectedGroupId.value = group.id;
    selectedInstanceId.value = null;
    editorMode.value = 'assembly';
};

const saveSelectedGroup = async () => {
    if (!selectedGroup.value) return;

    await projects.updateAssemblyGroup(projectId, selectedGroup.value.id, {
        name: selectedGroup.value.name,
        parent_id: selectedGroup.value.parent_id,
        is_visible: selectedGroup.value.is_visible,
        is_locked: selectedGroup.value.is_locked,
        sort_order: selectedGroup.value.sort_order,
    });

    if (selectedGroup.value.is_locked) selectedInstanceId.value = null;
};

const moveSelectedGroup = async (parentId) => {
    if (!selectedGroup.value) return;

    selectedGroup.value.parent_id = parentId === '' ? null : Number(parentId);
    await saveSelectedGroup();
};

const toggleGroupVisibility = async (group) => {
    await projects.updateAssemblyGroup(projectId, group.id, { is_visible: !group.is_visible });

    if (selectedInstanceId.value && !assemblyInstanceState.value.visibleInstanceIds.includes(selectedInstanceId.value)) {
        selectedInstanceId.value = null;
    }
};

const toggleGroupIsolation = (groupId) => {
    isolatedGroupId.value = isolatedGroupId.value === groupId ? null : groupId;
    selectedInstanceId.value = null;
};

const openAssemblyGroup = (groupId) => {
    activeAssemblyGroupId.value = groupId;
    isolatedGroupId.value = null;
    selectedGroupId.value = groupId;
    selectedInstanceId.value = null;
    editorMode.value = 'assembly';
    leftPanelMode.value = 'assembly';
};

const toggleGroupCollapsed = (groupId) => {
    collapsedGroupIds.value = collapsedGroupIds.value.includes(groupId)
        ? collapsedGroupIds.value.filter((id) => id !== groupId)
        : [...collapsedGroupIds.value, groupId];
};

const isGroupCollapsed = (groupId) => collapsedGroupIds.value.includes(groupId);

const removeSelectedGroup = async () => {
    if (!selectedGroup.value || !window.confirm(`Удалить узел «${selectedGroup.value.name}»?`)) return;

    const deleteContents = window.confirm('Удалить также все экземпляры деталей внутри этого узла? Нажмите «Отмена», чтобы сохранить содержимое на уровне выше.');
    const groupId = selectedGroup.value.id;
    const parentId = selectedGroup.value.parent_id;
    await projects.deleteAssemblyGroup(projectId, groupId, deleteContents);
    selectedGroupId.value = parentId;

    if (activeAssemblyGroupId.value === groupId) activeAssemblyGroupId.value = parentId;
    if (isolatedGroupId.value === groupId) isolatedGroupId.value = null;
};

const assignSelectedInstance = async (groupId) => {
    if (!selectedInstance.value) return;

    const instanceId = selectedInstance.value.id;
    await projects.updateInstance(projectId, instanceId, {
        assembly_group_id: groupId === '' ? null : Number(groupId),
    });
    await projects.fetchAssemblyGroups(projectId);
};

const beginInstanceInspectorEdit = () => {
    if (!selectedInstance.value) return;

    instanceInspectorBaseline = {
        position: { ...selectedInstance.value.position },
        rotation: { ...selectedInstance.value.rotation },
        mirrored: selectedInstance.value.mirrored,
    };
};

const saveInstance = async () => {
    if (!selectedInstance.value) {
        return;
    }

    const instance = selectedInstance.value;
    await projects.updateInstance(projectId, instance.id, {
        position_x: instance.position.x,
        position_y: instance.position.y,
        position_z: instance.position.z,
        rotation_x: instance.rotation.x,
        rotation_y: instance.rotation.y,
        rotation_z: instance.rotation.z,
        mirrored: instance.mirrored,
    });

    if (instanceInspectorBaseline) {
        const instanceId = instance.id;
        const before = cloneEditorValue(instanceInspectorBaseline);
        const after = {
            position: { ...instance.position },
            rotation: { ...instance.rotation },
            mirrored: instance.mirrored,
        };

        if (JSON.stringify(before) !== JSON.stringify(after)) {
            recordHistory({
                label: 'Точное размещение детали',
                undo: () => persistInstanceTransform(instanceId, before.position, before.rotation, before.mirrored),
                redo: () => persistInstanceTransform(instanceId, after.position, after.rotation, after.mirrored),
            });
        }
    }

    instanceInspectorBaseline = null;
};

const persistInstanceTransform = async (instanceId, position, rotation, mirrored = null) => {
    const part = projects.parts.find((item) => item.instances?.some((instance) => instance.id === instanceId));
    const instance = part?.instances.find((item) => item.id === instanceId);

    if (!instance) return;

    instance.position = { ...position };
    instance.rotation = { ...rotation };
    await projects.updateInstance(projectId, instanceId, {
        position_x: position.x,
        position_y: position.y,
        position_z: position.z,
        rotation_x: rotation.x,
        rotation_y: rotation.y,
        rotation_z: rotation.z,
        mirrored: mirrored ?? instance.mirrored,
    });
};

const instantiateBlueprints = async (partId, blueprints) => {
    const results = await Promise.allSettled(blueprints.map((blueprint) => projects.createInstances(
        projectId,
        partId,
        instanceBlueprintPayload(blueprint),
    )));
    const instances = results
        .filter((result) => result.status === 'fulfilled')
        .flatMap((result) => result.value);
    const failed = results.find((result) => result.status === 'rejected');

    if (failed) {
        await deleteInstanceCopies(instances);
        throw failed.reason;
    }

    return instances;
};

const deleteInstanceCopies = async (instances) => {
    await Promise.all(instances.map((instance) => projects.deleteInstance(projectId, instance.id)));
};

const createCopiesWithHistory = async (blueprints, label) => {
    if (!selectedPart.value || !blueprints.length || instanceToolsBusy.value) return;

    const partId = selectedPart.value.id;
    let instances = [];
    instanceToolsBusy.value = true;
    saveState.value = 'saving';

    try {
        instances = await instantiateBlueprints(partId, blueprints);
        selectedPartId.value = partId;
        selectedInstanceId.value = instances.at(-1)?.id ?? null;
        editorMode.value = 'assembly';
        recordHistory({
            label,
            undo: async () => {
                await deleteInstanceCopies(instances);
                selectedInstanceId.value = null;
            },
            redo: async () => {
                instances = await instantiateBlueprints(partId, blueprints);
                selectedPartId.value = partId;
                selectedInstanceId.value = instances.at(-1)?.id ?? null;
                editorMode.value = 'assembly';
            },
        });
        saveState.value = 'saved';
    } catch {
        saveState.value = 'error';
    } finally {
        instanceToolsBusy.value = false;
    }
};

const duplicateSelectedInstance = () => {
    if (!selectedInstance.value) return;

    return createCopiesWithHistory(
        [duplicateBlueprint(selectedInstance.value)],
        'Дублирование детали',
    );
};

const mirrorSelectedInstance = () => {
    if (!selectedInstance.value) return;

    return createCopiesWithHistory(
        [mirroredBlueprint(selectedInstance.value)],
        'Зеркальная копия детали',
    );
};

const createLinearArray = () => {
    if (!selectedInstance.value) return;

    const blueprints = linearArrayBlueprints(selectedInstance.value, arraySettings);

    return createCopiesWithHistory(
        blueprints,
        `Линейный массив ×${blueprints.length}`,
    );
};

const commitInstanceTransform = async ({ instanceId, position, rotation, initialPosition, initialRotation }) => {
    const part = projects.parts.find((item) => item.instances?.some((instance) => instance.id === instanceId));
    const instance = part?.instances.find((item) => item.id === instanceId);

    if (!instance) return;

    instance.position = { ...position };
    instance.rotation = { ...rotation };
    saveState.value = 'saving';

    try {
        await projects.updateInstance(projectId, instanceId, {
            position_x: position.x,
            position_y: position.y,
            position_z: position.z,
            rotation_x: rotation.x,
            rotation_y: rotation.y,
            rotation_z: rotation.z,
            mirrored: instance.mirrored,
        });
        const before = { position: { ...initialPosition }, rotation: { ...initialRotation } };
        const after = { position: { ...position }, rotation: { ...rotation } };

        if (JSON.stringify(before) !== JSON.stringify(after)) {
            recordHistory({
                label: 'Трансформация детали',
                undo: () => persistInstanceTransform(instanceId, before.position, before.rotation),
                redo: () => persistInstanceTransform(instanceId, after.position, after.rotation),
            });
        }

        saveState.value = 'saved';
    } catch {
        instance.position = { ...initialPosition };
        instance.rotation = { ...initialRotation };
        saveState.value = 'error';
    }
};

const removeInstance = async () => {
    if (!selectedInstance.value) {
        return;
    }

    const snapshot = {
        partId: selectedPart.value.id,
        instanceId: selectedInstance.value.id,
        position: { ...selectedInstance.value.position },
        rotation: { ...selectedInstance.value.rotation },
        mirrored: selectedInstance.value.mirrored,
        assemblyGroupId: selectedInstance.value.assembly_group_id,
    };
    await projects.deleteInstance(projectId, snapshot.instanceId);
    selectedInstanceId.value = null;

    recordHistory({
        label: 'Удаление детали',
        undo: async () => {
            const [instance] = await projects.createInstances(projectId, snapshot.partId, {
                quantity: 1,
                assembly_group_id: snapshot.assemblyGroupId,
            });
            snapshot.instanceId = instance.id;
            await persistInstanceTransform(snapshot.instanceId, snapshot.position, snapshot.rotation, snapshot.mirrored);
            selectedPartId.value = snapshot.partId;
            selectedInstanceId.value = snapshot.instanceId;
            editorMode.value = 'assembly';
        },
        redo: async () => {
            await projects.deleteInstance(projectId, snapshot.instanceId);
            selectedInstanceId.value = null;
        },
    });
};

const saveProject = async () => {
    if (!projectName.value.trim()) {
        return;
    }

    saveState.value = 'saving';

    try {
        await projects.updateProject(projectId, { name: projectName.value, description: projectDescription.value || null });
        saveState.value = 'saved';
    } catch {
        saveState.value = 'error';
    }
};

const saveCurrent = () => editorMode.value === 'part' ? savePart() : saveProject();
const handleEditorHistoryKey = (event) => {
    if (event.shiftKey && event.key.toLowerCase() === 'd' && editorMode.value === 'assembly' && selectedInstance.value) {
        if (!event.repeat && !['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName) && !event.target?.isContentEditable) {
            event.preventDefault();
            duplicateSelectedInstance();
        }

        return;
    }

    if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'z') return;
    if (['INPUT', 'TEXTAREA'].includes(event.target?.tagName) || event.target?.isContentEditable) return;

    event.preventDefault();

    if (event.shiftKey) {
        redoEditor();
    } else {
        undoEditor();
    }
};

onMounted(() => window.addEventListener('keydown', handleEditorHistoryKey));
onBeforeUnmount(() => {
    window.clearTimeout(partHistoryTimer);
    window.removeEventListener('keydown', handleEditorHistoryKey);
});

const formatDimensions = (part) => `${part.dimensions.length} × ${part.dimensions.width} × ${part.dimensions.thickness} мм`;
const formatApiTokenDate = (date) => date ? new Intl.DateTimeFormat('ru-UA', { dateStyle: 'medium' }).format(new Date(date)) : 'без ограничения';
const pluralParts = (count) => count === 1 ? 'заготовка' : count > 1 && count < 5 ? 'заготовки' : 'заготовок';
</script>
