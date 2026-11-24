<template>
    <div class="editor-shell" :class="{ 'library-collapsed': !libraryVisible, 'inspector-collapsed': !inspectorVisible }">
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
                <button type="button" :class="{ active: editorMode === 'assembly' }" @click="returnToAssembly">Сборка</button>
                <button type="button" :class="{ active: editorMode === 'part' }" @click="openCurrentPart">Заготовка</button>
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

            <button class="button-secondary button-compact" type="button" @click="openProjectAnalysis()">Анализ и раскрой</button>
            <details class="workspace-menu" @keydown.esc="$event.currentTarget.open = false">
                <summary class="button-secondary button-compact">Ещё <span aria-hidden="true">⌄</span></summary>
                <div class="workspace-menu-items" @click="$event.currentTarget.parentElement.open = false">
                    <button type="button" @click="openScriptEditor">Сценарии · JavaScript</button>
                    <button type="button" @click="showVersions = true">Версии проекта</button>
                    <button type="button" @click="startAssemblyGuide">Сборка по шагам</button>
                    <button type="button" @click="openTemplateCreator">Сохранить как шаблон</button>
                    <button type="button" @click="openAiAccess">Доступ для AI и API</button>
                </div>
            </details>
            <button class="button-primary button-compact" type="button" :disabled="saveState === 'saving'" @click="saveCurrent">
                {{ editorMode === 'part' ? 'Сохранить заготовку' : 'Сохранить проект' }}
            </button>
        </header>

        <div class="workspace-context">
            <button type="button" :aria-pressed="libraryVisible" @click="toggleWorkspacePanel('library')">☷ Заготовки и состав</button>
            <span class="workspace-context-label">{{ editorMode === 'part' ? 'Обработка заготовки' : 'Сборка изделия' }} <span aria-hidden="true">/</span> размеры в миллиметрах</span>
            <button type="button" :aria-pressed="inspectorVisible" @click="toggleWorkspacePanel('inspector')">Настройки детали ☷</button>
        </div>
        <div v-if="recovery.pending.value.length" class="flex flex-wrap items-center gap-3 bg-amber-100 px-4 py-2 text-sm text-amber-950" role="status">
            <span>Найден черновик: {{ recovery.pending.value[0].name }}</span><button class="font-semibold underline" @click="recovery.restore">Восстановить</button><button class="underline" @click="recovery.discard">Удалить черновик</button>
        </div>
        <p v-if="recovery.warning.value" role="alert" class="bg-amber-100 px-4 py-2 text-sm">{{ recovery.warning.value }}</p>

        <div v-if="loading" class="grid flex-1 place-items-center bg-stone-100">
            <div class="text-center"><div class="loader mx-auto"></div><p class="mt-4 text-sm text-stone-500">Открываем мастерскую…</p></div>
        </div>

        <div v-else class="editor-workspace editor-workspace-parts">
            <aside class="parts-library">
                <div class="grid grid-cols-2 border-b border-stone-200 bg-stone-50 p-2">
                    <button class="rounded-lg px-2 py-2 text-xs font-semibold" :class="leftPanelMode === 'assembly' ? 'bg-white text-emerald-900 shadow-sm' : 'text-stone-500'" type="button" @click="leftPanelMode = 'assembly'; editorMode = 'assembly'">Сборка</button>
                    <button class="rounded-lg px-2 py-2 text-xs font-semibold" :class="leftPanelMode === 'library' ? 'bg-white text-emerald-900 shadow-sm' : 'text-stone-500'" type="button" @click="leftPanelMode = 'library'">Заготовки</button>
                </div>

                <template v-if="leftPanelMode === 'library'">
                <div class="parts-library-header">
                    <div>
                        <span class="inspector-heading">Библиотека</span>
                        <p>{{ projects.parts.length }} {{ pluralParts(projects.parts.length) }}</p>
                    </div>
                    <button class="library-add-button" type="button" aria-label="Создать заготовку" @click="showCreatePart = true">＋</button>
                </div>

                <label v-if="projects.parts.length" class="library-search"><span class="sr-only">Найти заготовку</span><input v-model="partSearch" type="search" placeholder="Найти заготовку…"></label>
                <div v-if="projects.parts.length" class="parts-list">
                    <p v-if="!filteredLibraryParts.length" class="p-3 text-sm text-stone-500">Ничего не найдено. Попробуйте другое название.</p>
                    <article v-for="part in filteredLibraryParts" :key="part.id" class="part-library-card" :class="{ active: selectedPartId === part.id }" @click="selectPart(part)">
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
                        <button v-if="activeAssemblyGroup" class="mb-2 inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50" type="button" aria-keyshortcuts="Alt+ArrowUp" @click="openParentAssemblyGroup">← На уровень выше <kbd>Alt+↑</kbd></button>
                        <input v-model="assemblySearch" type="search" aria-label="Поиск на текущем уровне" class="w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-xs outline-none focus:border-emerald-500" placeholder="Поиск на этом уровне…">
                        <p v-if="projects.assemblyGroups.length" class="mt-2 text-xs leading-5 text-stone-500">На сцене: клик выбирает узел, двойной клик открывает его.</p>
                    </div>
                    <div v-if="assemblyRows.length" class="min-h-0 flex-1 overflow-y-auto p-2">
                        <template v-for="row in assemblyRows" :key="row.id">
                            <div
                                v-if="row.kind === 'group'"
                                class="flex items-center gap-1 rounded-lg px-1 py-1 text-xs hover:bg-stone-100"
                                :class="{ 'bg-emerald-50 text-emerald-900': selectedGroupId === row.group.id }"
                            >
                                <button class="flex min-h-9 min-w-0 flex-1 items-center gap-2 rounded-md px-2 text-left font-semibold" type="button" :title="row.group.name" @click="openAssemblyGroup(row.group.id)"><span class="text-emerald-700" aria-hidden="true">▰</span><span class="min-w-0 flex-1 truncate">{{ row.group.name }}</span><span class="text-lg text-stone-400" aria-hidden="true">›</span></button>
                                <button class="grid size-8 shrink-0 place-items-center rounded text-stone-500 hover:bg-white" type="button" :aria-label="row.group.is_visible ? 'Скрыть узел' : 'Показать узел'" :title="row.group.is_visible ? 'Скрыть узел' : 'Показать узел'" @click="toggleGroupVisibility(row.group)">{{ row.group.is_visible ? '◉' : '○' }}</button>
                            </div>
                            <div
                                v-else
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-xs text-stone-500 hover:bg-stone-100"
                                :class="{ 'bg-emerald-50 text-emerald-900': selectedInstanceId === row.instance.id }"
                            >
                                <button class="flex min-w-0 flex-1 items-center gap-2 text-left" type="button" @click="selectInstance(row.instance.id)"><span>◇</span><span class="min-w-0 flex-1 truncate">{{ row.part.name }}</span><small>#{{ row.instance.id }}</small></button>
                                <button
                                    class="grid size-6 shrink-0 place-items-center rounded text-[11px] text-stone-400 hover:bg-white disabled:cursor-not-allowed disabled:opacity-50"
                                    type="button"
                                    :class="{ 'bg-sky-100 text-sky-700': isInstanceGhosted(row.instance.id) }"
                                    :disabled="isInstanceGhostedByGroup(row.instance)"
                                    :title="isInstanceGhostedByGroup(row.instance) ? 'Прозрачность задана родительским узлом' : (isInstanceGhosted(row.instance.id) ? 'Вернуть обычный вид детали' : 'Сделать деталь полупрозрачной')"
                                    @click="toggleInstanceGhost(row.instance.id)"
                                >◐</button>
                            </div>
                        </template>
                    </div>
                    <div v-else class="p-5 text-center text-xs leading-5 text-stone-400">
                        {{ assemblySearch.trim() ? 'На этом уровне ничего не найдено.' : activeAssemblyGroup ? 'Этот узел пока пуст. Добавьте деталь из заготовок или создайте вложенный узел.' : 'Сборка пока пуста. Создайте узел или добавьте деталь из заготовок.' }}
                    </div>
                </template>
            </aside>

            <main class="relative min-h-0 min-w-0">
                <div class="view-tabs">
                    <template v-if="editorMode === 'assembly'">
                        <button class="view-tab" :class="{ 'view-tab-active': activeAssemblyGroupId === null }" type="button" @click="openAssemblyGroup(null)">Всё изделие</button>
                        <button v-for="group in assemblyBreadcrumbItems" :key="group.id" class="view-tab" :class="{ 'view-tab-active': group.id === activeAssemblyGroupId }" type="button" @click="openAssemblyGroup(group.id)">› {{ group.name }}</button>
                        <span v-if="isolatedGroup" class="ml-2 self-center rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-semibold text-amber-700">Solo: {{ isolatedGroup.name }}</span>
                        <div class="ml-auto flex items-center gap-2 px-2">
                            <button class="view-tab disabled:opacity-50" :class="{ 'view-tab-active': fastenerTool.active }" type="button" :aria-pressed="fastenerTool.active" :disabled="explodeDistance > 0" @click="toggleFastenerTool">Крепёж</button>
                            <button v-if="diagnosticFocusInstanceIds.length" class="view-tab view-tab-active" type="button" title="Вернуть отображение всей сборки" @click="clearDiagnosticFocus">Сбросить фокус</button>
                            <button class="view-tab" :class="{ 'view-tab-active': measurementTool === 'distance' }" type="button" @click="toggleMeasurement('distance')">Рулетка</button>
                            <button class="view-tab" :class="{ 'view-tab-active': measurementTool === 'angle' }" type="button" @click="toggleMeasurement('angle')">Угол</button>
                            <button class="view-tab" :class="{ 'view-tab-active': sectionAxis }" type="button" @click="sectionAxis = sectionAxis ? null : 'x'">Сечение</button>
                            <button class="view-tab" :class="{ 'view-tab-active': explodeDistance > 0 }" type="button" @click="explodeDistance = explodeDistance > 0 ? 0 : 300">{{ explodeDistance > 0 ? 'Собрать вид' : 'Разнести' }}</button>
                            <label v-if="explodeDistance > 0" class="flex items-center gap-2 text-[10px] font-semibold text-stone-400">Расстояние
                                <input v-model.number="explodeDistance" class="w-28 accent-emerald-700" type="range" min="50" max="1000" step="25">
                            </label>
                        </div>
                        <button class="view-tab" type="button" disabled>Спереди</button>
                        <button class="view-tab" type="button" disabled>Сверху</button>
                    </template>
                    <template v-else-if="activePart">
                        <button class="view-tab" :class="{ 'view-tab-active': !partContextInstance }" :aria-pressed="!partContextInstance" type="button" @click="partViewMode = 'solo'">Отдельно</button>
                        <button class="view-tab disabled:opacity-50" :class="{ 'view-tab-active': partContextInstance }" :aria-pressed="Boolean(partContextInstance)" :disabled="!activePart.instances?.length" type="button" @click="partViewMode = 'assembly'">В сборке</button>
                        <label v-if="partContextInstance && activePart.instances.length > 1" class="flex shrink-0 items-center gap-2 px-2 text-xs text-stone-600">
                            Экземпляр
                            <select v-model.number="partContextInstanceId" class="max-w-36 rounded-md border border-stone-200 bg-white px-1.5 py-1">
                                <option v-for="instance in activePart.instances" :key="instance.id" :value="instance.id">#{{ instance.id }}{{ instance.mirrored ? ' · зеркало' : '' }}</option>
                            </select>
                        </label>
                    </template>
                </div>

                <div v-if="editorMode === 'assembly' && sectionAxis" class="absolute right-4 top-14 z-4 grid w-64 gap-3 rounded-xl border border-stone-200 bg-white/95 p-4 shadow-xl backdrop-blur">
                    <div class="flex items-center justify-between gap-3"><strong class="text-xs text-stone-700">Плоскость сечения</strong><button class="text-lg leading-none text-stone-400 hover:text-stone-700" type="button" aria-label="Закрыть сечение" @click="sectionAxis = null">×</button></div>
                    <div class="grid grid-cols-3 gap-1 rounded-lg bg-stone-100 p-1">
                        <button v-for="axis in axes" :key="axis" class="rounded-md py-1.5 text-xs font-bold uppercase text-stone-400" :class="{ 'bg-white text-emerald-800 shadow-sm': sectionAxis === axis }" type="button" @click="sectionAxis = axis">{{ axis }}</button>
                    </div>
                    <label class="grid gap-1.5 text-[10px] font-semibold text-stone-500">Смещение, мм
                        <input v-model.number="sectionOffset" class="w-full accent-amber-600" type="range" min="-5000" max="5000" step="10">
                        <input v-scrub-number v-model.number="sectionOffset" class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 font-mono text-xs outline-none focus:border-emerald-500" type="number" step="1">
                    </label>
                    <label class="flex items-center gap-2 text-xs text-stone-600"><input v-model="sectionInverted" class="accent-emerald-700" type="checkbox">Показать противоположную сторону</label>
                </div>

                <div v-if="editorMode === 'assembly' && assemblyGuideActive && currentAssemblyGuideStep" class="absolute right-4 bottom-14 z-4 w-72 rounded-xl border border-emerald-900/15 bg-white/95 p-4 shadow-xl backdrop-blur">
                    <div class="flex items-start justify-between gap-3">
                        <div><small class="font-semibold text-emerald-700">Шаг {{ assemblyGuideStep + 1 }} из {{ assemblyGuideSteps.length }}</small><strong class="mt-1 block text-sm text-stone-800">{{ currentAssemblyGuideStep.name }}</strong></div>
                        <button class="text-lg leading-none text-stone-400 hover:text-stone-700" type="button" aria-label="Закрыть режим сборки" @click="finishAssemblyGuide">×</button>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-stone-500">Добавьте {{ currentAssemblyGuideStep.instanceIds.length }} дет. этого узла. Уже пройденные шаги остаются на сцене полупрозрачными.</p>
                    <div class="mt-3 flex items-center gap-2">
                        <button class="button-secondary button-compact flex-1" type="button" :disabled="assemblyGuideStep === 0" @click="setAssemblyGuideStep(assemblyGuideStep - 1)">Назад</button>
                        <button class="button-primary button-compact flex-1" type="button" @click="assemblyGuideStep === assemblyGuideSteps.length - 1 ? finishAssemblyGuide() : setAssemblyGuideStep(assemblyGuideStep + 1)">{{ assemblyGuideStep === assemblyGuideSteps.length - 1 ? 'Готово' : 'Далее' }}</button>
                    </div>
                </div>

                <ThreeViewport
                    :mode="editorMode"
                    :parts="connectionViewportParts"
                    :active-part="partPreview"
                    :part-context-instance="partContextInstance"
                    :part-context-visible-instance-ids="partContextVisibleInstanceIds"
                    :selected-instance-id="selectedInstanceId"
                    :selected-group-instance-ids="groupMoveInstanceIds"
                    :group-move-instance-ids="groupMoveInstanceIds"
                    :group-move-block="groupMoveBlock"
                    :transform-busy="saveState === 'saving' || historyBusy || Boolean(activeConnectionPreview) || fastenerTool.active"
                    :commit-scale="commitScale"
                    :pickable-instance-ids="assemblyPickableInstanceIds"
                    :selected-operation-id="selectedOperationId"
                    :visible-instance-ids="viewportVisibleInstanceIds"
                    :locked-instance-ids="assemblyInstanceState.lockedInstanceIds"
                    :ghosted-instance-ids="viewportGhostedInstanceIds"
                    :explode-distance="explodeDistance"
                    :focused-instance-ids="viewportFocusedInstanceIds"
                    :focus-request-id="diagnosticFocusRequestId"
                    :focused-fasteners="diagnosticFastenerFocus"
                    :measurement-tool="measurementTool"
                    :measurement-reset-id="measurementResetId"
                    :section-axis="sectionAxis"
                    :section-offset="sectionOffset"
                    :section-inverted="sectionInverted"
                    :connections="projects.connections"
                    :selected-connection-id="selectedConnectionId"
                    :connection-machining-preview="activeConnectionPreview"
                    :fasteners="fastenerTool.items"
                    :fastener-preview="fastenerTool.preview"
                    :assembly-geometry-cache="assemblyGeometryCache"
                    :fastener-machining-preview="fastenerTool.drillPreview"
                    :fastener-mode="fastenerTool.active"
                    :fastener-row-copy="Boolean(fastenerTool.copyDraft)"
                    :selected-fastener-id="fastenerTool.active ? fastenerTool.editingId : null"
                    @pick-fastener-point="fastenerTool.pick"
                    @select-fastener="fastenerTool.edit"
                    @fastener-preview-rendered="fastenerTool.finishDrillPreview"
                    @select-instance="selectAssemblyPick"
                    @open-instance-group="openAssemblyPick"
                    @select-connection="selectConnection"
                    @connection-preview-rendered="finishConnectionMachiningPreview"
                    @update-connection-parameters="updateConnectionParametersFromScene"
                    @commit-connection-parameters="commitConnectionParametersFromScene"
                    @select-operation="selectedOperationId = $event"
                    @update-operation="updateOperationFromScene"
                    @commit-instance-transform="commitInstanceTransform"
                    @commit-group-move="commitGroupMove"
                    @blocked-group-move="groupMoveError = $event"
                />

                <p v-if="groupMoveError" class="scene-transform-hud" role="alert">{{ groupMoveError }}</p>
                <div class="status-bar">
                    <span>Сетка: 50 мм</span>
                    <span>Единицы: мм</span>
                    <span class="ml-auto">{{ editorMode === 'assembly' ? `${totalInstances} деталей на сцене` : 'Редактор заготовки' }}</span>
                </div>
            </main>

            <aside class="inspector-panel" aria-label="Свойства выделения">
                <FastenerPanel v-if="editorMode === 'assembly' && fastenerTool.active" :tool="fastenerTool" :instances="allAssemblyInstances"/>
                <template v-else-if="editorMode === 'part' && partDraft">
                    <div class="inspector-section inspector-context">
                        <span class="inspector-kicker">Заготовка</span>
                        <h2 class="inspector-name truncate" :title="partDraft.name">{{ partDraft.name }}</h2>
                        <p v-if="activePart?.instances?.length > 1" class="mt-1 text-xs text-stone-500">Изменится экземпляров: {{ activePart.instances.length }}</p>
                    </div>
                    <div class="inspector-section">
                        <h2 class="inspector-heading">Размеры, мм</h2>
                        <div class="inspector-values">
                            <label v-for="field in stockDimensionFields" :key="field.key" class="dimension-field"><span>{{ field.label }}</span><input v-scrub-number v-model.number="partDraft.dimensions[field.key]" type="number" min="1"></label>
                        </div>
                    </div>
                    <details class="inspector-section inspector-disclosure">
                        <summary>Данные заготовки</summary>
                        <div class="inspector-disclosure-body grid gap-2">
                            <label class="editor-field inspector-inline-field">Название<input v-model="partDraft.name"></label>
                            <label class="editor-field inspector-inline-field">Материал<input v-model="partDraft.material" placeholder="Например, дуб"></label>
                            <button class="button-secondary button-compact justify-self-start" type="button" @click="openProjectAnalysis(activePart.id)">Материал для раскроя</button>
                            <label class="editor-field inspector-inline-field">Волокна<select v-model="partDraft.grain_axis"><option value="length">Вдоль длины</option><option value="width">Вдоль ширины</option></select></label>
                        </div>
                    </details>

                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">Операции</h2>
                            <span class="operation-badge">{{ partDraft.operations.length }}</span>
                        </div>

                        <div class="operation-add-grid">
                            <button v-for="tool in operationTools" :key="tool.type" type="button" :title="tool.label" :aria-label="tool.label" @click="addOperation(tool.type)"><span aria-hidden="true">{{ tool.icon }}</span>{{ tool.caption }}</button>
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
                                <button class="operation-row-summary" type="button" :title="operationSummary(operation)" :aria-pressed="selectedOperationId === operation.id" @click.stop="selectedOperationId = operation.id"><strong>{{ operationLabel(operation) }}</strong><small>{{ operationSummary(operation) }}</small></button>
                                <div class="operation-row-actions">
                                    <button type="button" title="Выше" :disabled="index === 0" @click.stop="moveOperation(operation.id, -1)">↑</button>
                                    <button type="button" title="Ниже" :disabled="index === currentOperations.length - 1" @click.stop="moveOperation(operation.id, 1)">↓</button>
                                    <button type="button" title="Удалить" @click.stop="removeOperation(operation.id)">×</button>
                                </div>
                            </article>
                        </div>
                        <p v-else class="operation-empty">Новых операций пока нет.</p>

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
                        </div>

                        <template v-if="selectedOperation.type === 'cross_cut'">
                            <label class="editor-field mt-2">Положение от начала, мм<input v-scrub-number v-model.number="selectedOperation.position" type="number" min="0" :max="partDraft.dimensions.length"></label>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="cut-field"><span>Угол в плане</span><div><input v-scrub-number v-model.number="selectedOperation.miter_angle" type="number" min="-60" max="60"><b>°</b></div></label>
                                <label class="cut-field"><span>Наклон диска</span><div><input v-scrub-number v-model.number="selectedOperation.bevel_angle" type="number" min="-45" max="45"><b>°</b></div></label>
                            </div>
                            <label v-if="isFullDepthCut" class="editor-field mt-2">Оставить часть<select v-model="selectedOperation.keep_side"><option value="start">До линии реза</option><option value="end">После линии реза</option></select></label>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Глубина, мм<input v-scrub-number v-model.number="selectedOperation.cut_depth" type="number" min="0.1" :max="partDraft.dimensions.thickness" step="0.1"></label>
                                <label class="editor-field">Направление<select v-model="selectedOperation.cut_direction"><option value="top_down">Сверху вниз</option><option value="bottom_up">Снизу вверх</option></select></label>
                            </div>
                            <label class="editor-field mt-2">Ширина пропила, мм<input v-scrub-number v-model.number="selectedOperation.kerf" type="number" min="0" max="20" step="0.1"></label>
                            </template>

                        <template v-else-if="selectedOperation.type === 'rip_cut'">
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Базовая кромка<select v-model="selectedOperation.reference_side"><option value="left">Левая</option><option value="right">Правая</option></select></label>
                                <label v-if="isFullDepthCut" class="editor-field">Что оставить<select v-model="selectedOperation.keep_side"><option value="opposite">Внутреннюю часть</option><option value="reference">Часть у базы</option></select></label>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Отступ в начале, мм<input v-scrub-number v-model.number="selectedOperation.start_offset" type="number" min="0" :max="partDraft.dimensions.width"></label>
                                <label class="editor-field">Отступ в конце, мм<input v-scrub-number v-model.number="selectedOperation.end_offset" type="number" min="0" :max="partDraft.dimensions.width"></label>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="cut-field"><span>Наклон диска</span><div><input v-scrub-number v-model.number="selectedOperation.bevel_angle" type="number" min="-45" max="45"><b>°</b></div></label>
                                <label class="editor-field">Пропил, мм<input v-scrub-number v-model.number="selectedOperation.kerf" type="number" min="0" max="20" step="0.1"></label>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Глубина, мм<input v-scrub-number v-model.number="selectedOperation.cut_depth" type="number" min="0.1" :max="partDraft.dimensions.thickness" step="0.1"></label>
                                <label class="editor-field">Направление<select v-model="selectedOperation.cut_direction"><option value="top_down">Сверху вниз</option><option value="bottom_up">Снизу вверх</option></select></label>
                            </div>
                            </template>

                        <template v-else-if="selectedOperation.type === 'groove'">
                            <label class="editor-field mt-2">Рабочая поверхность
                                <select :value="selectedOperation.face" @change="setGrooveFace($event.target.value)">
                                    <option value="top">Верхняя пласть</option>
                                    <option value="bottom">Нижняя пласть</option>
                                    <option value="left">Левая кромка</option>
                                    <option value="right">Правая кромка</option>
                                    <option value="start">Начальный торец</option>
                                    <option value="end">Конечный торец</option>
                                </select>
                            </label>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <button class="button-secondary" type="button" @click="setGrooveDirection(0)">Вдоль</button>
                                <button class="button-secondary" type="button" @click="setGrooveDirection(90)">Поперёк</button>
                                <label class="cut-field"><span>Угол</span><div><input v-scrub-number v-model.number="selectedOperation.path_angle" type="number" min="-180" max="180" step="1"><b>°</b></div></label>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Центр U, мм<input v-scrub-number v-model.number="selectedOperation.center_u" type="number" min="0" :max="grooveSurfaceSize.u" step="0.1"></label>
                                <label class="editor-field">Центр V, мм<input v-scrub-number v-model.number="selectedOperation.center_v" type="number" min="0" :max="grooveSurfaceSize.v" step="0.1"></label>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Длина, мм<input v-scrub-number v-model.number="selectedOperation.groove_length" type="number" min="0.1" :max="Math.max(grooveSurfaceSize.u, grooveSurfaceSize.v)" step="0.1"></label>
                                <label class="editor-field">Ширина, мм<input v-scrub-number v-model.number="selectedOperation.width" type="number" min="0.1" step="0.1"></label>
                            </div>
                            <label class="editor-field mt-2">Глубина, мм<input v-scrub-number v-model.number="selectedOperation.depth" type="number" min="0.1" :max="grooveSurfaceSize.depth" step="0.1"></label>
                            <label class="editor-field mt-2">Диаметр диска, мм<input v-scrub-number v-model.number="selectedOperation.blade_diameter" type="number" min="1"></label>
                            </template>

                        <template v-else-if="selectedOperation.type === 'edge_roundover'">
                            <label class="editor-field mt-2">Кромка
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
                            <label class="editor-field mt-2">Радиус скругления, мм
                                <input :value="selectedOperation.radius" type="range" min="0.1" :max="roundoverMaximumRadius" step="0.1" @input="setRoundoverRadius($event.target.value)">
                            </label>
                            <label class="cut-field mt-2"><span>Точный радиус</span><div><input v-scrub-number :value="selectedOperation.radius" type="number" min="0.1" :max="roundoverMaximumRadius" step="0.1" @scrub-value="setRoundoverRadius($event.target.value)" @change="setRoundoverRadius($event.target.value)"><b>мм</b></div></label>
                            </template>

                        <template v-else-if="selectedOperation.type === 'plunge_route'">
                            <label class="editor-field mt-2">Рабочая поверхность
                                <select :value="selectedOperation.face" @change="setPlungeFace($event.target.value)">
                                    <option value="top">Верхняя пласть</option>
                                    <option value="bottom">Нижняя пласть</option>
                                    <option value="left">Левая кромка</option>
                                    <option value="right">Правая кромка</option>
                                    <option value="start">Начальный торец</option>
                                    <option value="end">Конечный торец</option>
                                </select>
                            </label>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <button class="button-secondary" :class="{ active: selectedOperation.route_mode === 'point' }" type="button" @click="setPlungeMode('point')">Погружение</button>
                                <button class="button-secondary" :class="{ active: selectedOperation.route_mode === 'path' }" type="button" @click="setPlungeMode('path')">Проход</button>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Профиль фрезы
                                    <select :value="selectedOperation.cutter_profile" @change="setPlungeProfile($event.target.value)">
                                        <option value="straight">Прямая</option>
                                        <option value="dovetail">Ласточкин хвост</option>
                                        <option value="v_groove">V-образная</option>
                                    </select>
                                </label>
                                <label v-if="selectedOperation.cutter_profile !== 'straight'" class="cut-field">
                                    <span>{{ selectedOperation.cutter_profile === 'dovetail' ? 'Угол боковой стенки' : 'Угол раскрытия' }}</span>
                                    <div><input v-scrub-number :value="selectedOperation.cutter_angle" type="number" :min="selectedOperation.cutter_profile === 'dovetail' ? 1 : 10" :max="selectedOperation.cutter_profile === 'dovetail' ? 45 : 170" step="1" @change="setPlungeAngle($event.target.value)"><b>°</b></div>
                                </label>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Начало U, мм<input v-scrub-number v-model.number="selectedOperation.start_u" type="number" min="0" :max="grooveSurfaceSize.u" step="0.1"></label>
                                <label class="editor-field">Начало V, мм<input v-scrub-number v-model.number="selectedOperation.start_v" type="number" min="0" :max="grooveSurfaceSize.v" step="0.1"></label>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">{{ selectedOperation.cutter_profile === 'straight' ? 'Диаметр фрезы, мм' : 'Макс. диаметр, мм' }}<input v-scrub-number :value="selectedOperation.cutter_diameter" type="number" min="0.1" :max="Math.min(grooveSurfaceSize.u, grooveSurfaceSize.v)" step="0.1" @change="setPlungeDiameter($event.target.value)"></label>
                                <label class="editor-field">Глубина, мм<input v-scrub-number :value="selectedOperation.depth" type="number" min="0.1" :max="plungeMaximumDepth" step="0.1" @scrub-value="setPlungeDepth($event.target.value)" @change="setPlungeDepth($event.target.value)"></label>
                            </div>
                            <div v-if="selectedOperation.route_mode === 'path'" class="mt-2 grid grid-cols-2 gap-2">
                                <label class="cut-field"><span>Угол прохода</span><div><input v-scrub-number v-model.number="selectedOperation.path_angle" type="number" min="-180" max="180" step="1"><b>°</b></div></label>
                                <label class="editor-field">Расстояние прохода, мм<input v-scrub-number v-model.number="selectedOperation.travel_length" type="number" min="0.1" :max="Math.hypot(grooveSurfaceSize.u, grooveSurfaceSize.v)" step="0.1"></label>
                            </div>
                            </template>

                        <template v-else-if="selectedOperation.type === 'drill'">
                            <label class="editor-field mt-2">Рабочая поверхность
                                <select :value="selectedOperation.face" @change="setDrillFace($event.target.value)">
                                    <option value="top">Верхняя пласть</option>
                                    <option value="bottom">Нижняя пласть</option>
                                    <option value="left">Левая кромка</option>
                                    <option value="right">Правая кромка</option>
                                    <option value="start">Начальный торец</option>
                                    <option value="end">Конечный торец</option>
                                </select>
                            </label>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Центр U, мм<input v-scrub-number v-model.number="selectedOperation.center_u" type="number" min="0" :max="grooveSurfaceSize.u" step="0.1"></label>
                                <label class="editor-field">Центр V, мм<input v-scrub-number v-model.number="selectedOperation.center_v" type="number" min="0" :max="grooveSurfaceSize.v" step="0.1"></label>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="editor-field">Диаметр, мм<input v-scrub-number :value="selectedOperation.diameter" type="number" min="0.1" :max="Math.min(grooveSurfaceSize.u, grooveSurfaceSize.v)" step="0.1" @change="setDrillDiameter($event.target.value)"></label>
                                <label class="editor-field">Глубина, мм<input v-scrub-number :value="selectedOperation.through ? grooveSurfaceSize.depth : selectedOperation.depth" type="number" min="0.1" :max="grooveSurfaceSize.depth" step="0.1" :disabled="selectedOperation.through" @scrub-value="setDrillDepth($event.target.value)" @change="setDrillDepth($event.target.value)"></label>
                            </div>
                            <label class="mt-2 flex items-center gap-2 text-sm font-semibold text-stone-600">
                                <input :checked="selectedOperation.through" type="checkbox" @change="setDrillThrough($event.target.checked)">
                                Сквозное отверстие
                            </label>
                            <label v-if="selectedOperation.bottom_diameter != null" class="editor-field mt-2">Диаметр на дне, мм
                                <input v-scrub-number :value="selectedOperation.bottom_diameter" type="number" min="0.1" :max="selectedOperation.diameter - 0.01" step="0.01" @change="setDrillBottomDiameter($event.target.value)">
                            </label>
                        </template>
                        <details v-if="operationHelp[selectedOperation.type]" :key="selectedOperation.type" class="inspector-operation-help">
                            <summary>Подсказка по инструменту</summary>
                            <p>{{ operationHelp[selectedOperation.type] }}</p>
                        </details>
                    </div>

                    <div class="inspector-section grid gap-2">
                        <button class="button-primary button-compact w-full" type="button" :disabled="saveState === 'saving' || currentOperations.length === 0" @click="savePart">
                            {{ saveState === 'saving' ? 'Применяем…' : `Применить операции ×${currentOperations.length}` }}
                        </button>
                        <button class="danger-button inspector-text-action" type="button" @click="removePart">Удалить заготовку</button>
                    </div>
                </template>

                <template v-else-if="selectedGroup">
                    <div class="inspector-section inspector-context">
                        <div class="flex items-center justify-between gap-3">
                            <span class="inspector-kicker">Сборочный узел</span>
                            <span class="status-pill">{{ selectedGroupDescendantCount }} деталей</span>
                        </div>
                        <h2 class="inspector-name truncate" :title="selectedGroup.name">{{ selectedGroup.name }}</h2>
                        <div class="inspector-actions" role="group" aria-label="Действия с узлом">
                            <InspectorAction v-if="selectedGroup.id !== activeAssemblyGroupId" icon="enter" label="Открыть узел" caption="Открыть" @click="openAssemblyGroup(selectedGroup.id)" />
                            <InspectorAction :icon="selectedGroup.is_visible ? 'eye' : 'eyeOff'" label="Показывать узел" :hint="selectedGroup.is_visible ? 'Скрыть узел' : 'Показать узел'" :pressed="selectedGroup.is_visible" @click="toggleGroupVisibility(selectedGroup)" />
                            <InspectorAction icon="lock" label="Заблокировать детали узла" :hint="selectedGroup.is_locked ? 'Разблокировать детали' : 'Заблокировать детали'" :pressed="selectedGroup.is_locked" @click="selectedGroup.is_locked = !selectedGroup.is_locked; saveSelectedGroup()" />
                            <InspectorAction icon="ghost" label="Полупрозрачный узел" :pressed="isGroupGhosted(selectedGroup.id)" @click="toggleGroupGhost(selectedGroup.id)" />
                            <InspectorAction icon="isolate" label="Изолировать узел" :hint="isolatedGroupId === selectedGroup.id ? 'Показать окружение' : 'Изолировать узел'" :pressed="isolatedGroupId === selectedGroup.id" @click="toggleGroupIsolation(selectedGroup.id)" />
                        </div>
                    </div>
                    <div class="inspector-section grid gap-2">
                        <label class="editor-field inspector-inline-field">Название<input v-model="selectedGroup.name" maxlength="255" @change="saveSelectedGroup"></label>
                        <label class="editor-field inspector-inline-field">Родитель
                            <select :value="selectedGroup.parent_id ?? ''" @change="moveSelectedGroup($event.target.value)">
                                <option value="">Всё изделие</option>
                                <option v-for="group in availableGroupParents" :key="group.id" :value="group.id">{{ group.name }}</option>
                            </select>
                        </label>
                    </div>
                    <div class="inspector-section">
                        <button class="danger-button inspector-text-action" type="button" @click="removeSelectedGroup">Удалить узел</button>
                    </div>
                </template>

                <template v-else-if="selectedConnection">
                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3"><h2 class="inspector-heading">Соединение</h2><span class="status-pill">{{ connectionTypeLabel(selectedConnection.type) }}</span></div>
                        <label class="editor-field mt-4">Название<input v-model="selectedConnection.label" placeholder="Например, шип царги" @change="saveSelectedConnection"></label>
                        <label class="editor-field mt-3">Примечание<textarea v-model="selectedConnection.note" class="min-h-20" @change="saveSelectedConnection"></textarea></label>
                        <label class="mirror-toggle mt-4"><input v-model="selectedConnection.is_verified" type="checkbox" @change="saveSelectedConnection"><span>Соединение проверено</span></label>
                    </div>
                    <div class="inspector-section">
                        <h2 class="inspector-heading">Связанные детали</h2>
                        <div class="mt-3 grid gap-2">
                            <button v-for="instanceId in [selectedConnection.primary_instance_id, selectedConnection.secondary_instance_id]" :key="instanceId" class="rounded-xl border border-stone-200 bg-stone-50 p-3 text-left text-xs hover:border-emerald-400" type="button" @click="selectInstance(instanceId)"><strong class="block text-stone-700">{{ instanceName(instanceId) }}</strong><small class="mt-1 block text-stone-400">Экземпляр #{{ instanceId }}</small></button>
                        </div>
                    </div>
                    <div class="inspector-section">
                        <h2 class="inspector-heading">Параметры</h2>
                        <dl class="mt-3 grid gap-2 text-xs">
                            <div v-for="(value, key) in connectionSummaryParameters" :key="key" class="flex items-center justify-between gap-3 rounded-lg bg-stone-50 px-3 py-2"><dt class="text-stone-400">{{ connectionParameterLabel(key) }}</dt><dd class="font-mono font-semibold text-stone-700">{{ connectionParameterValue(key, value) }}</dd></div>
                        </dl>
                        <fieldset v-if="selectedConnection.type !== 'butt'" :disabled="connectionSaving" class="mt-4 grid min-w-0 gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-3">
                            <div class="flex items-center justify-between gap-3"><strong class="text-xs text-emerald-900">Положение на гранях</strong><span class="text-[10px] text-emerald-700">Перетаскивайте ◆ на сцене</span></div>
                            <div v-for="role in ['primary', 'secondary']" :key="role" class="grid grid-cols-2 gap-2">
                                <div class="col-span-2 flex items-center justify-between gap-2 text-[10px] font-semibold uppercase tracking-wide text-stone-400"><span>{{ selectedConnection.type === 'mortise_tenon' ? (role === 'primary' ? 'Шип' : 'Паз') : (role === 'primary' ? 'Первая деталь' : 'Вторая деталь') }}</span><button class="text-emerald-700 hover:text-emerald-900" type="button" @click="centerConnectionOnFace(role)">По центру</button></div>
                                <label class="dimension-field"><span>U, мм</span><input v-scrub-number :value="connectionCenterValue(role, 'u')" type="number" min="0" :max="connectionSurfaceSize(role).u" step="0.1" @change="setConnectionCenter(role, 'u', $event.target.value)"></label>
                                <label class="dimension-field"><span>V, мм</span><input v-scrub-number :value="connectionCenterValue(role, 'v')" type="number" min="0" :max="connectionSurfaceSize(role).v" step="0.1" @change="setConnectionCenter(role, 'v', $event.target.value)"></label>
                            </div>
                            <label v-if="selectedConnection.type !== 'mortise_tenon'" class="editor-field">Угол на поверхности, °
                                <div class="mt-1 flex items-center gap-2"><input class="min-w-0 flex-1 accent-emerald-700" :value="selectedConnection.parameters.joint_angle ?? 0" type="range" min="-180" max="180" step="1" @input="setConnectionParameter('joint_angle', $event.target.value, false)" @change="saveSelectedConnectionParameters"><input v-scrub-number class="w-20" :value="selectedConnection.parameters.joint_angle ?? 0" type="number" min="-180" max="180" step="1" @change="setConnectionParameter('joint_angle', $event.target.value)"></div>
                            </label>
                            <div v-if="selectedConnection.type === 'half_lap'" class="grid grid-cols-2 gap-2"><label class="dimension-field"><span>Длина зоны</span><input v-scrub-number :value="selectedConnection.parameters.joint_length ?? defaultConnectionArea.length" type="number" min="0.1" step="0.1" @change="setConnectionParameter('joint_length', $event.target.value)"></label><label class="dimension-field"><span>Ширина зоны</span><input v-scrub-number :value="selectedConnection.parameters.joint_width ?? defaultConnectionArea.width" type="number" min="0.1" step="0.1" @change="setConnectionParameter('joint_width', $event.target.value)"></label></div>
                            <label v-if="selectedConnection.type === 'dowel'" class="editor-field">Шаг между шкантами, мм<input v-scrub-number :value="selectedConnection.parameters.dowel_spacing ?? Number(selectedConnection.parameters.dowel_diameter ?? 8) * 2" type="number" min="0.1" step="0.1" @change="setConnectionParameter('dowel_spacing', $event.target.value)"></label>
                            <p v-if="selectedConnection.type === 'mortise_tenon'" class="text-xs font-semibold text-emerald-900">Размеры шипа</p>
                            <div v-if="selectedConnection.type === 'mortise_tenon'" class="grid grid-cols-3 gap-2">
                                <label v-for="field in tenonDimensionFields" :key="field.key" class="dimension-field">
                                    <span>{{ field.label }}, мм</span>
                                    <input v-scrub-number :value="selectedConnection.parameters[field.key] ?? field.fallback" type="number" min="0.1" max="10000" step="0.1" @change="setConnectionParameter(field.key, $event.target.value)">
                                </label>
                            </div>
                        </fieldset>
                        <div class="mt-4 rounded-xl border border-stone-200 bg-stone-50 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-xs font-semibold text-stone-600">Обработка деталей</span>
                                <span class="status-pill">{{ connectionMachiningStatusLabel(selectedConnection.machining_status) }}</span>
                            </div>
                            <p class="mt-2 text-xs leading-5 text-stone-400">{{ connectionMachiningDescription(selectedConnection) }}</p>
                            <p v-if="selectedConnection.generated_operations?.length" class="mt-2 text-[10px] font-semibold uppercase tracking-wide text-emerald-600">Создано операций: {{ selectedConnection.generated_operations.length }}</p>
                            <p v-if="connectionError" class="mt-2 text-xs text-red-600">{{ connectionError }}</p>
                            <template v-if="['pending', 'outdated'].includes(selectedConnection.machining_status) && selectedConnection.type === 'mortise_tenon'">
                                <div v-if="activeConnectionPreview" class="mt-2 rounded-lg bg-emerald-50 p-2 text-xs text-emerald-950">
                                    <p class="font-semibold">{{ connectionPreviewReady ? 'Предпросмотр шипа и паза' : 'Строим геометрию…' }}</p>
                                    <p v-if="connectionPreviewReady" class="mt-1">Красным отмечена выборка. Применение обновит обе заготовки.</p>
                                </div>
                                <button v-if="!activeConnectionPreview" class="button-primary mt-3 w-full" type="button" :disabled="connectionSaving" @click="previewSelectedConnectionMachining">{{ connectionSaving ? 'Рассчитываем…' : 'Предпросмотр' }}</button>
                                <template v-else>
                                    <button class="button-primary mt-3 w-full" type="button" :disabled="connectionSaving || !connectionPreviewReady" @click="generateSelectedConnectionMachining">Применить к обеим деталям</button>
                                    <button class="button-secondary mt-2 w-full" type="button" :disabled="connectionSaving" @click="connectionPreview = null">Закрыть предпросмотр</button>
                                </template>
                            </template>
                            <button v-else-if="['pending', 'outdated'].includes(selectedConnection.machining_status)" class="button-primary mt-3 w-full" type="button" :disabled="connectionSaving" @click="generateSelectedConnectionMachining">{{ connectionSaving ? 'Создаём обработку…' : selectedConnection.machining_status === 'outdated' ? 'Пересчитать обработку' : selectedConnection.type === 'butt' ? 'Подтвердить без обработки' : 'Создать обработку заготовок' }}</button>
                            <button v-if="selectedConnection.machining_status !== 'pending'" class="button-secondary mt-2 w-full" type="button" :disabled="connectionSaving" @click="removeSelectedConnectionMachining">Отменить созданную обработку</button>
                        </div>
                        <button class="danger-button mt-5 w-full" type="button" @click="removeSelectedConnection">Удалить соединение</button>
                    </div>
                </template>

                <template v-else-if="selectedInstance && selectedPart">
                    <div class="inspector-section inspector-context">
                        <div class="min-w-0">
                            <span class="inspector-kicker">Деталь · #{{ selectedInstance.id }}</span>
                            <h2 class="inspector-name truncate" :title="selectedPart.name">{{ selectedPart.name }}</h2>
                        </div>
                        <div class="inspector-actions" role="group" aria-label="Действия с деталью">
                            <InspectorAction icon="edit" label="Редактировать заготовку" caption="Заготовка" @click="openPart(selectedPart, selectedInstanceId)" />
                            <InspectorAction icon="ghost" label="Полупрозрачная деталь"
                                :hint="isInstanceGhostedByGroup(selectedInstance) ? 'Прозрачность задана узлом' : 'Переключить прозрачность детали'"
                                :pressed="isInstanceGhosted(selectedInstance.id)" :disabled="isInstanceGhostedByGroup(selectedInstance)"
                                @click="toggleInstanceGhost(selectedInstance.id)" />
                            <InspectorAction icon="copy" label="Дублировать деталь" hint="Дублировать · Shift+D" :disabled="instanceToolsBusy" @click="duplicateSelectedInstance" />
                            <InspectorAction icon="mirror" label="Создать зеркальную копию" :disabled="instanceToolsBusy" @click="mirrorSelectedInstance" />
                        </div>
                    </div>

                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="inspector-heading">Размеры, мм</h3>
                            <span v-if="selectedPart.material" class="min-w-0 truncate text-xs text-stone-600" :title="selectedPart.material">{{ selectedPart.material }}</span>
                        </div>
                        <dl class="inspector-values">
                            <div v-for="field in stockDimensionFields" :key="field.key" class="inspector-readout">
                                <dt>{{ field.label }}</dt><dd>{{ selectedPart.dimensions[field.key] }}</dd>
                            </div>
                        </dl>
                        <label class="editor-field inspector-inline-field mt-2">Узел
                            <select :value="selectedInstance.assembly_group_id ?? ''" @change="assignSelectedInstance($event.target.value)">
                                <option value="">Без узла</option>
                                <option v-for="group in projects.assemblyGroups" :key="group.id" :value="group.id">{{ group.name }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="inspector-heading">Положение</h3>
                            <span class="inspector-shortcuts" title="G — переместить, R — повернуть, S — масштабировать"><kbd>G</kbd><kbd>R</kbd><kbd>S</kbd></span>
                        </div>
                        <div v-for="field in transformFields" :key="field.key" class="inspector-transform-row">
                            <span>{{ field.label }}</span>
                            <label v-for="axis in axes" :key="axis" class="inspector-axis" :data-axis="axis">
                                <span aria-hidden="true">{{ axis.toUpperCase() }}</span>
                                <input v-scrub-number v-model.number="selectedInstance[field.key][axis]" :aria-label="`${field.label}, ${axis.toUpperCase()}`" type="number" :disabled="instanceInspectorSaving" @scrub-start="beginInstanceInspectorEdit" @focus="beginInstanceInspectorEdit" @change="saveInstance">
                            </label>
                        </div>
                        <label class="mirror-toggle mt-2"><input v-model="selectedInstance.mirrored" type="checkbox" :disabled="instanceInspectorSaving" @focus="beginInstanceInspectorEdit" @pointerdown="beginInstanceInspectorEdit" @change="saveInstance"><span>Зеркальный экземпляр</span></label>
                    </div>
                    <AssemblyPlacementPanel :key="selectedInstance.id" :source="{ part: selectedPart, instance: selectedInstance }" :parts="projects.parts" :disabled="explodeDistance > 0 || assemblyInstanceState.lockedInstanceIds.includes(selectedInstance.id)" :commit="commitInstanceTransform" />

                    <details class="inspector-section inspector-disclosure">
                        <summary>Линейный массив</summary>

                        <div class="inspector-disclosure-body">
                            <div class="grid grid-cols-3 gap-2">
                                <label class="dimension-field"><span>Ось</span><select v-model="arraySettings.axis" class="min-w-0 rounded-lg border border-stone-200 bg-stone-100 px-2 py-2 text-xs text-stone-600"><option value="x">X</option><option value="y">Y</option><option value="z">Z</option></select></label>
                                <label class="dimension-field"><span>Копий</span><input v-scrub-number v-model.number="arraySettings.copies" type="number" min="1" max="20"></label>
                                <label class="dimension-field"><span>Шаг, мм</span><input v-scrub-number v-model.number="arraySettings.spacing" type="number" min="-100000" max="100000" step="0.1"></label>
                            </div>
                            <button class="button-primary button-compact mt-2 w-full" type="button" :disabled="instanceToolsBusy" @click="createLinearArray">
                                {{ instanceToolsBusy ? 'Создаём…' : `Создать копии ×${arraySettings.copies}` }}
                            </button>
                        </div>
                    </details>

                    <details class="inspector-section inspector-disclosure">
                        <summary>Соединения <span class="operation-badge">{{ selectedInstanceConnections.length }}</span></summary>
                        <div v-if="selectedInstanceConnections.length" class="mt-3 grid gap-2">
                            <button v-for="connection in selectedInstanceConnections" :key="connection.id" class="rounded-xl border border-stone-200 bg-stone-50 p-3 text-left hover:border-emerald-400" type="button" @click="selectConnection(connection.id)"><strong class="block text-xs text-stone-700">{{ connection.label || connectionTypeLabel(connection.type) }}</strong><small class="mt-1 block text-[10px] text-stone-400">с {{ instanceName(otherConnectionInstanceId(connection, selectedInstance.id)) }}</small></button>
                        </div>
                        <form v-if="connectionTargetOptions.length" class="mt-4 grid gap-3 rounded-xl border border-stone-200 bg-stone-50 p-3" @submit.prevent="createConnection">
                            <strong class="text-xs text-stone-700">Новое соединение</strong>
                            <label class="editor-field">Вторая деталь<select v-model.number="connectionDraft.secondary_instance_id" required><option :value="null" disabled>Выберите деталь</option><option v-for="option in connectionTargetOptions" :key="option.id" :value="option.id">{{ option.name }} #{{ option.id }}</option></select></label>
                            <label class="editor-field">Тип<select v-model="connectionDraft.type"><option v-for="(label, type) in connectionTypeLabels" :key="type" :value="type">{{ label }}</option></select></label>
                            <div class="grid grid-cols-2 gap-2"><label class="editor-field">Грань первой<select v-model="connectionDraft.primary_face"><option v-for="(label, face) in connectionFaceLabels" :key="face" :value="face">{{ label }}</option></select></label><label class="editor-field">Грань второй<select v-model="connectionDraft.secondary_face"><option v-for="(label, face) in connectionFaceLabels" :key="face" :value="face">{{ label }}</option></select></label></div>
                            <div v-if="connectionDraft.type === 'half_lap'" class="grid grid-cols-1 gap-2"><label class="editor-field">Доля глубины<input v-scrub-number v-model.number="connectionDraft.depth_ratio" type="number" min="0.1" max="0.9" step="0.05"></label></div>
                            <div v-else-if="connectionDraft.type === 'mortise_tenon'" class="grid grid-cols-3 gap-2"><label class="dimension-field"><span>Ширина</span><input v-scrub-number v-model.number="connectionDraft.tenon_width" type="number" min="0.1"></label><label class="dimension-field"><span>Толщина</span><input v-scrub-number v-model.number="connectionDraft.tenon_thickness" type="number" min="0.1"></label><label class="dimension-field"><span>Длина</span><input v-scrub-number v-model.number="connectionDraft.tenon_length" type="number" min="0.1"></label></div>
                            <div v-else-if="connectionDraft.type === 'dowel'" class="grid grid-cols-3 gap-2"><label class="dimension-field"><span>Ø, мм</span><input v-scrub-number v-model.number="connectionDraft.dowel_diameter" type="number" min="0.1"></label><label class="dimension-field"><span>Кол-во</span><input v-scrub-number v-model.number="connectionDraft.dowel_count" type="number" min="1"></label><label class="dimension-field"><span>Глубина</span><input v-scrub-number v-model.number="connectionDraft.dowel_depth" type="number" min="0.1"></label></div>
                            <label class="editor-field">Название<input v-model="connectionDraft.label" placeholder="Необязательно"></label>
                            <p v-if="connectionError" class="text-xs text-red-600">{{ connectionError }}</p>
                            <button class="button-primary button-compact w-full" type="submit" :disabled="connectionSaving">{{ connectionSaving ? 'Создаём…' : 'Связать детали' }}</button>
                        </form>
                    </details>

                    <div class="inspector-section">
                        <button class="danger-button inspector-text-action" type="button" @click="removeInstance">Удалить со сцены</button>
                    </div>
                </template>

                <template v-else>
                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-4"><h2 class="inspector-heading">Проект</h2><span class="status-pill">Сборка</span></div>
                        <label class="mt-5 block text-xs font-semibold text-stone-500">Описание</label>
                        <textarea v-model="projectDescription" class="editor-textarea" placeholder="Добавьте заметки к проекту" @change="saveProject"></textarea>
                    </div>
                    <div class="inspector-section">
                        <h2 class="inspector-heading">Выбор в сборке</h2>
                        <p class="mt-2 text-xs leading-5 text-stone-500">{{ activeAssemblyGroup ? 'Выберите деталь в открытом узле. Двойной клик открывает вложенный узел.' : 'Выберите узел слева или двойным кликом на сцене. Деталь без узла можно выбрать сразу.' }}</p>
                        <button v-if="!projects.parts.length" class="button-secondary mt-3 w-full" type="button" @click="showCreatePart = true">Создать заготовку</button>
                    </div>
                </template>
            </aside>
        </div>

        <ProjectScriptDialog v-if="showScriptEditor" :project-id="projectId" :apply-script="applyScript" @close="showScriptEditor = false" @detach="clearHistory" />
        <ProjectVersionsDialog v-if="showVersions" :project-id="projectId" :on-restored="reloadRestoredProject" @close="showVersions = false" />
        <div v-if="showCreatePart" class="editor-modal-backdrop" @click.self="showCreatePart = false">
            <form class="editor-modal" @submit.prevent="createPart">
                <div class="flex items-start justify-between gap-4">
                    <div><p class="eyebrow">Новая деталь</p><h2 class="mt-2 text-2xl font-semibold tracking-tight">Создать заготовку</h2></div>
                    <button class="icon-button" type="button" aria-label="Закрыть" @click="showCreatePart = false">×</button>
                </div>
                <div class="mt-7 grid gap-4 sm:grid-cols-2">
                    <label class="field-label sm:col-span-2">Название<input v-model="newPart.name" class="field-input" placeholder="Например, ножка стола" required></label>
                    <label class="field-label sm:col-span-2">Материал<input v-model="newPart.material" class="field-input" placeholder="Дуб, ясень, фанера"></label>
                    <label class="field-label">Длина, мм<input v-scrub-number v-model.number="newPart.length" class="field-input" type="number" min="1" required></label>
                    <label class="field-label">Ширина, мм<input v-scrub-number v-model.number="newPart.width" class="field-input" type="number" min="1" required></label>
                    <label class="field-label">Толщина, мм<input v-scrub-number v-model.number="newPart.thickness" class="field-input" type="number" min="1" required></label>
                    <label class="field-label">Количество на сцене<input v-scrub-number v-model.number="newPart.quantity" class="field-input" type="number" min="0" max="20"></label>
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

        <div v-if="showTemplateCreator" class="editor-modal-backdrop" @click.self="showTemplateCreator = false">
            <form class="editor-modal max-w-lg" aria-labelledby="template-creator-title" @submit.prevent="saveProjectTemplate">
                <div class="flex items-start justify-between gap-4">
                    <div><p class="eyebrow">Повторное использование</p><h2 id="template-creator-title" class="mt-2 text-2xl font-semibold tracking-tight">Сохранить параметрический шаблон</h2></div>
                    <button class="icon-button" type="button" aria-label="Закрыть" @click="showTemplateCreator = false">×</button>
                </div>
                <p class="mt-3 text-sm leading-6 text-stone-500">Сохранятся заготовки, операции, узлы и сборка. При создании проекта размеры можно будет изменить по трём осям.</p>
                <label class="field-label mt-6">Название<input v-model="templateDraft.name" class="field-input" required maxlength="255"></label>
                <label class="field-label mt-4">Описание<textarea v-model="templateDraft.description" class="min-h-24 rounded-xl border border-stone-200 bg-white p-3 text-sm outline-none focus:border-emerald-500" maxlength="5000"></textarea></label>
                <p v-if="templateError" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{{ templateError }}</p>
                <div class="mt-6 flex justify-end gap-3"><button class="button-secondary" type="button" @click="showTemplateCreator = false">Отмена</button><button class="button-primary" type="submit" :disabled="templateSaving">{{ templateSaving ? 'Сохраняем…' : 'Сохранить шаблон' }}</button></div>
            </form>
        </div>

        <div v-if="showProjectAnalysis" class="editor-modal-backdrop" @click.self="showProjectAnalysis = false">
            <section class="editor-modal max-h-[92vh] max-w-6xl overflow-y-auto" aria-labelledby="project-analysis-title">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="eyebrow">Производство</p>
                        <h2 id="project-analysis-title" class="mt-2 text-2xl font-semibold tracking-tight">Анализ, спецификация и раскрой</h2>
                        <p class="mt-2 text-sm leading-6 text-stone-500">Предварительная проверка конструкции и расчёт материала по текущей сборке.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button v-if="projectAnalysis" :disabled="projectAnalysisLoading || Boolean(projectAnalysisError)" class="button-secondary button-compact" type="button" @click="printWorkshopPlan">Карты деталей · PDF</button>
                        <button v-if="projectAnalysis" class="button-secondary button-compact" type="button" @click="exportManufacturingCsv">Скачать CSV</button>
                        <button class="icon-button" type="button" aria-label="Закрыть" @click="showProjectAnalysis = false">×</button>
                    </div>
                </div>

                <ProjectMaterialsPanel
                    :project-id="projectId"
                    :parts="projects.parts"
                    :costing="projectAnalysis?.manufacturing.costing"
                    :settings="analysisEstimateSettings"
                    :analysis-busy="projectAnalysisLoading"
                    :focus-part-id="analysisPartId"
                    @refresh="refreshProjectAnalysis"
                />

                <div v-if="projectAnalysisLoading" class="grid min-h-64 place-items-center">
                    <div class="text-center"><div class="loader mx-auto"></div><p class="mt-4 text-sm text-stone-500">Проверяем конструкцию…</p></div>
                </div>
                <p v-else-if="projectAnalysisError" class="mt-6 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ projectAnalysisError }}</p>

                <template v-else-if="projectAnalysis">
                    <div class="mt-6 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-stone-200 bg-stone-50 p-4"><small class="text-stone-400">Типов деталей</small><strong class="mt-1 block text-xl text-stone-800">{{ projectAnalysis.manufacturing.summary.unique_part_count }}</strong></div>
                        <div class="rounded-xl border border-stone-200 bg-stone-50 p-4"><small class="text-stone-400">Экземпляров</small><strong class="mt-1 block text-xl text-stone-800">{{ projectAnalysis.manufacturing.summary.instance_count }}</strong></div>
                        <div class="rounded-xl border border-stone-200 bg-stone-50 p-4"><small class="text-stone-400">Объём материала</small><strong class="mt-1 block text-xl text-stone-800">{{ formatAnalysisVolume(projectAnalysis.manufacturing.summary.material_volume_mm3) }} м³</strong></div>
                    </div>

                    <section class="mt-5 rounded-xl border border-stone-200 bg-stone-50 p-4">
                        <div class="flex flex-wrap items-end gap-3">
                            <label v-for="field in visibleCuttingSettingFields" :key="field.key" class="grid min-w-32 flex-1 gap-1 text-[10px] font-semibold text-stone-500">{{ field.label }}
                                <input v-scrub-number v-model.number="cuttingSettings[field.key]" class="rounded-lg border border-stone-200 bg-white px-3 py-2 font-mono text-xs outline-none focus:border-emerald-500" type="number" :min="field.min" :max="field.max" :step="field.step">
                            </label>
                            <button class="button-primary button-compact" type="button" :disabled="projectAnalysisLoading" @click="refreshProjectAnalysis">Пересчитать</button>
                        </div>
                    </section>

                    <section class="mt-7">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-base font-semibold text-stone-800">Проверка конструкции</h3>
                            <span class="status-pill">{{ projectAnalysis.diagnostics.warnings.length + projectAnalysis.diagnostics.review_items.length }} замечаний</span>
                        </div>
                        <div v-if="projectAnalysis.diagnostics.warnings.length || projectAnalysis.diagnostics.review_items.length" class="mt-3 grid gap-2 md:grid-cols-2">
                            <button v-for="(warning, index) in projectAnalysis.diagnostics.warnings" :key="`warning-${index}`" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-left transition hover:border-amber-400 hover:shadow-sm" type="button" @click="inspectAnalysisIssue(warning)">
                                <strong class="text-xs text-amber-900">{{ analysisIssueTitle(warning.code) }}</strong>
                                <p class="mt-1 text-xs leading-5 text-amber-700">{{ analysisIssueDetails(warning) }}</p>
                                <small class="mt-2 block text-[10px] font-semibold text-amber-600">Показать на сцене →</small>
                            </button>
                            <button v-for="(item, index) in projectAnalysis.diagnostics.review_items" :key="`review-${index}`" class="rounded-xl border border-sky-200 bg-sky-50 p-3 text-left transition hover:border-sky-400 hover:shadow-sm" type="button" @click="inspectAnalysisIssue(item)">
                                <strong class="text-xs text-sky-900">{{ analysisIssueTitle(item.code) }}</strong>
                                <p class="mt-1 text-xs leading-5 text-sky-700">{{ analysisIssueDetails(item) }}</p>
                                <small class="mt-2 block text-[10px] font-semibold text-sky-600">Показать на сцене →</small>
                            </button>
                        </div>
                        <p v-else class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">Критичных замечаний в предварительной проверке нет.</p>
                    </section>

                    <FastenerDiagnosticsPanel
                        :report="fastenerAnalysis"
                        :loading="fastenerAnalysisLoading"
                        :error="fastenerAnalysisError"
                        @inspect="inspectAnalysisIssue"
                    />

                    <section class="mt-7">
                        <h3 class="text-base font-semibold text-stone-800">Спецификация деталей</h3>
                        <div class="mt-3 overflow-x-auto rounded-xl border border-stone-200">
                            <table class="w-full min-w-[760px] text-left text-xs">
                                <thead class="bg-stone-50 text-stone-400"><tr><th class="px-3 py-2.5">Заготовка</th><th class="px-3 py-2.5">Материал</th><th class="px-3 py-2.5">Размеры, мм</th><th class="px-3 py-2.5">Кол-во</th><th class="px-3 py-2.5">Операций</th></tr></thead>
                                <tbody class="divide-y divide-stone-100 text-stone-600">
                                    <tr v-for="part in projectAnalysis.manufacturing.bill_of_materials" :key="part.part_id">
                                        <td class="px-3 py-2.5 font-semibold text-stone-700"><span class="mr-2 text-emerald-800">{{ partWorkshopCode(part.part_id) }}</span>{{ part.name }}</td>
                                        <td class="px-3 py-2.5">{{ part.material }}</td>
                                        <td class="px-3 py-2.5 font-mono">{{ part.dimensions.length }} × {{ part.dimensions.width }} × {{ part.dimensions.thickness }}</td>
                                        <td class="px-3 py-2.5">×{{ part.quantity }}</td>
                                        <td class="px-3 py-2.5">{{ part.operation_count }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section v-if="projectAnalysis.manufacturing.linear_cutting.length" class="mt-7">
                        <h3 class="text-base font-semibold text-stone-800">Раскрой досок</h3>
                        <BoardCuttingDiagram :groups="projectAnalysis.manufacturing.linear_cutting" class="mt-3"/>
                    </section>

                    <section v-if="projectAnalysis.manufacturing.sheet_cutting.length" class="mt-7">
                        <h3 class="text-base font-semibold text-stone-800">Карта раскроя листов</h3>
                        <div class="mt-3 grid gap-4 lg:grid-cols-2">
                            <article v-for="(cutting, cuttingIndex) in projectAnalysis.manufacturing.sheet_cutting" :key="`sheet-${cuttingIndex}`" class="rounded-xl border border-stone-200 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2"><strong class="text-sm text-stone-700">{{ cutting.material }} · {{ cutting.thickness }} мм</strong><span class="text-xs text-stone-400">{{ cutting.stock.length }} × {{ cutting.stock.width }} мм</span></div>
                                <div v-for="sheet in cutting.sheets" :key="sheet.number" class="mt-3">
                                    <div class="mb-1 flex items-center justify-between gap-2 text-[11px] text-stone-400"><span>Лист №{{ sheet.number }}</span><span>Пригодный остаток {{ formatAnalysisArea(sheet.reusable_area) }} м²</span></div>
                                    <div class="relative aspect-[2/1] overflow-hidden rounded-lg border-2 border-stone-300 bg-stone-50">
                                        <div v-for="placement in sheet.placements" :key="`${placement.part_id}-${placement.piece}`" class="absolute overflow-hidden border border-emerald-800 bg-emerald-200/80 p-1 text-[8px] font-semibold leading-tight text-emerald-900" :style="sheetPlacementStyle(placement, cutting.stock)" :title="`${placement.name}: ${placement.length} × ${placement.width} мм`">{{ partWorkshopCode(placement.part_id) }}</div>
                                    </div>
                                </div>
                                <p v-if="cutting.oversized.length" class="mt-3 text-xs text-red-600">Не помещаются на стандартный лист: {{ cutting.oversized.map((part) => part.name).join(', ') }}</p>
                            </article>
                        </div>
                    </section>

                    <p class="mt-7 rounded-xl bg-stone-50 p-4 text-xs leading-5 text-stone-500">Предварительный расчёт: пропил {{ projectAnalysis.manufacturing.assumptions.kerf_mm }} мм, отступ от края {{ projectAnalysis.manufacturing.assumptions.edge_margin_mm }} мм. Перед изготовлением укажите фактические размеры материала, направление волокон и технологические припуски.</p>
                </template>
            </section>
        </div>
        <Teleport v-if="workshopPrintReport" to="body">
            <WorkshopPrint
                :project-name="projectName"
                :revision="workshopPrintReport.revision"
                :parts="projects.parts"
                :groups="projects.assemblyGroups"
                :report="workshopPrintReport.manufacturing"
            />
        </Teleport>
    </div>
</template>

<script setup>
import { vScrubNumber } from '../editor/scrubNumber.js';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ThreeViewport from '../components/ThreeViewport.vue';
import ProjectScriptDialog from '../components/ProjectScriptDialog.vue';
import ProjectVersionsDialog from '../components/ProjectVersionsDialog.vue';
import ProjectMaterialsPanel from '../components/ProjectMaterialsPanel.vue';
import FastenerPanel from '../components/FastenerPanel.vue';
import FastenerDiagnosticsPanel from '../components/FastenerDiagnosticsPanel.vue';
import { analyzeFasteners } from '../editor/fastenerDiagnostics.js';
import { createFastenerGeometry } from '../editor/fastenerGeometry.js';
import { createResultCache } from '../editor/geometry/resultCache.js';
import { useProjectFasteners } from '../editor/useProjectFasteners.js';
import BoardCuttingDiagram from '../components/BoardCuttingDiagram.vue';
import WorkshopPrint from '../components/WorkshopPrint.vue';
import { partWorkshopCode } from '../editor/workshopPlan.js';
import { connectionMachiningPreviewParts } from '../editor/connections.js';
import AssemblyPlacementPanel from '../components/AssemblyPlacementPanel.vue';
import InspectorAction from '../components/InspectorAction.vue';
import { usePartDraftRecovery } from '../editor/usePartDraftRecovery.js';
import { useAuthStore } from '../stores/auth';
import { useProjectScripts } from '../editor/useProjectScripts.js';
import { assemblyShortcutKey, groupMoveLimit, translatedPosition } from '../editor/assemblyMove.js';
import { planAssemblyScale } from '../editor/assemblyScale.js';
import {
    assemblyBreadcrumbs,
    assemblyInstanceState as calculateAssemblyInstanceState,
    descendantGroupIds,
    assemblyLevelRows,
    resolveAssemblyPick,
} from '../editor/assemblyTree.js';
import { duplicateBlueprint, instanceBlueprintPayload, linearArrayBlueprints, mirroredBlueprint } from '../editor/instanceCopies.js';
import { createOperation, normalizeOperations, operationLabel, operationSummary } from '../editor/operations/catalog.js';
import { fitDrillToFace } from '../editor/operations/drill.js';
import {
    fitPlungeOperationToFace,
    plungeOperationRadius,
    plungeProfileMaximumDepth,
} from '../editor/operations/plungeRoute.js';
import { maximumRoundoverRadius } from '../editor/operations/roundover.js';
import { surfaceDimensions } from '../editor/operations/shared.js';
import { useProjectApiTokens } from '../editor/useProjectApiTokens.js';
import { useEditorHistory } from '../editor/useEditorHistory.js';
import { useProjectsStore } from '../stores/projects';

const route = useRoute();
const router = useRouter();
const projects = useProjectsStore();
const projectId = Number(route.params.id);
const axes = ['x', 'y', 'z'];
const stockDimensionFields = [
    { key: 'length', label: 'Длина' },
    { key: 'width', label: 'Ширина' },
    { key: 'thickness', label: 'Толщина' },
];
const transformFields = [
    { key: 'position', label: 'Позиция, мм' },
    { key: 'rotation', label: 'Поворот, °' },
];
const operationTools = [
    { type: 'cross_cut', icon: '╱', caption: 'Поперёк', label: 'Поперечный рез' },
    { type: 'rip_cut', icon: '↗', caption: 'Вдоль', label: 'Продольный рез' },
    { type: 'groove', icon: '⊔', caption: 'Паз пилой', label: 'Паз пилой' },
    { type: 'edge_roundover', icon: '◜', caption: 'Кромка', label: 'Кромочный фрезер' },
    { type: 'plunge_route', icon: '⌾', caption: 'Фрезер', label: 'Погружной фрезер' },
    { type: 'drill', icon: '●', caption: 'Сверло', label: 'Сверление' },
];
const operationHelp = {
    cross_cut: 'На полной глубине часть отделяется, на меньшей остаётся пропил шириной диска.',
    rip_cut: 'Разные отступы на концах дают продольный рез под углом.',
    groove: 'Выберите грань на сцене. Перетащите паз за красный объём или потяните за ручку, чтобы изменить длину и угол.',
    edge_roundover: 'Кликните рядом с нужной кромкой на сцене. Фрезер пройдёт по всей её длине.',
    plunge_route: 'Выберите грань. Жёлтая ручка задаёт точку погружения, красная — направление и длину прохода.',
    drill: 'Выберите грань и перетащите красный цилиндр к точке сверления.',
};
const loading = ref(true);
const projectName = ref('');
const projectDescription = ref('');
const saveState = ref('saved');
const groupMoveError = ref('');
const editorMode = ref('assembly');
const partViewMode = ref('solo');
const partContextInstanceId = ref(null);
const leftPanelMode = ref('assembly');
const selectedGroupId = ref(null);
const activeAssemblyGroupId = ref(null);
const isolatedGroupId = ref(null);
const ghostedGroupIds = ref([]);
const ghostedInstanceIds = ref([]);
const explodeDistance = ref(0);
const diagnosticFocusInstanceIds = ref([]);
const diagnosticFocusRequestId = ref(0);
const diagnosticFastenerFocus = ref(null);
const measurementTool = ref(null);
const measurementResetId = ref(0);
const sectionAxis = ref(null);
const sectionOffset = ref(0);
const sectionInverted = ref(false);
const assemblyGuideActive = ref(false);
const assemblyGuideStep = ref(0);
const assemblySearch = ref('');
const selectedPartId = ref(null);
const selectedInstanceId = ref(null);
const selectedConnectionId = ref(null);
const selectedOperationId = ref(null);
const partDraft = ref(null);
const historyExpanded = ref(false);
const showCreatePart = ref(false);
const showScriptEditor = ref(false);
const showVersions = ref(false);
const creatingPart = ref(false);
const createError = ref('');
const showProjectAnalysis = ref(false);
const workshopPrintReport = ref(null);
const analysisPartId = ref(null);
const showTemplateCreator = ref(false);
const templateSaving = ref(false);
const templateError = ref('');
const templateDraft = reactive({ name: '', description: '' });
const connectionSaving = ref(false);
const connectionPreview = ref(null);
const connectionPreviewReady = ref(false);
const tenonDimensionFields = [
    { key: 'tenon_width', label: 'Ширина', fallback: 30 },
    { key: 'tenon_thickness', label: 'Толщина', fallback: 10 },
    { key: 'tenon_length', label: 'Длина', fallback: 20 },
];
const connectionError = ref('');
const connectionDraft = reactive({
    secondary_instance_id: null,
    type: 'butt',
    label: '',
    primary_face: 'end',
    secondary_face: 'start',
    depth_ratio: 0.5,
    tenon_width: 30,
    tenon_thickness: 10,
    tenon_length: 20,
    dowel_diameter: 8,
    dowel_count: 2,
    dowel_depth: 25,
    dowel_spacing: 16,
    joint_angle: 0,
    joint_length: 50,
    joint_width: 40,
});
const connectionTypeLabels = {
    butt: 'Стыковое',
    half_lap: 'Вполдерева',
    mortise_tenon: 'Шип–паз',
    dowel: 'На шкантах',
};
const connectionFaceLabels = {
    top: 'Верх',
    bottom: 'Низ',
    left: 'Левая',
    right: 'Правая',
    start: 'Начало',
    end: 'Торец',
};
const projectAnalysis = ref(null);
const projectAnalysisLoading = ref(false);
const projectAnalysisError = ref('');
const fastenerAnalysisLoading = ref(false);
const fastenerAnalysisError = ref('');
const cuttingSettings = reactive({
    kerf_mm: 3.2,
    edge_margin_mm: 10,
    linear_stock_length_mm: 6000,
    sheet_length_mm: 2440,
    sheet_width_mm: 1220,
});
const cuttingSettingFields = [
    { key: 'kerf_mm', label: 'Пропил, мм', min: 0, max: 20, step: 0.1 },
    { key: 'edge_margin_mm', label: 'Отступ, мм', min: 0, max: 500, step: 1 },
    { key: 'linear_stock_length_mm', label: 'Длина хлыста, мм', min: 100, max: 50000, step: 10 },
    { key: 'sheet_length_mm', label: 'Длина листа, мм', min: 100, max: 10000, step: 10 },
    { key: 'sheet_width_mm', label: 'Ширина листа, мм', min: 100, max: 10000, step: 10 },
];
const visibleCuttingSettingFields = computed(() => projectAnalysis.value?.manufacturing.costing?.configured
    ? cuttingSettingFields.filter(field => ['kerf_mm', 'edge_margin_mm'].includes(field.key))
    : cuttingSettingFields);
const analysisEstimateSettings = computed(() => {
    const used = projectAnalysis.value?.manufacturing.assumptions;
    return used ? {
        kerf_mm: used.kerf_mm,
        edge_margin_mm: used.edge_margin_mm,
        linear_stock_length_mm: used.linear_stock_length_mm,
        sheet_length_mm: used.sheet_size_mm.length,
        sheet_width_mm: used.sheet_size_mm.width,
    } : cuttingSettings;
});
const instanceQuantities = reactive({});
const newPart = reactive({ name: '', material: '', length: 720, width: 60, thickness: 60, quantity: 0 });
const arraySettings = reactive({ axis: 'x', copies: 3, spacing: 100 });
const instanceToolsBusy = ref(false);
const {
    busy: historyBusy,
    canRedo,
    canUndo,
    record: recordHistory,
    clear: clearHistory,
    redo: redoHistory,
    redoLabel,
    undo: undoHistory,
    undoLabel,
} = useEditorHistory();
const assemblyGeometryCache = createResultCache();
const fastenerGeometry = createFastenerGeometry(assemblyGeometryCache);
const fastenerTool = reactive(useProjectFasteners(
    projectId, () => projects.parts, recordHistory, () => projects.fetchParts(projectId), () => viewportVisibleInstanceIds.value, fastenerGeometry,
));
const fastenerAnalysisPlan = computed(() => showProjectAnalysis.value
    ? fastenerGeometry.evaluate(materialFor => analyzeFasteners(fastenerTool.items, projects.parts, projects.assemblyGroups, materialFor))
    : null);
const fastenerAnalysis = computed(() => fastenerAnalysisPlan.value?.result);
watch(() => fastenerAnalysisPlan.value?.needed, needed => {
    if (needed) fastenerGeometry.prepare(needed);
});
watch(() => projects.parts.map(part => part.id), fastenerGeometry.retain);
const { applyScript } = useProjectScripts({
    projectId, projects, recordHistory,
    onApplied: async () => {
        selectedInstanceId.value = null;
        selectedOperationId.value = null;
        selectedPartId.value = null;
        partDraft.value = null;
        editorMode.value = 'assembly';
        await resetPartHistoryBaseline();
    },
});
const openScriptEditor = async () => {
    if (editorMode.value === 'part') {
        if (!window.confirm('Сохранить текущую заготовку и открыть сценарий?')) return;
        await savePart();
        if (saveState.value === 'error') return;
    }
    flushPartHistory();
    showScriptEditor.value = true;
};
let partHistoryBaseline = null;
let partHistoryTimer = null;
let partHistoryLabel = 'Изменение заготовки';
let suppressPartHistory = false;
let instanceInspectorBaseline = null;
const instanceInspectorSaving = ref(false);

const activePart = computed(() => projects.parts.find((part) => part.id === selectedPartId.value) ?? null);
const partContextInstance = computed(() => editorMode.value === 'part' && partViewMode.value === 'assembly'
    ? activePart.value?.instances?.find((instance) => instance.id === partContextInstanceId.value) ?? null
    : null);
const partContextVisibleInstanceIds = computed(() => partContextInstance.value
    ? calculateAssemblyInstanceState(projects.assemblyGroups, projects.parts).visibleInstanceIds
    : []);
watch(activePart, (part) => {
    const instances = part?.instances ?? [];
    if (!instances.some((instance) => instance.id === partContextInstanceId.value)) {
        partContextInstanceId.value = instances[0]?.id ?? null;
    }
});
const selectedPart = computed(() => projects.parts.find((part) => part.instances?.some((instance) => instance.id === selectedInstanceId.value)) ?? null);
const selectedInstance = computed(() => selectedPart.value?.instances.find((instance) => instance.id === selectedInstanceId.value) ?? null);
const selectedConnection = computed(() => projects.connections.find((connection) => connection.id === selectedConnectionId.value) ?? null);
const activeConnectionPreview = computed(() => editorMode.value === 'assembly'
    && selectedConnection.value?.id === connectionPreview.value?.connectionId
    ? connectionPreview.value
    : null);
const connectionViewportParts = computed(() => connectionMachiningPreviewParts(projects.parts, activeConnectionPreview.value ?? fastenerTool.drillPreview));
watch(() => fastenerTool.drillPreview, async (plan, previous) => {
    if (plan) {
        setDiagnosticFocus(plan.parts.map(part => part.instance_id));
        await nextTick();
        diagnosticFocusRequestId.value++;
    } else if (previous && !diagnosticFastenerFocus.value) {
        clearDiagnosticFocus();
    }
});
watch([editorMode, selectedConnectionId, () => selectedConnection.value?.parameters], () => {
    connectionPreview.value = null;
}, { deep: true });
watch(selectedConnectionId, () => { connectionError.value = ''; });
const libraryVisible = ref(window.innerWidth > 900);
const inspectorVisible = ref(window.innerWidth > 900);
const toggleWorkspacePanel = (panel) => {
    const target = panel === 'library' ? libraryVisible : inspectorVisible;
    target.value = !target.value;

    if (target.value && window.innerWidth <= 900) {
        (panel === 'library' ? inspectorVisible : libraryVisible).value = false;
    }
};
const partSearch = ref('');
const filteredLibraryParts = computed(() => projects.parts.filter((part) => `${part.name} ${part.material ?? ''}`.toLocaleLowerCase().includes(partSearch.value.trim().toLocaleLowerCase())));
const connectionSummaryParameters = computed(() => Object.fromEntries(Object.entries(selectedConnection.value?.parameters ?? {}).filter(([key]) => ![
    'primary_center_u',
    'primary_center_v',
    'secondary_center_u',
    'secondary_center_v',
    'joint_angle',
    'joint_length',
    'joint_width',
    'dowel_spacing',
    'tenon_width',
    'tenon_thickness',
    'tenon_length',
].includes(key))));
const allAssemblyInstances = computed(() => projects.parts.flatMap((part) => (part.instances ?? []).map((instance) => ({
    ...instance,
    name: part.name,
}))));
const connectionTargetOptions = computed(() => allAssemblyInstances.value.filter((instance) => instance.id !== selectedInstanceId.value));
const selectedInstanceConnections = computed(() => projects.connections.filter((connection) => (
    connection.primary_instance_id === selectedInstanceId.value || connection.secondary_instance_id === selectedInstanceId.value
)));
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
const ghostedGroupScopeIds = computed(() => new Set(ghostedGroupIds.value.flatMap((groupId) => (
    descendantGroupIds(projects.assemblyGroups, groupId)
))));
const ghostedAssemblyInstanceIdSet = computed(() => {
    const instanceIds = new Set(ghostedInstanceIds.value);

    projects.parts.forEach((part) => {
        (part.instances ?? []).forEach((instance) => {
            if (instance.assembly_group_id !== null && ghostedGroupScopeIds.value.has(instance.assembly_group_id)) {
                instanceIds.add(instance.id);
            }
        });
    });

    return instanceIds;
});
const assemblyPickableInstanceIds = computed(() => (
    activeAssemblyGroupId.value === null && isolatedGroupId.value === null
        ? null
        : assemblyInstanceState.value.visibleInstanceIds
));
const viewportGhostedInstanceIds = computed(() => {
    const ids = new Set(ghostedAssemblyInstanceIdSet.value);
    if (activeAssemblyGroupId.value !== null && isolatedGroupId.value === null && !assemblyGuideActive.value) {
        const inGroup = new Set(assemblyInstanceState.value.visibleInstanceIds);
        viewportVisibleInstanceIds.value.forEach(id => {
            if (!inGroup.has(id)) ids.add(id);
        });
    }
    return [...ids];
});
const assemblyGuideSteps = computed(() => {
    const steps = [];
    const ungroupedInstanceIds = projects.parts.flatMap((part) => (part.instances ?? [])
        .filter((instance) => instance.assembly_group_id === null)
        .map((instance) => instance.id));

    if (ungroupedInstanceIds.length) {
        steps.push({ id: 'ungrouped', name: 'Основа изделия', instanceIds: ungroupedInstanceIds });
    }

    [...projects.assemblyGroups]
        .sort((left, right) => Number(left.sort_order) - Number(right.sort_order) || left.id - right.id)
        .forEach((group) => {
            const instanceIds = projects.parts.flatMap((part) => (part.instances ?? [])
                .filter((instance) => instance.assembly_group_id === group.id)
                .map((instance) => instance.id));

            if (instanceIds.length) {
                steps.push({ id: group.id, name: group.name, instanceIds });
            }
        });

    return steps;
});
const currentAssemblyGuideStep = computed(() => assemblyGuideSteps.value[assemblyGuideStep.value] ?? null);
const assemblyGuideVisibleInstanceIds = computed(() => assemblyGuideSteps.value
    .slice(0, assemblyGuideStep.value + 1)
    .flatMap((step) => step.instanceIds));
const viewportFocusedInstanceIds = computed(() => assemblyGuideActive.value
    ? currentAssemblyGuideStep.value?.instanceIds ?? []
    : diagnosticFocusInstanceIds.value);
const viewportVisibleInstanceIds = computed(() => {
    let normalInstanceIds = assemblyGuideActive.value
        ? assemblyGuideVisibleInstanceIds.value
        : assemblyInstanceState.value.visibleInstanceIds;

    if (!assemblyGuideActive.value && activeAssemblyGroupId.value !== null && isolatedGroupId.value === null) {
        const global = calculateAssemblyInstanceState(projects.assemblyGroups, projects.parts);
        normalInstanceIds = [...new Set([...normalInstanceIds, ...global.visibleInstanceIds])];
    }

    return diagnosticFocusInstanceIds.value.length
        ? [...new Set([...normalInstanceIds, ...diagnosticFocusInstanceIds.value])]
        : normalInstanceIds;
});
const assemblyRows = computed(() => {
    const rows = assemblyLevelRows(
        projects.assemblyGroups,
        projects.parts,
        activeAssemblyGroupId.value,
    );
    const search = assemblySearch.value.trim().toLocaleLowerCase();

    if (!search) return rows;

    return rows.filter((row) => (row.kind === 'group' ? row.group.name : row.part.name).toLocaleLowerCase().includes(search));
});
const selectedGroupDescendantIds = computed(() => selectedGroup.value
    ? new Set(descendantGroupIds(projects.assemblyGroups, selectedGroup.value.id))
    : new Set());
const groupMoveInstanceIds = computed(() => selectedInstanceId.value !== null ? [] : allAssemblyInstances.value
    .filter(instance => selectedGroupDescendantIds.value.has(instance.assembly_group_id))
    .map(instance => instance.id));
const groupMoveBlock = computed(() => {
    if (!selectedGroup.value || selectedInstanceId.value !== null) return '';
    if (!groupMoveInstanceIds.value.length) return 'В выбранном узле нет деталей.';
    if (groupMoveInstanceIds.value.length > groupMoveLimit) return 'За одно действие можно переместить узел до 100 деталей.';
    const locked = new Set(assemblyInstanceState.value.lockedInstanceIds);
    if (groupMoveInstanceIds.value.some(id => locked.has(id))) return 'В узле есть заблокированные детали. Снимите блокировку перед перемещением.';
    return '';
});
const assemblyInstancesById = computed(() => new Map(allAssemblyInstances.value.map(instance => [instance.id, instance])));
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
const roundoverMaximumRadius = computed(() => maximumRoundoverRadius(selectedOperation.value?.edge, partDraft.value?.dimensions));
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
    if (selectedPartId.value !== state.partId) partViewMode.value = 'solo';
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
    void fastenerTool.reload().catch(() => {});
    try {
        const [project, parts] = await Promise.all([
            projects.fetchProject(projectId),
            projects.fetchParts(projectId),
            projects.fetchAssemblyGroups(projectId),
            projects.fetchProjectConnections(projectId),
        ]);
        projectName.value = project.name;
        projectDescription.value = project.description ?? '';
        selectedPartId.value = parts[0]?.id ?? null;
        parts.forEach((part) => { instanceQuantities[part.id] = 1; });
        recovery.load();
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

const openPart = async (part, instanceId = null) => {
    if (!part) {
        showCreatePart.value = true;
        return;
    }

    flushPartHistory();
    selectedPartId.value = part.id;
    partContextInstanceId.value = instanceId ?? part.instances?.[0]?.id ?? null;
    partViewMode.value = instanceId === null ? 'solo' : 'assembly';
    selectedInstanceId.value = null;
    partDraft.value = draftFromPart(part);
    selectedOperationId.value = null;
    historyExpanded.value = false;
    editorMode.value = 'part';
    leftPanelMode.value = 'library';
    await resetPartHistoryBaseline();
};

const openCurrentPart = () => {
    if (editorMode.value === 'part') return;

    openPart(selectedPart.value ?? activePart.value ?? projects.parts[0], selectedInstanceId.value);
};

const returnToAssembly = () => {
    if (partContextInstance.value) selectedInstanceId.value = partContextInstance.value.id;
    editorMode.value = 'assembly';
};

const auth = useAuthStore();
const recovery = usePartDraftRecovery({ projectId, userId: () => auth.user?.id ?? 'session', projects, selectedPartId, partDraft, draftFromPart, openPart });
const reloadRestoredProject = async () => {
    window.clearTimeout(partHistoryTimer);
    partDraft.value = null;
    selectedPartId.value = null;
    selectedInstanceId.value = null;
    selectedOperationId.value = null;
    selectedGroupId.value = null;
    selectedConnectionId.value = null;
    activeAssemblyGroupId.value = null;
    isolatedGroupId.value = null;
    explodeDistance.value = 0;
    clearHistory();
    fastenerTool.clear();
    fastenerTool.close();
    await Promise.all([projects.fetchProject(projectId), projects.fetchParts(projectId), projects.fetchAssemblyGroups(projectId), projects.fetchProjectConnections(projectId), fastenerTool.reload()]);
    projectName.value = projects.activeProject.name;
    projectDescription.value = projects.activeProject.description ?? '';
    editorMode.value = 'assembly';
    recovery.load();
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
    const minimum = selectedOperation.value.bottom_diameter == null ? 0.1 : Math.min(Number(selectedOperation.value.bottom_diameter) + 0.01, surface.u, surface.v);
    selectedOperation.value.diameter = Math.min(Math.max(Number(value), minimum), surface.u, surface.v);
    fitDrillCenter();
};

const setDrillBottomDiameter = (value) => {
    if (selectedOperation.value?.type !== 'drill') return;

    selectedOperation.value.bottom_diameter = Math.max(0.1, Math.min(Number(value), selectedOperation.value.diameter - 0.01));
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
                position_z: Number(part.dimensions.thickness) / 2,
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
        const dimensionsChanged = stockDimensionFields.some(({ key }) => Number(partDraft.value.dimensions[key]) !== Number(activePart.value.dimensions[key]));
        const updated = await projects.updatePart(projectId, activePart.value.id, {
            name: partDraft.value.name,
            material: partDraft.value.material || null,
            ...partDraft.value.dimensions,
            grain_axis: partDraft.value.grain_axis,
            operations: appliedOperations,
        });
        partDraft.value = draftFromPart(updated);
        recovery.clear(updated.id);
        selectedOperationId.value = null;
        historyExpanded.value = false;
        await resetPartHistoryBaseline();
        if (dimensionsChanged) await projects.fetchProjectConnections(projectId);
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
    partViewMode.value = 'solo';
    selectedPartId.value = projects.parts[0]?.id ?? null;
    partDraft.value = selectedPartId.value ? draftFromPart(projects.parts[0]) : null;
    selectedOperationId.value = null;
    editorMode.value = projects.parts.length ? 'part' : 'assembly';
    await resetPartHistoryBaseline();
};

const addInstances = async (part) => {
    const quantity = instanceQuantities[part.id] || 1;
    const assemblyGroupId = activeAssemblyGroupId.value;
    let instances = await projects.createInstances(projectId, part.id, { quantity, assembly_group_id: assemblyGroupId, position_z: Number(part.dimensions.thickness) / 2 });
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
            instances = await projects.createInstances(projectId, part.id, { quantity, assembly_group_id: assemblyGroupId, position_z: Number(part.dimensions.thickness) / 2 });
            selectedPartId.value = part.id;
            selectedInstanceId.value = instances[0]?.id ?? null;
            editorMode.value = 'assembly';
        },
    });
};

const selectInstance = (instanceId) => {
    if (assemblyInstanceState.value.lockedInstanceIds.includes(instanceId)) return;

    selectedInstanceId.value = instanceId;
    selectedConnectionId.value = null;
    selectedGroupId.value = null;
    groupMoveError.value = '';
};

const assemblyPick = (instanceId) => resolveAssemblyPick(
    projects.assemblyGroups,
    assemblyInstancesById.value.get(instanceId),
    activeAssemblyGroupId.value,
);
const selectAssemblyPick = (instanceId) => {
    const pick = assemblyPick(instanceId);
    if (pick?.kind === 'group') selectAssemblyGroup(pick.group);
    else selectInstance(pick?.id ?? null);
};
const openAssemblyPick = (instanceId) => {
    const pick = assemblyPick(instanceId);
    if (pick?.kind === 'group') openAssemblyGroup(pick.group.id);
};

const connectionTypeLabel = (type) => connectionTypeLabels[type] ?? 'Соединение';
const connectionParameterLabel = (key) => ({
    primary_face: 'Грань первой детали',
    secondary_face: 'Грань второй детали',
    depth_ratio: 'Доля глубины',
    tenon_width: 'Ширина шипа, мм',
    tenon_thickness: 'Толщина шипа, мм',
    tenon_length: 'Длина шипа, мм',
    dowel_diameter: 'Диаметр шканта, мм',
    dowel_count: 'Количество шкантов',
    dowel_depth: 'Глубина, мм',
}[key] ?? key);
const connectionParameterValue = (key, value) => ['primary_face', 'secondary_face'].includes(key)
    ? connectionFaceLabels[value] ?? value
    : value;
const connectionMachiningStatusLabel = (status) => ({
    pending: 'Не создана',
    generated: 'Создана',
    not_required: 'Не требуется',
    outdated: 'Требует пересчёта',
}[status] ?? 'Не создана');
const connectionMachiningDescription = (connection) => {
    if (connection.machining_status === 'generated') return 'Операции добавлены в историю обеих заготовок и уже влияют на их геометрию.';
    if (connection.machining_status === 'not_required') return 'Стыковое соединение не требует автоматической выборки материала.';
    if (connection.machining_status === 'outdated') return connection.type === 'mortise_tenon'
        ? 'Размер заготовки или параметры соединения изменились. Откройте предпросмотр новой обработки.'
        : 'Положение изменено. Старая обработка пока сохранена, а красным показана новая выборка.';
    if (connection.type === 'butt') return 'Для стыкового соединения геометрия деталей останется без изменений.';

    return 'Система добавит применённые операции на обе детали. Общие заготовки при необходимости станут отдельными вариантами.';
};
const instanceName = (instanceId) => allAssemblyInstances.value.find((instance) => instance.id === instanceId)?.name ?? 'Удалённая деталь';
const otherConnectionInstanceId = (connection, instanceId) => connection.primary_instance_id === instanceId
    ? connection.secondary_instance_id
    : connection.primary_instance_id;
const connectionPart = (role) => {
    const instanceId = selectedConnection.value?.[`${role}_instance_id`];

    return projects.parts.find((part) => part.instances?.some((instance) => instance.id === instanceId)) ?? null;
};
const connectionSurfaceSize = (role) => {
    const part = connectionPart(role);
    const face = selectedConnection.value?.parameters?.[`${role}_face`] ?? (role === 'primary' ? 'end' : 'start');

    return part ? surfaceDimensions(face, part.dimensions) : { u: 0, v: 0, depth: 0 };
};
const connectionCenterValue = (role, axis) => Number(
    selectedConnection.value?.parameters?.[`${role}_center_${axis}`] ?? connectionSurfaceSize(role)[axis] / 2,
);
const defaultConnectionArea = computed(() => ({
    length: Math.min(connectionSurfaceSize('primary').u, connectionSurfaceSize('secondary').u),
    width: Math.min(connectionSurfaceSize('primary').v, connectionSurfaceSize('secondary').v),
}));

const selectConnection = async (connectionId) => {
    const connection = projects.connections.find((item) => item.id === connectionId);

    if (!connection) return;

    editorMode.value = 'assembly';
    selectedConnectionId.value = connectionId;
    selectedInstanceId.value = null;
    selectedGroupId.value = null;
    assemblyGuideActive.value = false;
    setDiagnosticFocus([connection.primary_instance_id, connection.secondary_instance_id]);
    await nextTick();
    diagnosticFocusRequestId.value++;
};

const connectionParameters = () => {
    const faces = { primary_face: connectionDraft.primary_face, secondary_face: connectionDraft.secondary_face, joint_angle: connectionDraft.joint_angle };

    if (connectionDraft.type === 'half_lap') return { ...faces, depth_ratio: connectionDraft.depth_ratio, joint_length: connectionDraft.joint_length, joint_width: connectionDraft.joint_width };
    if (connectionDraft.type === 'mortise_tenon') return {
        ...faces,
        tenon_width: connectionDraft.tenon_width,
        tenon_thickness: connectionDraft.tenon_thickness,
        tenon_length: connectionDraft.tenon_length,
    };
    if (connectionDraft.type === 'dowel') return {
        ...faces,
        dowel_diameter: connectionDraft.dowel_diameter,
        dowel_count: connectionDraft.dowel_count,
        dowel_depth: connectionDraft.dowel_depth,
        dowel_spacing: connectionDraft.dowel_spacing,
    };

    return faces;
};

const createConnection = async () => {
    if (!selectedInstance.value || !connectionDraft.secondary_instance_id) return;

    connectionSaving.value = true;
    connectionError.value = '';

    try {
        const connection = await projects.createProjectConnection(projectId, {
            primary_instance_id: selectedInstance.value.id,
            secondary_instance_id: connectionDraft.secondary_instance_id,
            type: connectionDraft.type,
            label: connectionDraft.label || null,
            parameters: connectionParameters(),
        });
        connectionDraft.secondary_instance_id = null;
        connectionDraft.label = '';
        await selectConnection(connection.id);
    } catch (error) {
        connectionError.value = error.response?.data?.message ?? 'Не удалось создать соединение.';
    } finally {
        connectionSaving.value = false;
    }
};

const saveSelectedConnection = async () => {
    if (!selectedConnection.value) return;

    await projects.updateProjectConnection(projectId, selectedConnection.value.id, {
        label: selectedConnection.value.label || null,
        note: selectedConnection.value.note || null,
        is_verified: selectedConnection.value.is_verified,
    });
};

const saveSelectedConnectionParameters = async () => {
    if (!selectedConnection.value) return;

    connectionSaving.value = true;
    connectionError.value = '';

    try {
        await projects.updateProjectConnection(projectId, selectedConnection.value.id, {
            parameters: { ...selectedConnection.value.parameters },
        });
    } catch (error) {
        connectionError.value = error.response?.data?.message
            ?? Object.values(error.response?.data?.errors ?? {})[0]?.[0]
            ?? 'Не удалось сохранить положение соединения.';
        await projects.fetchProjectConnections(projectId);
    } finally {
        connectionSaving.value = false;
    }
};

const setConnectionParameter = (key, value, persist = true) => {
    if (!selectedConnection.value) return;

    selectedConnection.value.parameters = { ...selectedConnection.value.parameters, [key]: Number(value) };

    if (persist) saveSelectedConnectionParameters();
};

const setConnectionCenter = (role, axis, value) => setConnectionParameter(`${role}_center_${axis}`, value);
const centerConnectionOnFace = (role) => {
    if (!selectedConnection.value) return;

    const surface = connectionSurfaceSize(role);
    selectedConnection.value.parameters = {
        ...selectedConnection.value.parameters,
        [`${role}_center_u`]: surface.u / 2,
        [`${role}_center_v`]: surface.v / 2,
    };
    saveSelectedConnectionParameters();
};
const updateConnectionParametersFromScene = (connectionId, parameters) => {
    const connection = projects.connections.find((item) => item.id === connectionId);

    if (!connection || connectionSaving.value) return;

    connection.parameters = { ...connection.parameters, ...parameters };
};
const commitConnectionParametersFromScene = (connectionId) => {
    if (!connectionSaving.value && selectedConnection.value?.id === connectionId) saveSelectedConnectionParameters();
};

const previewSelectedConnectionMachining = async () => {
    const connection = selectedConnection.value;
    if (!connection || connectionSaving.value) return;

    connectionSaving.value = true;
    connectionError.value = '';
    connectionPreview.value = null;

    try {
        const preview = await projects.previewProjectConnectionMachining(projectId, connection.id);
        if (selectedConnection.value !== connection || editorMode.value !== 'assembly') return;
        connectionPreviewReady.value = false;
        connectionPreview.value = { ...preview, connectionId: connection.id };
        setDiagnosticFocus([connection.primary_instance_id, connection.secondary_instance_id]);
        await nextTick();
        diagnosticFocusRequestId.value++;
    } catch (error) {
        if (selectedConnection.value !== connection || editorMode.value !== 'assembly') return;
        const errors = error.response?.data?.errors ?? {};
        connectionError.value = Object.values(errors)[0]?.[0] ?? 'Не удалось рассчитать предпросмотр.';
        const role = ['primary', 'secondary'].find(item => errors[`parameters.${item}`]);
        if (role) {
            setDiagnosticFocus([connection[`${role}_instance_id`]]);
            await nextTick();
            diagnosticFocusRequestId.value++;
        }
    } finally {
        connectionSaving.value = false;
    }
};

const finishConnectionMachiningPreview = (preview, success) => {
    if (preview !== activeConnectionPreview.value) return;
    connectionPreviewReady.value = success;
    if (!success) {
        connectionPreview.value = null;
        connectionError.value = 'Не удалось показать геометрию обеих деталей. Постройте предпросмотр ещё раз.';
    }
};

const generateSelectedConnectionMachining = async () => {
    if (!selectedConnection.value || connectionSaving.value) return;

    const connectionId = selectedConnection.value.id;
    const preview = activeConnectionPreview.value;
    if (selectedConnection.value.type === 'mortise_tenon' && (!preview || !connectionPreviewReady.value)) return;
    connectionSaving.value = true;
    connectionError.value = '';

    try {
        await projects.generateProjectConnectionMachining(projectId, connectionId, preview ? { expected_revision: preview.revision } : {});
        connectionPreview.value = null;
        await Promise.all([
            projects.fetchParts(projectId),
            projects.fetchProjectConnections(projectId),
        ]);
        selectedConnectionId.value = connectionId;
    } catch (error) {
        if (error.response?.status === 409) {
            connectionPreview.value = null;
            await Promise.all([projects.fetchParts(projectId), projects.fetchProjectConnections(projectId)]);
            connectionError.value = 'Проект изменился. Постройте предпросмотр ещё раз.';
        } else {
            connectionError.value = Object.values(error.response?.data?.errors ?? {})[0]?.[0]
                ?? error.response?.data?.message
                ?? 'Не удалось создать обработку соединения.';
        }
    } finally {
        connectionSaving.value = false;
    }
};

const removeSelectedConnectionMachining = async () => {
    if (!selectedConnection.value || connectionSaving.value) return;

    const connectionId = selectedConnection.value.id;
    connectionSaving.value = true;
    connectionError.value = '';

    try {
        await projects.removeProjectConnectionMachining(projectId, connectionId);
        await Promise.all([
            projects.fetchParts(projectId),
            projects.fetchProjectConnections(projectId),
        ]);
        selectedConnectionId.value = connectionId;
    } catch (error) {
        connectionError.value = error.response?.data?.message ?? 'Не удалось отменить обработку соединения.';
    } finally {
        connectionSaving.value = false;
    }
};

const removeSelectedConnection = async () => {
    if (!selectedConnection.value || !window.confirm('Удалить это соединение?')) return;

    await projects.deleteProjectConnection(projectId, selectedConnection.value.id);
    selectedConnectionId.value = null;
    clearDiagnosticFocus();
};

const setDiagnosticFocus = (instanceIds, fasteners = null) => {
    diagnosticFocusInstanceIds.value = instanceIds;
    diagnosticFastenerFocus.value = fasteners;
};
const clearDiagnosticFocus = () => setDiagnosticFocus([]);

const toggleFastenerTool = () => {
    if (fastenerTool.active) {
        fastenerTool.close();
        return;
    }
    measurementTool.value = null;
    selectedConnectionId.value = null;
    clearDiagnosticFocus();
    inspectorVisible.value = true;
    if (window.innerWidth <= 900) libraryVisible.value = false;
    void fastenerTool.open();
};
watch([editorMode, explodeDistance], ([mode, exploded]) => {
    if (mode === 'assembly' && exploded === 0) return;
    fastenerTool.close();
    if (diagnosticFastenerFocus.value) clearDiagnosticFocus();
});

const toggleMeasurement = (tool) => {
    fastenerTool.close();
    measurementTool.value = measurementTool.value === tool ? null : tool;
    measurementResetId.value++;
};

const setAssemblyGuideStep = async (step) => {
    assemblyGuideStep.value = Math.max(0, Math.min(step, assemblyGuideSteps.value.length - 1));
    selectedInstanceId.value = currentAssemblyGuideStep.value?.instanceIds[0] ?? null;
    await nextTick();
    diagnosticFocusRequestId.value++;
};

const startAssemblyGuide = async () => {
    if (!assemblyGuideSteps.value.length) {
        window.alert('Сначала добавьте детали в сборку и распределите их по узлам.');
        return;
    }

    editorMode.value = 'assembly';
    activeAssemblyGroupId.value = null;
    isolatedGroupId.value = null;
    explodeDistance.value = 0;
    clearDiagnosticFocus();
    assemblyGuideActive.value = true;
    await setAssemblyGuideStep(0);
};

const finishAssemblyGuide = () => {
    assemblyGuideActive.value = false;
    selectedInstanceId.value = null;
};

const inspectAnalysisIssue = async (issue) => {
    const context = issue.context ?? {};
    const instanceIds = [
        ...(context.instance_ids ?? []),
        context.duplicate_of_instance_id,
        context.instance_id,
    ].filter((instanceId, index, values) => instanceId && values.indexOf(instanceId) === index);

    showProjectAnalysis.value = false;

    if (context.connection_id && projects.connections.some((connection) => connection.id === context.connection_id)) {
        await selectConnection(context.connection_id);
        return;
    }

    if (instanceIds.length) {
        editorMode.value = 'assembly';
        leftPanelMode.value = 'assembly';
        activeAssemblyGroupId.value = null;
        isolatedGroupId.value = null;
        selectedGroupId.value = null;
        explodeDistance.value = 0;
        assemblyGuideActive.value = false;
        measurementTool.value = null;
        const fasteners = context.fastener_ids?.length ? { ids: context.fastener_ids, status: context.fastener_status } : null;
        if (fasteners) {
            fastenerTool.close();
            selectedConnectionId.value = null;
            sectionAxis.value = null;
        }
        setDiagnosticFocus(instanceIds, fasteners);
        selectedInstanceId.value = fasteners ? null : instanceIds[0];
        selectedPartId.value = projects.parts.find((part) => part.instances?.some((instance) => instance.id === instanceIds[0]))?.id ?? selectedPartId.value;
        await nextTick();
        diagnosticFocusRequestId.value++;
        return;
    }

    const part = projects.parts.find((item) => item.id === context.part_id);

    if (part) {
        openPart(part);
        selectedOperationId.value = context.operation_id ?? null;
    }
};

const selectAssemblyGroup = (group) => {
    editorMode.value = 'assembly';
    leftPanelMode.value = 'assembly';
    selectedGroupId.value = group.id;
    selectedInstanceId.value = null;
    selectedConnectionId.value = null;
    groupMoveError.value = '';
};

const createAssemblyGroup = async () => {
    const name = window.prompt('Название сборочного узла', 'Новый узел')?.trim();

    if (!name) return;

    const group = await projects.createAssemblyGroup(projectId, {
        name,
        parent_id: activeAssemblyGroupId.value,
    });
    selectedGroupId.value = group.id;
    selectedInstanceId.value = null;
    selectedConnectionId.value = null;
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

const isGroupGhosted = (groupId) => ghostedGroupIds.value.includes(groupId);

const isInstanceGhostedByGroup = (instance) => instance.assembly_group_id !== null
    && ghostedGroupScopeIds.value.has(instance.assembly_group_id);

const isInstanceGhosted = (instanceId) => ghostedAssemblyInstanceIdSet.value.has(instanceId);

const toggleGroupGhost = (groupId) => {
    ghostedGroupIds.value = isGroupGhosted(groupId)
        ? ghostedGroupIds.value.filter((id) => id !== groupId)
        : [...ghostedGroupIds.value, groupId];
};

const toggleInstanceGhost = (instanceId) => {
    const instance = projects.parts.flatMap((part) => part.instances ?? []).find((item) => item.id === instanceId);

    if (!instance || isInstanceGhostedByGroup(instance)) return;

    ghostedInstanceIds.value = ghostedInstanceIds.value.includes(instanceId)
        ? ghostedInstanceIds.value.filter((id) => id !== instanceId)
        : [...ghostedInstanceIds.value, instanceId];
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
    selectedConnectionId.value = null;
    assemblySearch.value = '';
    groupMoveError.value = '';
    editorMode.value = 'assembly';
    leftPanelMode.value = 'assembly';
};

const openParentAssemblyGroup = () => {
    if (activeAssemblyGroup.value) openAssemblyGroup(activeAssemblyGroup.value.parent_id);
};

const removeSelectedGroup = async () => {
    if (!selectedGroup.value || !window.confirm(`Удалить узел «${selectedGroup.value.name}»?`)) return;

    const deleteContents = window.confirm('Удалить также все экземпляры деталей внутри этого узла? Нажмите «Отмена», чтобы сохранить содержимое на уровне выше.');
    const groupId = selectedGroup.value.id;
    const parentId = selectedGroup.value.parent_id;
    await projects.deleteAssemblyGroup(projectId, groupId, deleteContents);
    ghostedGroupIds.value = ghostedGroupIds.value.filter((id) => id !== groupId);
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

const beginInstanceInspectorEdit = (event) => {
    if (instanceInspectorSaving.value) {
        event.preventDefault();
        return;
    }

    if (!selectedInstance.value) return;

    instanceInspectorBaseline = {
        position: { ...selectedInstance.value.position },
        rotation: { ...selectedInstance.value.rotation },
        mirrored: selectedInstance.value.mirrored,
    };
};

const saveInstance = async () => {
    if (!selectedInstance.value || instanceInspectorSaving.value) {
        return;
    }

    const instance = selectedInstance.value;
    const instanceId = instance.id;
    const before = instanceInspectorBaseline;
    instanceInspectorBaseline = null;
    instanceInspectorSaving.value = true;

    try {
        const saved = await projects.updateInstance(projectId, instanceId, {
            position_x: instance.position.x,
            position_y: instance.position.y,
            position_z: instance.position.z,
            rotation_x: instance.rotation.x,
            rotation_y: instance.rotation.y,
            rotation_z: instance.rotation.z,
            mirrored: instance.mirrored,
        });
        const after = { position: saved.position, rotation: saved.rotation, mirrored: saved.mirrored };

        if (before && JSON.stringify(before) !== JSON.stringify(after)) {
            recordHistory({
                label: 'Точное размещение детали',
                undo: () => persistInstanceTransform(instanceId, before.position, before.rotation, before.mirrored),
                redo: () => persistInstanceTransform(instanceId, after.position, after.rotation, after.mirrored),
            });
        }
    } finally {
        instanceInspectorSaving.value = false;
    }
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

    if (!instance || assemblyInstanceState.value.lockedInstanceIds.includes(instanceId)) return false;

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
        return true;
    } catch {
        instance.position = { ...initialPosition };
        instance.rotation = { ...initialRotation };
        saveState.value = 'error';
        return false;
    }
};

const commitGroupMove = async ({ members, axis, value }) => {
    if (groupMoveBlock.value || !['x', 'y', 'z'].includes(axis) || !Number.isFinite(value)) return;

    const before = members.map(({ id, position }) => ({ id, position }));
    const after = before.map(({ id, position }) => ({ id, position: translatedPosition(position, axis, value) }));
    if (before.every(({ position }, index) => Number(position[axis]) === after[index].position[axis])) return;
    groupMoveError.value = '';
    saveState.value = 'saving';

    try {
        await projects.moveAssemblyInstances(projectId, before, after, axis);
        recordHistory({
            label: 'Перемещение узла',
            undo: () => projects.moveAssemblyInstances(projectId, after, before, axis),
            redo: () => projects.moveAssemblyInstances(projectId, before, after, axis),
        });
        saveState.value = 'saved';
    } catch (error) {
        saveState.value = 'error';
        groupMoveError.value = error.response?.data?.message || error.message;
    }
};

const commitScale = async ({ members, pivot, axis, value }) => {
    groupMoveError.value = '';
    saveState.value = 'saving';

    try {
        const { variants, bindings } = planAssemblyScale(members, pivot, axis, value);
        if (variants.length + bindings.length > groupMoveLimit) {
            throw new Error('Слишком много деталей и заготовок для одного масштаба. Выберите узел меньшего размера.');
        }

        const command = (id, part, position) => ({
            type: 'transform_instance',
            instance_id: id,
            ...part,
            data: { position_x: position.x, position_y: position.y, position_z: position.z },
        });
        const before = bindings.map(({ id, originalPartId, originalPosition }, index) => ({
            id, partId: originalPartId, position: originalPosition,
            rotation: members[index].instance.rotation, partUpdatedAt: members[index].part.updated_at,
        }));
        const results = await projects.applyAssemblyScale(projectId, before, [
            ...variants,
            ...bindings.map(({ id, partRef, position }) => command(id, { part_ref: partRef }, position)),
        ]);
        const createdParts = new Map(results.filter(result => result.type === 'create_part')
            .map(result => [result.temporary_id, result.part_id]));
        const after = bindings.map(({ id, partRef, position }) => ({ id, partId: createdParts.get(partRef), position }));
        const restore = async (expected, target) => {
            await projects.applyAssemblyScale(projectId, expected, target.map(({ id, partId, position }) => command(id, { part_id: partId }, position)));
            await projects.fetchParts(projectId);
        };
        recordHistory({
            label: 'Масштабирование',
            undo: () => restore(after, before),
            redo: () => restore(before, after),
        });
        await projects.fetchParts(projectId);
        saveState.value = 'saved';
    } catch (error) {
        saveState.value = 'error';
        groupMoveError.value = Object.values(error.response?.data?.errors ?? {}).flat().join(' ')
            || error.response?.data?.message || error.message;
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
        isGhosted: ghostedInstanceIds.value.includes(selectedInstance.value.id),
    };
    await projects.deleteInstance(projectId, snapshot.instanceId);
    ghostedInstanceIds.value = ghostedInstanceIds.value.filter((id) => id !== snapshot.instanceId);
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
            if (snapshot.isGhosted) ghostedInstanceIds.value = [...ghostedInstanceIds.value, snapshot.instanceId];
            selectedPartId.value = snapshot.partId;
            selectedInstanceId.value = snapshot.instanceId;
            editorMode.value = 'assembly';
        },
        redo: async () => {
            await projects.deleteInstance(projectId, snapshot.instanceId);
            ghostedInstanceIds.value = ghostedInstanceIds.value.filter((id) => id !== snapshot.instanceId);
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
    if (showScriptEditor.value || showVersions.value) return;
    if (event.key === 'Escape' && fastenerTool.active && !fastenerTool.busy && !showProjectAnalysis.value) {
        if (fastenerTool.drillPreview) fastenerTool.cancelDrillPreview();
        else if (fastenerTool.copyDraft) fastenerTool.cancelCopy();
        else fastenerTool.close();
        return;
    }
    if (event.key === 'ArrowUp' && event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey
        && !event.defaultPrevented && !event.isComposing
        && editorMode.value === 'assembly' && activeAssemblyGroup.value
        && !showCreatePart.value && !showProjectAnalysis.value && !showTemplateCreator.value
        && !['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName) && !event.target?.isContentEditable) {
        event.preventDefault();
        if (!event.repeat) openParentAssemblyGroup();
        return;
    }

    if (event.shiftKey && assemblyShortcutKey(event) === 'd' && editorMode.value === 'assembly' && selectedInstance.value) {
        if (!event.repeat && !['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName) && !event.target?.isContentEditable) {
            event.preventDefault();
            duplicateSelectedInstance();
        }

        return;
    }

    if (!(event.ctrlKey || event.metaKey) || assemblyShortcutKey(event) !== 'z') return;
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
    fastenerGeometry.dispose();
    assemblyGeometryCache.clear();
    window.clearTimeout(partHistoryTimer);
    window.removeEventListener('keydown', handleEditorHistoryKey);
    window.removeEventListener('afterprint', closeWorkshopPrint);
});

const openProjectAnalysis = async (partId = null) => {
    analysisPartId.value = partId;
    showProjectAnalysis.value = true;
    await refreshProjectAnalysis();
};

const openTemplateCreator = () => {
    templateDraft.name = `${projectName.value} — шаблон`;
    templateDraft.description = projectDescription.value;
    templateError.value = '';
    showTemplateCreator.value = true;
};

const saveProjectTemplate = async () => {
    templateSaving.value = true;
    templateError.value = '';

    try {
        await projects.createProjectTemplate({
            project_id: projectId,
            name: templateDraft.name,
            description: templateDraft.description || null,
        });
        showTemplateCreator.value = false;
    } catch (error) {
        templateError.value = error.response?.data?.message ?? 'Не удалось сохранить шаблон.';
    } finally {
        templateSaving.value = false;
    }
};

const refreshProjectAnalysis = async () => {
    projectAnalysisLoading.value = true;
    projectAnalysisError.value = '';
    fastenerAnalysisLoading.value = true;
    fastenerAnalysisError.value = '';
    fastenerGeometry.retryFailed();

    const fasteners = fastenerTool.reload().catch(() => {
        fastenerAnalysisError.value = 'Не удалось загрузить крепёж. Нажмите «Пересчитать».';
    }).finally(() => {
        fastenerAnalysisLoading.value = false;
    });

    try {
        projectAnalysis.value = await projects.fetchProjectAnalysis(projectId, cuttingSettings);
    } catch (error) {
        projectAnalysisError.value = error.response?.data?.message ?? 'Не удалось выполнить анализ проекта.';
    } finally {
        projectAnalysisLoading.value = false;
    }
    await fasteners;
};

const csvCell = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;

const closeWorkshopPrint = () => {
    workshopPrintReport.value = null;
};

const printWorkshopPlan = async () => {
    if (!projectAnalysis.value || workshopPrintReport.value) return;

    workshopPrintReport.value = projectAnalysis.value;
    await nextTick();
    window.addEventListener('afterprint', closeWorkshopPrint, { once: true });
    window.print();
};

const exportManufacturingCsv = () => {
    if (!projectAnalysis.value) return;

    const report = projectAnalysis.value.manufacturing;
    const rows = [
        ['Раздел', 'Заготовка', 'Материал', 'Длина, мм', 'Ширина, мм', 'Толщина, мм', 'Количество', 'Операций', 'Номер детали'],
        ...report.bill_of_materials.map((part) => [
            'Спецификация',
            part.name,
            part.material,
            part.dimensions.length,
            part.dimensions.width,
            part.dimensions.thickness,
            part.quantity,
            part.operation_count,
            partWorkshopCode(part.part_id),
        ]),
        [],
        ['Раскрой досок', 'Заготовка', 'Материал', 'Длина детали, мм', 'Доска №', 'Начало, мм', 'Остаток по длине, мм', 'Полоса №', 'Ширина, мм', 'Отступ от кромки, мм', 'Номер детали'],
        ...report.linear_cutting.flatMap((group) => group.bars.flatMap((bar) => bar.cuts.map((cut) => [
            'Доска', cut.name, group.material, cut.length, bar.number, cut.start,
            bar.strips?.find(strip => strip.number === cut.strip)?.waste_length ?? bar.waste_length,
            cut.strip ?? 1, cut.strip_width ?? group.section.width, cut.strip_offset ?? 0, partWorkshopCode(cut.part_id),
        ]))),
        [],
        ['Раскрой листа', 'Заготовка', 'Материал', 'Длина, мм', 'Ширина, мм', 'Лист №', 'X, мм', 'Y, мм', 'Номер детали'],
        ...report.sheet_cutting.flatMap((group) => group.sheets.flatMap((sheet) => sheet.placements.map((placement) => [
            'Лист', placement.name, group.material, placement.length, placement.width, sheet.number, placement.x, placement.y, partWorkshopCode(placement.part_id),
        ]))),
    ];
    const csv = `\ufeff${rows.map((row) => row.map(csvCell).join(';')).join('\r\n')}`;
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    const fileName = projectName.value.trim().replace(/[^\p{L}\p{N}_-]+/gu, '-') || `project-${projectId}`;
    link.href = url;
    link.download = `${fileName}-production.csv`;
    link.click();
    URL.revokeObjectURL(url);
};

const analysisIssueTitle = (code) => ({
    project_empty: 'Проект пуст',
    part_unused: 'Заготовка не используется',
    instance_below_floor: 'Деталь ниже уровня пола',
    thin_remaining_stock: 'Слишком тонкий остаток материала',
    duplicate_instance: 'Дублирующиеся детали',
    instance_without_support: 'Деталь без опоры',
    potential_intersection: 'Возможное пересечение',
    connection_unverified: 'Соединение не проверено',
}[code] ?? 'Требуется проверка');

const analysisIssueDetails = (issue) => {
    const context = issue.context ?? {};

    if (issue.code === 'part_unused') return context.part_name;
    if (issue.code === 'instance_below_floor') return `Экземпляр #${context.instance_id}, нижняя отметка ${context.position_z} мм.`;
    if (issue.code === 'thin_remaining_stock') return `${context.part_name}: после операции остаётся ${context.remaining_mm} мм.`;
    if (issue.code === 'duplicate_instance') return `Экземпляры #${context.duplicate_of_instance_id} и #${context.instance_id} имеют одинаковое положение.`;
    if (issue.code === 'instance_without_support') return `${context.part_name} (#${context.instance_id}), нижняя отметка ${context.bottom_z} мм.`;
    if (issue.code === 'potential_intersection') return `${context.part_names?.join(' ↔ ')} · экземпляры #${context.instance_ids?.join(' и #')}.`;
    if (issue.code === 'connection_unverified') return `${connectionTypeLabel(context.connection_type)} · экземпляры #${context.instance_ids?.join(' и #')}.`;

    return issue.message;
};

const formatAnalysisVolume = (volumeMm3) => new Intl.NumberFormat('ru-UA', { maximumFractionDigits: 4 }).format(Number(volumeMm3) / 1_000_000_000);
const formatAnalysisArea = (areaMm2) => new Intl.NumberFormat('ru-UA', { maximumFractionDigits: 2 }).format(Number(areaMm2) / 1_000_000);
const sheetPlacementStyle = (placement, stock) => ({
    left: `${Number(placement.x) / Number(stock.length) * 100}%`,
    top: `${Number(placement.y) / Number(stock.width) * 100}%`,
    width: `${Number(placement.length) / Number(stock.length) * 100}%`,
    height: `${Number(placement.width) / Number(stock.width) * 100}%`,
});

const formatDimensions = (part) => `${part.dimensions.length} × ${part.dimensions.width} × ${part.dimensions.thickness} мм`;
const formatApiTokenDate = (date) => date ? new Intl.DateTimeFormat('ru-UA', { dateStyle: 'medium' }).format(new Date(date)) : 'без ограничения';
const pluralParts = (count) => count === 1 ? 'заготовка' : count > 1 && count < 5 ? 'заготовки' : 'заготовок';
</script>
