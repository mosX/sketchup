<template>
    <div ref="viewport" class="three-viewport">
        <div v-if="emptyMessage" class="viewport-empty">
            <span class="viewport-empty-mark">◇</span>
            <strong>{{ emptyMessage.title }}</strong>
            <small>{{ emptyMessage.text }}</small>
        </div>
        <div
            v-if="angleCapableOperation && operationToolVisible"
            ref="operationControls"
            class="scene-operation-tool"
            @pointerenter="keepOperationToolVisible"
            @pointerleave="releaseOperationTool"
        >
            <button
                v-if="!anglePanelOpen"
                class="scene-operation-trigger"
                type="button"
                title="Настроить углы"
                aria-label="Открыть настройки углов"
                @click="anglePanelOpen = true"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 18h16M6 17l10-9M15 6h4v4" />
                    <circle cx="6" cy="17" r="1.5" />
                </svg>
            </button>
            <div v-else class="scene-operation-controls">
                <div class="scene-operation-title">
                    <div><span>{{ selectedOperationLabel }}</span><small>Угол и глубина пропила</small></div>
                    <button type="button" aria-label="Закрыть настройки углов" @click="anglePanelOpen = false">×</button>
                </div>
                <div v-if="selectedOperation.type === 'cross_cut'" class="scene-angle-editor">
                    <label for="scene-miter-angle">Угол в плане</label>
                    <div>
                        <input id="scene-miter-angle" :value="selectedOperation.miter_angle" type="range" min="-60" max="60" step="1" @input="setOperationValue('miter_angle', $event)">
                        <span><input v-scrub-number :value="selectedOperation.miter_angle" type="number" min="-60" max="60" step="1" aria-label="Точный угол в плане" @scrub-value="setOperationValue('miter_angle', $event)" @change="setOperationValue('miter_angle', $event)"><b>°</b></span>
                    </div>
                </div>
                <div class="scene-angle-editor">
                    <label for="scene-bevel-angle">Наклон диска</label>
                    <div>
                        <input id="scene-bevel-angle" :value="selectedOperation.bevel_angle" type="range" min="-45" max="45" step="1" @input="setOperationValue('bevel_angle', $event)">
                        <span><input v-scrub-number :value="selectedOperation.bevel_angle" type="number" min="-45" max="45" step="1" aria-label="Точный наклон диска" @scrub-value="setOperationValue('bevel_angle', $event)" @change="setOperationValue('bevel_angle', $event)"><b>°</b></span>
                    </div>
                </div>
                <div class="scene-angle-editor">
                    <label for="scene-cut-depth">Глубина пропила</label>
                    <div>
                        <input id="scene-cut-depth" :value="selectedOperation.cut_depth" type="range" min="0.1" :max="props.activePart.dimensions.thickness" step="0.1" @input="setOperationValue('cut_depth', $event)">
                        <span><input v-scrub-number :value="selectedOperation.cut_depth" type="number" min="0.1" :max="props.activePart.dimensions.thickness" step="0.1" aria-label="Точная глубина пропила" @scrub-value="setOperationValue('cut_depth', $event)" @change="setOperationValue('cut_depth', $event)"><b>мм</b></span>
                    </div>
                </div>
                <label class="scene-cut-direction">
                    <span>Направление пропила</span>
                    <select :value="selectedOperation.cut_direction" @change="setOperationDirection">
                        <option value="top_down">Сверху вниз</option>
                        <option value="bottom_up">Снизу вверх</option>
                    </select>
                </label>
                <p>Shift+A — закрыть · Shift + ←/→ — план · Alt + ←/→ — наклон</p>
            </div>
        </div>
        <div v-if="selectedOperation" class="offcut-legend"><i></i> Полупрозрачная часть будет удалена</div>
        <div v-if="mode === 'assembly' && connections.length" class="absolute bottom-4 right-20 z-2 rounded-lg border border-emerald-900/10 bg-white/80 px-3 py-2 text-[10px] text-stone-500 backdrop-blur"><span class="mr-1 text-amber-600">◆</span>Столярные соединения: {{ connections.length }}</div>
        <div v-if="transformState" class="scene-transform-hud">
            <div>
                <strong>{{ transformModeLabel }}</strong>
                <span v-if="transformState.axis" class="transform-axis-badge" :class="`transform-axis-${transformState.axis}`">{{ transformState.axis.toUpperCase() }}</span>
            </div>
            <p v-if="transformState.mode === 'scale' && !transformState.axis">Общий масштаб · <b>{{ transformValueLabel }}</b> · <kbd>X</kbd> <kbd>Y</kbd> <kbd>Z</kbd> для оси</p>
            <p v-else-if="!transformState.axis">Выберите ось: <kbd>X</kbd> <kbd>Y</kbd> <kbd>Z</kbd></p>
            <p v-else>Двигайте мышь · <b>{{ transformValueLabel }}</b></p>
            <p v-if="transformState.scaleError" role="alert">{{ transformState.scaleError }}</p>
            <p v-if="transformState.numericInput" class="text-amber-300">Точный ввод: <b>{{ transformState.numericInput }}</b></p>
            <p v-else-if="transformState.snap" class="text-emerald-300">Привязка: <b>{{ transformState.snap.label }}</b></p>
            <small>{{ transformState.mode === 'scale' ? 'Введите коэффициент, например 1,5' : (transformState.mode === 'move' ? 'Введите число · Удерживайте Shift для привязки' : 'Введите число · Shift — медленнее') }}</small>
            <small>ЛКМ / Enter — применить · Esc / ПКМ — отменить</small>
        </div>
        <div v-if="measurementTool" class="absolute bottom-12 left-1/2 z-4 min-w-72 -translate-x-1/2 rounded-xl border border-emerald-900/15 bg-white/95 px-4 py-3 text-center shadow-xl backdrop-blur">
            <div class="flex items-center justify-center gap-2 text-xs font-bold text-emerald-950">
                <span>{{ measurementTool === 'angle' ? 'Измерение угла' : 'Измерение расстояния' }}</span>
                <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] text-emerald-800">{{ measurementPoints.length }}/{{ measurementTool === 'angle' ? 3 : 2 }}</span>
            </div>
            <p v-if="measurementResult?.type === 'distance'" class="mt-1 font-mono text-sm font-bold text-stone-700">{{ measurementResult.distance.toFixed(1) }} мм</p>
            <p v-else-if="measurementResult?.type === 'angle'" class="mt-1 font-mono text-sm font-bold text-stone-700">{{ measurementResult.angle.toFixed(1) }}°</p>
            <p v-if="measurementResult?.type === 'distance'" class="mt-1 text-[10px] text-stone-500">ΔX {{ measurementResult.delta.x.toFixed(1) }} · ΔY {{ measurementResult.delta.y.toFixed(1) }} · ΔZ {{ measurementResult.delta.z.toFixed(1) }} мм</p>
            <p v-else class="mt-1 text-[10px] text-stone-500">Кликайте по поверхности: {{ measurementTool === 'angle' ? 'точка луча — вершина — точка луча' : 'начальная — конечная точка' }}.</p>
        </div>
        <div class="viewport-hint">СКМ / Alt+ЛКМ — вращение · Колесо — масштаб · ПКМ — перемещение</div>
        <div class="axis-widget" aria-hidden="true"><span class="axis-x">X</span><span class="axis-y">Y</span><span class="axis-z">Z</span></div>
    </div>
</template>

<script setup>
import { vScrubNumber } from '../editor/scrubNumber.js';
import { applyMeshAppearance, disposeSceneObjects } from '../editor/sceneAppearance.js';
import { assemblyShortcutKey, translatedPosition } from '../editor/assemblyMove.js';
import { scaleAssemblyPose, scaleFactorFromPointer } from '../editor/assemblyScale.js';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import {
    clamp,
    clampDrillCenter,
    clampGrooveCenter,
    clampPlungeStart,
    drillPlacement,
    edgeRoundoverMaximumRadius,
    grooveFaceFrame,
    groovePlacement,
    millimeterScale,
    partSize,
    partFaceDragPlane,
    setPartEditingFrame,
    setScenePose,
    plungeMaximumRadius,
    plungeRoutePlacement,
} from '../editor/geometry/coordinates.js';
import { crossCutPlane, operationPreviewObject, ripCutPlane } from '../editor/geometry/cutters.js';
import { createGeometryWorkerClient } from '../editor/geometry/workerClient.js';
import { geometryKey } from '../editor/geometry/resultCache.js';
import { connectionFacePlacement, connectionOperationPlan } from '../editor/connections.js';
import { createFastenerMesh } from '../editor/fasteners.js';

const props = defineProps({
    mode: { type: String, default: 'assembly' },
    parts: { type: Array, default: () => [] },
    activePart: { type: Object, default: null },
    partContextInstance: { type: Object, default: null },
    partContextVisibleInstanceIds: { type: Array, default: () => [] },
    selectedInstanceId: { type: Number, default: null },
    selectedGroupInstanceIds: { type: Array, default: () => [] },
    groupMoveInstanceIds: { type: Array, default: () => [] },
    groupMoveBlock: { type: String, default: '' },
    transformBusy: Boolean,
    commitScale: { type: Function, default: null },
    pickableInstanceIds: { type: Array, default: null },
    selectedOperationId: { type: String, default: null },
    visibleInstanceIds: { type: Array, default: null },
    lockedInstanceIds: { type: Array, default: () => [] },
    ghostedInstanceIds: { type: Array, default: () => [] },
    explodeDistance: { type: Number, default: 0 },
    focusedInstanceIds: { type: Array, default: () => [] },
    focusedFasteners: { type: Object, default: null },
    focusRequestId: { type: Number, default: 0 },
    measurementTool: { type: String, default: null },
    measurementResetId: { type: Number, default: 0 },
    sectionAxis: { type: String, default: null },
    sectionOffset: { type: Number, default: 0 },
    sectionInverted: { type: Boolean, default: false },
    connections: { type: Array, default: () => [] },
    selectedConnectionId: { type: Number, default: null },
    connectionMachiningPreview: { type: Object, default: null },
    fastenerMachiningPreview: { type: Object, default: null },
    fasteners: { type: Array, default: () => [] },
    fastenerPreview: { type: Array, default: () => [] },
    assemblyGeometryCache: { type: Object, default: null },
    fastenerRowCopy: Boolean,
    fastenerMode: Boolean,
    selectedFastenerId: { type: Number, default: null },
});

const emit = defineEmits(['select-instance', 'open-instance-group', 'select-operation', 'select-connection', 'connection-preview-rendered', 'fastener-preview-rendered', 'update-operation', 'update-connection-parameters', 'commit-connection-parameters', 'commit-instance-transform', 'commit-group-move', 'blocked-group-move', 'pick-fastener-point', 'select-fastener']);
const viewport = ref(null);
const operationControls = ref(null);
const anglePanelOpen = ref(false);
const hoveredOperationId = ref(null);
const operationToolHovered = ref(false);
const transformState = ref(null);
let pendingScaleIds = null;
const measurementPoints = ref([]);
const measurementResult = ref(null);
const editorAxisDirections = {
    x: new THREE.Vector3(1, 0, 0),
    y: new THREE.Vector3(0, 0, 1),
    z: new THREE.Vector3(0, 1, 0),
};
const palette = ['#b98552', '#c99b65', '#a87345', '#d0a878', '#98704c'];
const objectMeshes = [];
const fastenerMeshes = new Map();
const operationHelpers = [];
const lastPointerPosition = new THREE.Vector2();
const geometryWorker = createGeometryWorkerClient();
const assemblyGeometryWorker = createGeometryWorkerClient(props.assemblyGeometryCache);
let animationFrame;
let camera;
let controls;
let renderer;
let resizeObserver;
let scene;
let objectsGroup;
let partGroup;
let partMesh = null;
let renderedPartId = null;
let dragState = null;
let viewedPartId = null;
let assemblyCamera = null;
let viewedContextId = null;
let hoverClearTimer = null;
let transformGuide = null;
let measurementGroup = null;
let sectionHelper = null;
let connectionsGroup = null;
let hasPointerPosition = false;
let rebuildTimer = null;
let rebuildVersion = 0;
let assemblyRebuildVersion = 0;

const selectedOperation = computed(() => props.activePart?.operations?.find((operation) => operation.id === props.selectedOperationId) ?? null);
const visibleInstanceIdSet = computed(() => props.visibleInstanceIds === null ? null : new Set(props.visibleInstanceIds));
const contextVisibleInstanceIdSet = computed(() => new Set(props.partContextVisibleInstanceIds));
const pickableInstanceIdSet = computed(() => props.pickableInstanceIds === null ? null : new Set(props.pickableInstanceIds));
const selectedGroupInstanceIdSet = computed(() => new Set(props.selectedGroupInstanceIds));
const canPickInstance = id => pickableInstanceIdSet.value === null || pickableInstanceIdSet.value.has(id);
let lastAssemblyPick = null;
const lockedInstanceIdSet = computed(() => new Set(props.lockedInstanceIds));
const ghostedInstanceIdSet = computed(() => new Set(props.ghostedInstanceIds));
const focusedInstanceIdSet = computed(() => new Set(props.focusedInstanceIds));
const angleCapableOperation = computed(() => ['cross_cut', 'rip_cut'].includes(selectedOperation.value?.type));
const operationToolVisible = computed(() => anglePanelOpen.value || operationToolHovered.value || hoveredOperationId.value === props.selectedOperationId);
const selectedOperationLabel = computed(() => ({
    cross_cut: 'Поперечный рез',
    rip_cut: 'Продольный рез',
    groove: 'Паз пилой',
}[selectedOperation.value?.type] ?? 'Операция'));
const selectedInstanceData = computed(() => {
    for (const part of props.parts) {
        const instance = part.instances?.find((item) => item.id === props.selectedInstanceId);

        if (instance) return { part, instance };
    }

    return null;
});

const applyInstanceTransform = (mesh, part, position, rotation) => {
    setScenePose(mesh, position, rotation);
    mesh.userData.basePosition = mesh.position.clone();
};

const applyExplodedPositions = () => {
    if (props.mode !== 'assembly') return;

    const assemblyMeshes = objectMeshes.filter((mesh) => mesh.visible && mesh.userData.instanceId !== null && mesh.userData.basePosition);
    const distance = Math.max(0, Number(props.explodeDistance)) * millimeterScale;

    if (!assemblyMeshes.length) {
        clearConnectionHelpers();
        return;
    }

    const center = assemblyMeshes.reduce(
        (sum, mesh) => sum.add(mesh.userData.basePosition),
        new THREE.Vector3(),
    ).divideScalar(assemblyMeshes.length);

    assemblyMeshes.forEach((mesh) => {
        mesh.position.copy(mesh.userData.basePosition);

        if (distance === 0) return;

        const direction = mesh.userData.basePosition.clone().sub(center);

        if (direction.lengthSq() < 0.000001) {
            const angle = (Number(mesh.userData.instanceId) * 2.399963) % (Math.PI * 2);
            direction.set(Math.cos(angle), 0.45, Math.sin(angle));
        }

        direction.normalize();
        mesh.position.addScaledVector(direction, distance);
    });
    updateConnectionHelpers();
};

const connectionColor = (type) => ({
    butt: '#64748b',
    half_lap: '#0f766e',
    mortise_tenon: '#b45309',
    dowel: '#7c3aed',
}[type] ?? '#64748b');

const clearConnectionHelpers = () => {
    if (!connectionsGroup) return;

    connectionsGroup.children.slice().forEach((object) => {
        object.geometry?.dispose();
        object.material?.dispose();
        connectionsGroup.remove(object);
    });
};

const connectionPoint = (connection, role, mesh) => {
    const part = mesh.userData.part;
    const placement = connectionFacePlacement(connection, part, role);
    const frame = grooveFaceFrame(part, placement.face);
    const localPoint = frame.center.clone()
        .addScaledVector(frame.u, (placement.center.u - placement.surface.u / 2) * millimeterScale)
        .addScaledVector(frame.v, (placement.center.v - placement.surface.v / 2) * millimeterScale);
    mesh.updateWorldMatrix(true, false);

    return { ...placement, frame, point: mesh.localToWorld(localPoint) };
};

const addConnectionMachiningPreview = (connection) => {
    if (!['pending', 'outdated'].includes(connection.machining_status)) return;

    const plan = connection.type === 'mortise_tenon'
        ? Object.values(props.connectionMachiningPreview?.parts ?? {}).map(entry => ({
            instance: { id: entry.instance_id },
            operations: entry.operations,
        }))
        : connectionOperationPlan(connection, props.parts);

    addMachiningPreview(plan, connection.id);
};

const addMachiningPreview = (plan, connectionId = null) => {
    plan.forEach(({ instance, operations }) => {
        const mesh = objectMeshes.find((candidate) => candidate.userData.instanceId === instance.id);

        if (!mesh || !mesh.visible) return;

        const part = mesh.userData.part;
        mesh.updateWorldMatrix(true, false);
        operations.forEach((operation) => {
            const preview = operationPreviewObject(part, operation);

            if (!preview) return;

            preview.applyMatrix4(mesh.matrixWorld);
            preview.material = new THREE.MeshBasicMaterial({
                color: connectionId === null ? '#0891b2' : '#ef4444',
                transparent: true,
                opacity: 0.28,
                depthWrite: false,
                depthTest: connectionId !== null,
                side: THREE.DoubleSide,
            });
            preview.userData.connectionId = connectionId;
            preview.userData.connectionPreview = true;
            preview.renderOrder = 5;
            connectionsGroup.add(preview);
        });
    });
};

const updateConnectionHelpers = () => {
    if (!connectionsGroup) return;

    clearConnectionHelpers();

    if (props.mode !== 'assembly') return;

    props.connections.forEach((connection) => {
        const primary = objectMeshes.find((mesh) => mesh.userData.instanceId === connection.primary_instance_id);
        const secondary = objectMeshes.find((mesh) => mesh.userData.instanceId === connection.secondary_instance_id);

        if (!primary?.visible || !secondary?.visible) return;

        const selected = connection.id === props.selectedConnectionId;
        const color = selected ? '#dc2626' : connectionColor(connection.type);
        const primaryPlacement = connectionPoint(connection, 'primary', primary);
        const secondaryPlacement = connectionPoint(connection, 'secondary', secondary);
        const points = [primaryPlacement.point, secondaryPlacement.point];
        const line = new THREE.Line(
            new THREE.BufferGeometry().setFromPoints(points),
            new THREE.LineDashedMaterial({ color, dashSize: 0.12, gapSize: 0.07, depthTest: false }),
        );
        line.computeLineDistances();
        line.userData.connectionId = connection.id;
        line.renderOrder = 6;
        connectionsGroup.add(line);

        ['primary', 'secondary'].forEach((role, index) => {
            const marker = new THREE.Mesh(
                new THREE.OctahedronGeometry(selected ? 0.09 : 0.065),
                new THREE.MeshBasicMaterial({ color, depthTest: false }),
            );
            marker.position.copy(points[index]);
            marker.userData.connectionId = connection.id;
            marker.userData.connectionRole = role;
            marker.renderOrder = 7;
            connectionsGroup.add(marker);
        });

        if (selected) addConnectionMachiningPreview(connection);
    });
    if (props.fastenerMachiningPreview) {
        addMachiningPreview(props.fastenerMachiningPreview.parts.map(entry => ({
            instance: { id: entry.instance_id }, operations: entry.operations,
        })));
    }
};

const frameInspectionBounds = (bounds, direction, minimumRadius, minimumDistance) => {
    const center = bounds.getCenter(new THREE.Vector3());
    const size = bounds.getSize(new THREE.Vector3());
    const radius = Math.max(size.length() / 2, minimumRadius);

    controls.target.copy(center);
    camera.position.copy(center).addScaledVector(direction, Math.max(radius * 2.6, minimumDistance));
    camera.near = Math.max(radius / 100, 0.01);
    camera.far = Math.max(radius * 100, 1000);
    camera.updateProjectionMatrix();
    controls.update();
};

const focusInstances = () => {
    if (props.mode !== 'assembly' || !camera || !controls || focusedInstanceIdSet.value.size === 0) return;

    const direction = camera.position.clone().sub(controls.target).normalize();
    const fasteners = (props.focusedFasteners?.ids ?? []).flatMap(id => {
        const object = fastenerMeshes.get(id)?.object;
        return object?.parent?.visible ? [object] : [];
    });
    if (fasteners.length) {
        const bounds = new THREE.Box3().setFromPoints(fasteners.map(object => object.getWorldPosition(new THREE.Vector3())));
        const outward = new THREE.Vector3(0, -1, 0).transformDirection(fasteners[0].matrixWorld);
        direction.addScaledVector(outward, Math.max(0, 0.55 - direction.dot(outward))).normalize();
        frameInspectionBounds(bounds, direction, 0.18, 0.6);
        return;
    }

    const meshes = objectMeshes.filter(mesh => mesh.visible && focusedInstanceIdSet.value.has(mesh.userData.instanceId));
    if (!meshes.length) return;
    const bounds = meshes.reduce((box, mesh) => box.union(new THREE.Box3().setFromObject(mesh)), new THREE.Box3());
    frameInspectionBounds(bounds, direction, 0.35, 1.4);
};

const sectionNormal = () => ({
    x: new THREE.Vector3(1, 0, 0),
    y: new THREE.Vector3(0, 0, 1),
    z: new THREE.Vector3(0, 1, 0),
}[props.sectionAxis] ?? null);

const updateSection = () => {
    if (!scene) return;

    if (sectionHelper) {
        scene.remove(sectionHelper);
        sectionHelper.geometry.dispose();
        sectionHelper.material.dispose();
        sectionHelper = null;
    }

    const baseNormal = props.mode === 'assembly' ? sectionNormal() : null;

    if (baseNormal) {
        const normal = baseNormal.clone().multiplyScalar(props.sectionInverted ? -1 : 1);
        const point = baseNormal.clone().multiplyScalar(Number(props.sectionOffset) * millimeterScale);
        const plane = new THREE.Plane().setFromNormalAndCoplanarPoint(normal, point);
        const material = new THREE.MeshBasicMaterial({
            color: '#d97706',
            opacity: 0.12,
            transparent: true,
            depthWrite: false,
            side: THREE.DoubleSide,
        });
        sectionHelper = new THREE.Mesh(new THREE.PlaneGeometry(20, 20), material);
        sectionHelper.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), baseNormal);
        sectionHelper.position.copy(point);
        sectionHelper.renderOrder = 3;
        scene.add(sectionHelper);

        objectMeshes.forEach((mesh) => {
            mesh.material.clippingPlanes = [plane];
            mesh.material.needsUpdate = true;

            if (mesh.userData.edges) {
                mesh.userData.edges.material.clippingPlanes = [plane];
                mesh.userData.edges.material.needsUpdate = true;
            }
        });

        return;
    }

    objectMeshes.forEach((mesh) => {
        mesh.material.clippingPlanes = [];
        mesh.material.needsUpdate = true;

        if (mesh.userData.edges) {
            mesh.userData.edges.material.clippingPlanes = [];
            mesh.userData.edges.material.needsUpdate = true;
        }
    });
};

const clearMeasurement = () => {
    measurementPoints.value = [];
    measurementResult.value = null;

    if (!measurementGroup) return;

    measurementGroup.children.slice().forEach((object) => {
        object.geometry?.dispose();
        object.material?.dispose();
        measurementGroup.remove(object);
    });
};

const drawMeasurement = () => {
    if (!measurementGroup) return;

    measurementGroup.children.slice().forEach((object) => {
        object.geometry?.dispose();
        object.material?.dispose();
        measurementGroup.remove(object);
    });

    measurementPoints.value.forEach((point) => {
        const marker = new THREE.Mesh(
            new THREE.SphereGeometry(0.045, 16, 12),
            new THREE.MeshBasicMaterial({ color: '#f59e0b', depthTest: false }),
        );
        marker.position.copy(point);
        marker.renderOrder = 5;
        measurementGroup.add(marker);
    });

    if (measurementPoints.value.length > 1) {
        const line = new THREE.Line(
            new THREE.BufferGeometry().setFromPoints(measurementPoints.value),
            new THREE.LineBasicMaterial({ color: '#d97706', depthTest: false }),
        );
        line.renderOrder = 5;
        measurementGroup.add(line);
    }
};

const addMeasurementPoint = (point) => {
    const requiredPoints = props.measurementTool === 'angle' ? 3 : 2;

    if (measurementPoints.value.length >= requiredPoints) {
        clearMeasurement();
    }

    measurementPoints.value = [...measurementPoints.value, point.clone()];
    drawMeasurement();

    if (props.measurementTool === 'distance' && measurementPoints.value.length === 2) {
        const delta = measurementPoints.value[1].clone().sub(measurementPoints.value[0]);
        measurementResult.value = {
            type: 'distance',
            distance: delta.length() / millimeterScale,
            delta: {
                x: Math.abs(delta.x / millimeterScale),
                y: Math.abs(delta.z / millimeterScale),
                z: Math.abs(delta.y / millimeterScale),
            },
        };
    } else if (props.measurementTool === 'angle' && measurementPoints.value.length === 3) {
        const first = measurementPoints.value[0].clone().sub(measurementPoints.value[1]);
        const second = measurementPoints.value[2].clone().sub(measurementPoints.value[1]);
        measurementResult.value = {
            type: 'angle',
            angle: THREE.MathUtils.radToDeg(first.angleTo(second)),
        };
    }
};
const transformModeLabel = computed(() => {
    const state = transformState.value;
    if (state?.mode === 'scale') return state.group ? 'Масштаб узла' : 'Масштаб детали';
    if (state?.mode === 'rotate') return 'Вращение';
    return state?.group ? 'Перемещение узла' : 'Перемещение';
});
const transformValueLabel = computed(() => {
    if (transformState.value?.mode === 'scale') return `×${transformState.value.value.toFixed(3)}`;
    if (!transformState.value?.axis) return '';

    return transformState.value.mode === 'move'
        ? `${transformState.value.value.toFixed(1)} мм`
        : `${transformState.value.value.toFixed(1)}°`;
});

const emptyMessage = computed(() => {
    if (props.mode === 'part' && !props.activePart) {
        return { title: 'Выберите заготовку', text: 'Или создайте новую в библиотеке слева.' };
    }

    const instanceCount = props.parts.reduce((total, part) => total + (part.instances?.length ?? 0), 0);

    if (props.mode === 'assembly' && instanceCount === 0) {
        return { title: 'Сцена пока пуста', text: 'Добавьте экземпляры заготовок из библиотеки.' };
    }

    return null;
});

const createOffcutPreview = (part, geometry) => {
    const operation = part.operations?.find((item) => item.id === props.selectedOperationId);

    if (!['cross_cut', 'rip_cut'].includes(operation?.type)) return;

    if (!geometry || geometry.getAttribute('position')?.count === 0) {
        geometry?.dispose();
        return;
    }

    const material = new THREE.MeshStandardMaterial({
        color: '#d96c48',
        transparent: true,
        opacity: 0.32,
        depthWrite: false,
        roughness: 0.85,
        side: THREE.DoubleSide,
    });
    const offcut = new THREE.Mesh(geometry, material);
    offcut.renderOrder = 2;

    const outlineGeometry = new THREE.EdgesGeometry(geometry);
    const outline = new THREE.LineSegments(
        outlineGeometry,
        new THREE.LineDashedMaterial({ color: '#aa3f28', dashSize: 0.12, gapSize: 0.07, transparent: true, opacity: 0.75 }),
    );
    outline.computeLineDistances();
    offcut.add(outline);
    partGroup.add(offcut);
};

const createGrooveFacePreview = (part, operation) => {
    const placement = groovePlacement(part, operation);
    const geometry = new THREE.PlaneGeometry(placement.frame.uSize, placement.frame.vSize);
    const material = new THREE.MeshBasicMaterial({
        color: '#3f9272',
        transparent: true,
        opacity: 0.1,
        depthWrite: false,
        side: THREE.DoubleSide,
    });
    const preview = new THREE.Mesh(geometry, material);
    const orientation = new THREE.Matrix4().makeBasis(placement.frame.u, placement.frame.v, placement.frame.inward);
    preview.quaternion.setFromRotationMatrix(orientation);
    preview.position.copy(placement.frame.center).addScaledVector(placement.frame.inward, -0.006);
    preview.renderOrder = 1;
    partGroup.add(preview);
};

const createPlungeFacePreview = (part, operation) => {
    const placement = plungeRoutePlacement(part, operation);
    const geometry = new THREE.PlaneGeometry(placement.frame.uSize, placement.frame.vSize);
    const material = new THREE.MeshBasicMaterial({
        color: '#397e9b',
        transparent: true,
        opacity: 0.1,
        depthWrite: false,
        side: THREE.DoubleSide,
    });
    const preview = new THREE.Mesh(geometry, material);
    preview.quaternion.setFromRotationMatrix(new THREE.Matrix4().makeBasis(
        placement.frame.u,
        placement.frame.v,
        placement.frame.inward,
    ));
    preview.position.copy(placement.frame.center).addScaledVector(placement.frame.inward, -0.006);
    preview.renderOrder = 1;
    partGroup.add(preview);
};

const createDrillFacePreview = (part, operation) => {
    const placement = drillPlacement(part, operation);
    const geometry = new THREE.PlaneGeometry(placement.frame.uSize, placement.frame.vSize);
    const material = new THREE.MeshBasicMaterial({
        color: '#397e9b',
        transparent: true,
        opacity: 0.1,
        depthWrite: false,
        side: THREE.DoubleSide,
    });
    const preview = new THREE.Mesh(geometry, material);
    preview.quaternion.setFromRotationMatrix(new THREE.Matrix4().makeBasis(
        placement.frame.u,
        placement.frame.v,
        placement.frame.inward,
    ));
    preview.position.copy(placement.frame.center).addScaledVector(placement.frame.inward, -0.006);
    preview.renderOrder = 1;
    partGroup.add(preview);
};

const createPlungeRouteHandles = (part, operation) => {
    const placement = plungeRoutePlacement(part, operation);
    const partExtent = Math.max(...Object.values(partSize(part)));
    const radius = clamp(partExtent * 0.018, 0.045, 0.11);
    const handles = [{ name: 'start', point: placement.startPoint, color: '#f4b44b' }];

    if (operation.route_mode === 'path') {
        handles.push({
            name: 'end',
            point: placement.startPoint.clone().addScaledVector(placement.path, placement.travelLength),
            color: '#d84d32',
        });
    }

    handles.forEach(({ name, point, color }) => {
        const handle = new THREE.Mesh(
            new THREE.SphereGeometry(radius, 18, 12),
            new THREE.MeshBasicMaterial({ color, depthTest: false }),
        );
        handle.position.copy(point).addScaledVector(placement.frame.inward, -radius * 0.35);
        handle.renderOrder = 5;
        handle.userData.operationId = operation.id;
        handle.userData.isOperationHelper = true;
        handle.userData.plungeHandle = name;
        operationHelpers.push(handle);
        partGroup.add(handle);
    });
};

const createGrooveEndpointHandles = (part, operation) => {
    const placement = groovePlacement(part, operation);
    const partExtent = Math.max(...Object.values(partSize(part)));
    const radius = clamp(partExtent * 0.018, 0.045, 0.11);

    for (const side of [-1, 1]) {
        const material = new THREE.MeshBasicMaterial({ color: side < 0 ? '#f4b44b' : '#d84d32', depthTest: false });
        const handle = new THREE.Mesh(new THREE.SphereGeometry(radius, 18, 12), material);
        handle.position.copy(placement.surfacePoint)
            .addScaledVector(placement.path, placement.length / 2 * side)
            .addScaledVector(placement.frame.inward, -radius * 0.35);
        handle.renderOrder = 5;
        handle.userData.operationId = operation.id;
        handle.userData.isOperationHelper = true;
        handle.userData.grooveHandle = side < 0 ? 'start' : 'end';
        operationHelpers.push(handle);
        partGroup.add(handle);
    }
};

const createOperationPreview = (part, operation) => {
    if (operation.enabled === false) return;

    let preview;

    if (operation.type === 'groove') {
        createGrooveFacePreview(part, operation);
        preview = operationPreviewObject(part, operation);
    } else if (operation.type === 'plunge_route') {
        createPlungeFacePreview(part, operation);
        preview = operationPreviewObject(part, operation);
    } else if (operation.type === 'drill') {
        createDrillFacePreview(part, operation);
        preview = operationPreviewObject(part, operation);
    } else if (operation.type === 'edge_roundover') {
        preview = operationPreviewObject(part, operation);
    } else {
        const { length, width, thickness } = partSize(part);
        const extent = Math.max(length, width, thickness) * 1.4;
        const plane = operation.type === 'cross_cut'
            ? crossCutPlane(part, operation)
            : { ...ripCutPlane(part, operation) };
        preview = new THREE.Mesh(new THREE.BoxGeometry(0.012, extent, extent));
        preview.quaternion.setFromUnitVectors(new THREE.Vector3(1, 0, 0), plane.normal);
        preview.position.copy(plane.point);
    }

    const isSelected = operation.id === props.selectedOperationId;
    preview.material = new THREE.MeshBasicMaterial({
        color: isSelected ? '#d84d32' : '#d98570',
        transparent: true,
        opacity: isSelected ? 0.34 : 0.1,
        depthWrite: false,
        side: THREE.DoubleSide,
    });
    preview.renderOrder = 3;
    preview.userData.operationId = operation.id;
    preview.userData.isOperationHelper = true;
    preview.userData.grooveHandle = operation.type === 'groove' ? 'body' : null;
    preview.userData.plungeHandle = operation.type === 'plunge_route' ? 'body' : null;
    preview.userData.drillHandle = operation.type === 'drill' ? 'body' : null;
    operationHelpers.push(preview);
    partGroup.add(preview);

    if (operation.type === 'groove') {
        createGrooveEndpointHandles(part, operation);
    }

    if (operation.type === 'plunge_route') {
        createPlungeRouteHandles(part, operation);
    }
};

const createOperationPreviews = (part) => {
    const selectedOperation = part.operations?.find((operation) => operation.id === props.selectedOperationId);

    if (selectedOperation) {
        createOperationPreview(part, selectedOperation);
    }
};

const removeFastenerMesh = (key, entry) => {
    entry.object.removeFromParent();
    disposeSceneObjects([entry.object]);
    fastenerMeshes.delete(key);
};

const syncFastenerMeshes = () => {
    const sources = new Map(objectMeshes.map(mesh => [mesh.userData.instanceId, mesh]));
    const displayed = new Set();
    if (props.mode === 'assembly') {
        const drillIds = new Set(props.fastenerMachiningPreview?.payload.fastener_ids);
        const focusedIds = new Set(props.focusedFasteners?.ids);
        const placements = [
            ...props.fasteners.filter(item => !drillIds.has(item.id) && (item.id !== props.selectedFastenerId || !props.fastenerPreview.length))
                .map(item => [item.id, item, false]),
            ...props.fastenerPreview.map((item, index) => [`preview-${index}`, item, true]),
        ];
        for (const [key, item, preview] of placements) {
            const source = sources.get(item.source_instance_id);
            if (!source) continue;
            displayed.add(key);
            const part = source.userData.part;
            const selected = props.selectedFastenerId === item.id;
            const status = !preview && focusedIds.has(item.id) ? props.focusedFasteners.status : null;
            const signature = JSON.stringify([item, part.dimensions, selected, status]);
            const entry = fastenerMeshes.get(key);
            if (entry?.object.parent === source && entry.signature === signature) continue;
            if (entry) removeFastenerMesh(key, entry);
            const object = createFastenerMesh(part, item, preview, selected, status);
            source.add(object);
            fastenerMeshes.set(key, { object, signature, preview });
        }
    }
    fastenerMeshes.forEach((entry, key) => { if (!displayed.has(key)) removeFastenerMesh(key, entry); });
};

const clearObjects = () => {
    clearConnectionHelpers();
    fastenerMeshes.forEach((entry, key) => removeFastenerMesh(key, entry));
    objectMeshes.splice(0);
    disposeSceneObjects(objectsGroup.children);
    objectsGroup.clear();
};

const clearPartObjects = (keepMesh = false) => {
    operationHelpers.splice(0);
    const discarded = partGroup.children.filter(child => !keepMesh || child !== partMesh);
    disposeSceneObjects(discarded);
    partGroup.remove(...discarded);
    if (!keepMesh) partMesh = null;
};

const updateMeshAppearance = (mesh, isSelected) => applyMeshAppearance(mesh, {
    selected: props.mode === 'assembly' && (isSelected || selectedGroupInstanceIdSet.value.has(mesh.userData.instanceId)),
    ghosted: props.mode === 'part' ? mesh.userData.instanceId !== null : ghostedInstanceIdSet.value.has(mesh.userData.instanceId),
    dimmed: props.mode === 'assembly' && focusedInstanceIdSet.value.size > 0
        && mesh.userData.instanceId !== null
        && !focusedInstanceIdSet.value.has(mesh.userData.instanceId),
});

const createMesh = (part, geometry, instance = null, edgeGeometry = null) => {
    const isSelected = instance?.id === props.selectedInstanceId;
    const material = new THREE.MeshStandardMaterial({
        color: palette[part.id % palette.length],
        emissive: '#000000',
        emissiveIntensity: 0,
        roughness: 0.72,
    });
    const mesh = new THREE.Mesh(geometry, material);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    mesh.userData.instanceId = instance?.id ?? null;
    mesh.userData.partName = part.name;
    mesh.userData.part = part;
    mesh.userData.geometrySize = partSize(part);

    const edges = new THREE.LineSegments(
        edgeGeometry ?? new THREE.EdgesGeometry(geometry),
        new THREE.LineBasicMaterial({ color: isSelected ? '#173d30' : '#6f5235' }),
    );
    mesh.userData.edges = edges;
    mesh.add(edges);
    updateMeshAppearance(mesh, isSelected);

    if (instance) {
        applyInstanceTransform(mesh, part, instance.position, instance.rotation);
        mesh.scale.x = instance.mirrored ? -1 : 1;
        objectMeshes.push(mesh);
        objectsGroup.add(mesh);
    } else {
        partMesh = mesh;
        renderedPartId = part.id;
        partGroup.add(mesh);
    }
};

const fallbackPartGeometry = (part) => {
    const { length, width, thickness } = partSize(part);

    return new THREE.BoxGeometry(length, thickness, width);
};

const fitAssemblyMesh = (mesh, { length, width, thickness }, mirrored) => {
    const geometry = mesh.userData.geometrySize;
    mesh.scale.set(
        (mirrored ? -1 : 1) * length / geometry.length,
        thickness / geometry.thickness,
        width / geometry.width,
    );
};

const rebuildPart = async () => {
    if (!partGroup) return;

    const currentVersion = ++rebuildVersion;
    geometryWorker.cancelAll();

    const part = props.mode === 'part' ? props.activePart : null;
    if (!part) return;

    clearPartObjects(renderedPartId === part.id);
    createOperationPreviews(part);

    try {
        const includeOffcut = ['cross_cut', 'rip_cut'].includes(selectedOperation.value?.type)
            ? props.selectedOperationId
            : null;
        const result = await geometryWorker.calculate(part, includeOffcut);

        if (currentVersion !== rebuildVersion || props.mode !== 'part' || props.activePart?.id !== part.id) {
            result.partGeometry?.dispose();
            result.offcutGeometry?.dispose();
            return;
        }

        clearPartObjects();
        createOperationPreviews(part);
        createMesh(part, result.partGeometry ?? fallbackPartGeometry(part));
        createOffcutPreview(part, result.offcutGeometry);
    } catch (error) {
        if (currentVersion !== rebuildVersion || props.mode !== 'part' || props.activePart?.id !== part.id || error.name === 'AbortError') return;

        console.error('Не удалось пересчитать геометрию в фоновом потоке.', error);
        clearPartObjects();
        createOperationPreviews(part);
        createMesh(part, fallbackPartGeometry(part));
    }
};

const rebuildAssembly = async () => {
    if (!objectsGroup) return;

    const currentVersion = ++assemblyRebuildVersion;
    const fastenerPreview = props.fastenerMachiningPreview;
    const machiningPreview = fastenerPreview ?? props.connectionMachiningPreview;
    assemblyGeometryWorker.cancelAll();
    const visibleParts = props.parts.filter((part) => part.instances?.length);
    const results = await Promise.all(visibleParts.map(async (part) => {
        try {
            const result = await assemblyGeometryWorker.calculate(part);
            return { part, geometry: result.partGeometry ?? fallbackPartGeometry(part), calculated: Boolean(result.partGeometry) };
        } catch (error) {
            if (error.name === 'AbortError') return { part, geometry: null };

            console.error('Не удалось пересчитать геометрию экземпляров в фоновом потоке.', error);
            return { part, geometry: fallbackPartGeometry(part) };
        }
    }));

    if (currentVersion !== assemblyRebuildVersion) {
        results.forEach(({ geometry }) => geometry?.dispose());
        return;
    }

    clearObjects();
    results.forEach(({ part, geometry }) => {
        if (!geometry) return;

        const edges = new THREE.EdgesGeometry(geometry);
        part.instances.forEach((instance) => createMesh(part, geometry, instance, edges));
    });
    syncAssemblyMeshes();
    updateSection();

    if (machiningPreview && machiningPreview === (props.fastenerMachiningPreview ?? props.connectionMachiningPreview)) {
        const rendered = new Map(results.filter(result => result.calculated)
            .flatMap(({ part }) => part.instances.map(instance => [instance.id, new Set(part.operations?.map(operation => operation.id))])));
        const success = Object.values(machiningPreview.parts).every(entry => {
            const ids = rendered.get(entry.instance_id);
            return ids && entry.operations.every(operation => ids.has(operation.id)) && entry.replaces.every(id => !ids.has(id));
        });
        emit(fastenerPreview ? 'fastener-preview-rendered' : 'connection-preview-rendered', machiningPreview, success);
    }

    if (props.mode === 'assembly' && props.focusRequestId > 0) {
        window.requestAnimationFrame(focusInstances);
    }
};

const schedulePartRebuild = () => {
    window.clearTimeout(rebuildTimer);
    rebuildTimer = window.setTimeout(() => {
        rebuildTimer = null;
        rebuildPart();
    }, 50);
};

const syncAssemblyMeshes = () => {
    const contextual = props.mode === 'part';
    if (!objectsGroup || (contextual && !props.partContextInstance)) return;

    const instances = new Map(props.parts.flatMap(part => (part.instances ?? []).map(instance => [instance.id, { part, instance }])));
    objectMeshes.forEach(mesh => {
        const entry = instances.get(mesh.userData.instanceId);
        if (!entry) { mesh.visible = false; return; }
        mesh.visible = contextual
            ? entry.instance.id !== props.partContextInstance.id && contextVisibleInstanceIdSet.value.has(entry.instance.id)
            : visibleInstanceIdSet.value === null || visibleInstanceIdSet.value.has(entry.instance.id);
        mesh.castShadow = !contextual;
        updateMeshAppearance(mesh, entry.instance.id === props.selectedInstanceId);
        if (pendingScaleIds?.has(entry.instance.id)) return;

        mesh.userData.part = entry.part;
        mesh.userData.partName = entry.part.name;
        applyInstanceTransform(mesh, entry.part, entry.instance.position, entry.instance.rotation);
        // Keep the saved dimensions visible while the replacement mesh is being built.
        fitAssemblyMesh(mesh, partSize(entry.part), entry.instance.mirrored);
    });
    if (contextual) {
        clearConnectionHelpers();
    } else {
        applyExplodedPositions();
    }
    syncFastenerMeshes();
};

const syncPartView = () => {
    if (!partGroup) return;

    const part = props.mode === 'part' ? props.activePart : null;
    const instance = part ? props.partContextInstance : null;
    objectsGroup.visible = !part || Boolean(instance);
    partGroup.visible = Boolean(part);
    if (partMesh) partMesh.visible = renderedPartId === part?.id;

    if (!part || instance) {
        if (assemblyCamera) {
            camera.position.copy(assemblyCamera.position);
            controls.target.copy(assemblyCamera.target);
            camera.near = assemblyCamera.near;
            camera.far = assemblyCamera.far;
            camera.updateProjectionMatrix();
            assemblyCamera = null;
            controls.update();
        }
        viewedPartId = null;
    }

    if (part) setPartEditingFrame(partGroup, part, instance);

    if (instance) {
        if (viewedContextId !== null && viewedContextId !== instance.id) {
            camera.position.add(partGroup.position.clone().sub(controls.target));
            controls.target.copy(partGroup.position);
            controls.update();
        }
    } else if (part) {
        const { length, width, thickness } = partSize(part);
        if (viewedPartId !== part.id) {
            assemblyCamera ??= { position: camera.position.clone(), target: controls.target.clone(), near: camera.near, far: camera.far };
            const direction = camera.position.clone().sub(controls.target).normalize();
            const radius = Math.hypot(length, width, thickness) / 2;
            const halfFov = THREE.MathUtils.degToRad(camera.fov / 2);
            const fitAngle = Math.min(halfFov, Math.atan(Math.tan(halfFov) * camera.aspect));
            controls.target.copy(partGroup.position);
            camera.position.copy(controls.target).addScaledVector(direction, Math.max(radius / Math.sin(fitAngle) * 1.12, 0.7));
            camera.near = 0.01;
            camera.far = Math.max(radius * 100, 1000);
            camera.updateProjectionMatrix();
            controls.update();
            viewedPartId = part.id;
        }
    }

    viewedContextId = instance?.id ?? null;
    syncAssemblyMeshes();
    updateSection();
};



const rayFromEvent = (event) => {
    const bounds = renderer.domElement.getBoundingClientRect();
    const pointer = new THREE.Vector2(
        ((event.clientX - bounds.left) / bounds.width) * 2 - 1,
        -((event.clientY - bounds.top) / bounds.height) * 2 + 1,
    );
    const raycaster = new THREE.Raycaster();
    raycaster.params.Line.threshold = 0.08;
    raycaster.setFromCamera(pointer, camera);

    return raycaster;
};

const pickPartStock = (raycaster) => partMesh?.visible ? raycaster.intersectObject(partMesh, false)[0] : null;

const preventContextMenu = (event) => event.preventDefault();

const selectedInstanceMesh = () => objectMeshes.find((mesh) => mesh.userData.instanceId === props.selectedInstanceId) ?? null;

const clearTransformGuide = () => {
    if (!transformGuide) return;

    transformGuide.geometry.dispose();
    transformGuide.material.dispose();
    objectsGroup.remove(transformGuide);
    transformGuide = null;
};

const showTransformGuide = (axis) => {
    clearTransformGuide();
    const origin = transformState.value?.initialWorldPosition;
    if (!origin) return;

    const direction = editorAxisDirections[axis];
    const geometry = new THREE.BufferGeometry().setFromPoints([
        direction.clone().multiplyScalar(-100),
        direction.clone().multiplyScalar(100),
    ]);
    const material = new THREE.LineDashedMaterial({
        color: { x: '#d34b45', y: '#3b9560', z: '#467bc2' }[axis],
        dashSize: 0.22,
        gapSize: 0.12,
        transparent: true,
        opacity: 0.85,
        depthTest: false,
    });
    transformGuide = new THREE.Line(geometry, material);
    transformGuide.computeLineDistances();
    transformGuide.position.copy(origin);
    transformGuide.renderOrder = 5;
    objectsGroup.add(transformGuide);
};

const sceneAxisName = (editorAxis) => ({ x: 'x', y: 'z', z: 'y' }[editorAxis]);

const boxAnchors = (box, axis) => [
    { value: box.min[axis], label: 'минимальная грань' },
    { value: (box.min[axis] + box.max[axis]) / 2, label: 'центр' },
    { value: box.max[axis], label: 'максимальная грань' },
];

const findMoveSnap = (meshes, editorAxis) => {
    const axis = sceneAxisName(editorAxis);
    const bounds = new THREE.Box3();
    for (const mesh of meshes) bounds.expandByObject(mesh);
    const selectedAnchors = boxAnchors(bounds, axis);
    const movingMeshes = new Set(meshes);
    const maximumDistance = 15 * millimeterScale;
    let nearest = null;

    objectMeshes.forEach((candidate) => {
        if (movingMeshes.has(candidate) || !candidate.visible) return;

        const candidateAnchors = boxAnchors(new THREE.Box3().setFromObject(candidate), axis);

        selectedAnchors.forEach((selectedAnchor) => {
            candidateAnchors.forEach((candidateAnchor) => {
                const delta = candidateAnchor.value - selectedAnchor.value;

                if (Math.abs(delta) > maximumDistance || (nearest && Math.abs(delta) >= Math.abs(nearest.delta))) return;

                nearest = {
                    delta,
                    label: `${selectedAnchor.label} → ${candidate.userData.partName}: ${candidateAnchor.label}`,
                };
            });
        });
    });

    return nearest;
};

const applyTransformPreview = (value, { snap = false } = {}) => {
    const state = transformState.value;
    if (state?.mode === 'scale') {
        state.scaleError = '';
        let poses;
        try {
            poses = state.members.map(member => scaleAssemblyPose(member, state.pivot, state.axis, value));
        } catch (error) {
            state.scaleError = error.message;
            return;
        }

        const meshes = new Map(objectMeshes.map(mesh => [mesh.userData.instanceId, mesh]));
        state.members.forEach(({ part, instance }, index) => {
            const mesh = meshes.get(instance.id);
            if (!mesh) return;
            const { position, size: [length, width, thickness] } = poses[index];
            applyInstanceTransform(mesh, part, position, instance.rotation);
            fitAssemblyMesh(mesh, {
                length: length * millimeterScale,
                width: width * millimeterScale,
                thickness: thickness * millimeterScale,
            }, instance.mirrored);
        });
        state.value = value;
        updateConnectionHelpers();
        return;
    }
    if (!state?.axis) return;

    if (state.group) {
        const members = new Map(state.members.map(member => [member.id, member]));
        const meshes = objectMeshes.filter(mesh => members.has(mesh.userData.instanceId));
        const move = (delta) => {
            for (const mesh of meshes) {
                const member = members.get(mesh.userData.instanceId);
                applyInstanceTransform(mesh, mesh.userData.part, translatedPosition(member.position, state.axis, delta), member.rotation);
            }
        };
        move(value);
        state.snap = snap ? findMoveSnap(meshes.filter(mesh => mesh.visible), state.axis) : null;
        if (state.snap) {
            value += state.snap.delta / millimeterScale;
            move(value);
        }
        state.value = value;
        updateConnectionHelpers();
        if (transformGuide) transformGuide.position.copy(state.initialWorldPosition)
            .addScaledVector(editorAxisDirections[state.axis], value * millimeterScale);
        return;
    }

    const data = selectedInstanceData.value;
    const mesh = selectedInstanceMesh();

    if (!state || !state.axis || !data || !mesh) return;

    const position = { ...state.initialPosition };
    const rotation = { ...state.initialRotation };

    if (state.mode === 'move') {
        position[state.axis] += value;
    } else {
        rotation[state.axis] += value;
    }

    applyInstanceTransform(mesh, data.part, position, rotation);
    applyExplodedPositions();
    state.snap = null;

    if (state.mode === 'move' && snap && Number(props.explodeDistance) === 0) {
        const snapped = findMoveSnap([mesh], state.axis);

        if (snapped) {
            const snappedOffset = snapped.delta / millimeterScale;
            position[state.axis] += snappedOffset;
            value += snappedOffset;
            state.snap = snapped;
            applyInstanceTransform(mesh, data.part, position, rotation);
            applyExplodedPositions();
        }
    }

    state.position = position;
    state.rotation = rotation;
    state.value = value;

    if (transformGuide) {
        transformGuide.position.copy(mesh.position);
    }
};

const beginTransform = (mode) => {
    if (props.mode !== 'assembly' || props.transformBusy || pendingScaleIds || props.measurementTool) return;
    if (mode === 'scale' && !props.commitScale) return;
    if (mode !== 'rotate' && props.groupMoveBlock) {
        emit('blocked-group-move', props.groupMoveBlock);
        return;
    }
    if (Number(props.explodeDistance) > 0) return;
    const groupIds = new Set(props.groupMoveInstanceIds);
    const group = groupIds.size > 0 && mode !== 'rotate';
    const data = selectedInstanceData.value;
    const mesh = selectedInstanceMesh();

    if (!group && (!data || !mesh || lockedInstanceIdSet.value.has(data.instance.id))) return;
    const visibleMeshes = group ? objectMeshes.filter(item => item.visible && groupIds.has(item.userData.instanceId)) : [];
    if (group && !visibleMeshes.length) {
        emit('blocked-group-move', 'Покажите узел на сцене перед перемещением.');
        return;
    }

    const selectedIds = group ? groupIds : new Set([data.instance.id]);
    if (mode === 'scale' && props.connections.some(connection => selectedIds.has(connection.primary_instance_id) || selectedIds.has(connection.secondary_instance_id))) {
        emit('blocked-group-move', 'Масштаб недоступен для деталей со столярными соединениями.');
        return;
    }

    if (transformState.value) finishTransform(false);

    if (!hasPointerPosition) {
        const bounds = renderer.domElement.getBoundingClientRect();
        lastPointerPosition.set(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2);
    }

    const initialWorldPosition = new THREE.Vector3();
    if (group) {
        const bounds = new THREE.Box3();
        for (const item of visibleMeshes) bounds.expandByObject(item);
        bounds.getCenter(initialWorldPosition);
    } else {
        mesh.getWorldPosition(initialWorldPosition);
    }
    const state = {
        mode,
        axis: null,
        group,
        initialWorldPosition: { x: initialWorldPosition.x, y: initialWorldPosition.y, z: initialWorldPosition.z },
        value: mode === 'scale' ? 1 : 0,
        scalePointerBase: 1,
        pointerValue: null,
        numericInput: '',
        snap: null,
        startPointer: { x: lastPointerPosition.x, y: lastPointerPosition.y },
    };
    if (mode === 'scale') {
        const bounds = renderer.domElement.getBoundingClientRect();
        const screen = initialWorldPosition.clone().project(camera);
        state.screenPivot = {
            x: bounds.left + (screen.x + 1) * bounds.width / 2,
            y: bounds.top + (1 - screen.y) * bounds.height / 2,
        };
        state.pivot = { x: initialWorldPosition.x / millimeterScale, y: initialWorldPosition.z / millimeterScale, z: initialWorldPosition.y / millimeterScale };
        state.members = props.parts.flatMap(part => (part.instances ?? [])
            .filter(instance => selectedIds.has(instance.id))
            .map(instance => ({ part, instance: { ...instance, position: { ...instance.position }, rotation: { ...instance.rotation } } })));
    } else if (group) {
        state.members = props.parts.flatMap(part => (part.instances ?? [])
            .filter(instance => groupIds.has(instance.id))
            .map(instance => ({ id: instance.id, position: { ...instance.position }, rotation: { ...instance.rotation } })));
    } else {
        Object.assign(state, {
            instanceId: data.instance.id,
            initialPosition: { ...data.instance.position },
            initialRotation: { ...data.instance.rotation },
            position: { ...data.instance.position },
            rotation: { ...data.instance.rotation },
        });
    }
    transformState.value = state;
    controls.enabled = false;
    renderer.domElement.style.cursor = 'crosshair';
};

const selectTransformAxis = (axis) => {
    if (!transformState.value) return;

    if (transformState.value.mode === 'scale' && transformState.value.axis === axis) axis = null;
    transformState.value.axis = axis;
    transformState.value.numericInput = '';
    transformState.value.pointerValue = null;
    transformState.value.snap = null;
    transformState.value.startPointer = { x: lastPointerPosition.x, y: lastPointerPosition.y };
    if (transformState.value.mode === 'scale') {
        const state = transformState.value;
        state.scalePointerBase = state.value;
        applyTransformPreview(state.value);
    } else {
        applyTransformPreview(0);
    }
    if (axis) showTransformGuide(axis);
    else clearTransformGuide();
};

const moveValueFromPointer = (event) => {
    const state = transformState.value;

    if (!state?.axis) return 0;

    const bounds = renderer.domElement.getBoundingClientRect();
    const axisVector = editorAxisDirections[state.axis];
    const worldPosition = new THREE.Vector3(
        state.initialWorldPosition.x,
        state.initialWorldPosition.y,
        state.initialWorldPosition.z,
    );
    const projectedStart = worldPosition.clone().project(camera);
    const projectedEnd = worldPosition.clone().add(axisVector).project(camera);
    const screenAxis = new THREE.Vector2(
        (projectedEnd.x - projectedStart.x) * bounds.width / 2,
        -(projectedEnd.y - projectedStart.y) * bounds.height / 2,
    );

    if (screenAxis.lengthSq() < 0.0001) {
        screenAxis.set(1, 0);
    } else {
        screenAxis.normalize();
    }

    const pointerDelta = new THREE.Vector2(
        event.clientX - state.startPointer.x,
        event.clientY - state.startPointer.y,
    );
    const distance = camera.position.distanceTo(worldPosition);
    const worldPerPixel = 2 * distance * Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2) / Math.max(bounds.height, 1);
    return Math.round(pointerDelta.dot(screenAxis) * worldPerPixel / millimeterScale * 10) / 10;
};

const rotationValueFromPointer = (event) => {
    const state = transformState.value;

    if (!state) return 0;

    const horizontal = event.clientX - state.startPointer.x;
    const vertical = state.startPointer.y - event.clientY;
    const precision = event.shiftKey ? 0.1 : 1;

    return Math.round((horizontal + vertical) * 0.35 * precision * 10) / 10;
};

const finishTransform = (shouldCommit) => {
    const state = transformState.value;

    if (!state) return;

    if (state.mode === 'scale') {
        if (shouldCommit && state.scaleError) return;
        const { members, pivot, axis, value } = state;
        if (shouldCommit && value !== 1) {
            pendingScaleIds = new Set(members.map(({ instance }) => instance.id));
            void props.commitScale({ members, pivot, axis, value }).finally(() => {
                pendingScaleIds = null;
                syncAssemblyMeshes();
            });
        } else {
            applyTransformPreview(1);
        }
    } else if (state.group) {
        const { members, axis, value } = state;
        applyTransformPreview(0);
        if (shouldCommit && axis && value !== 0) emit('commit-group-move', { members, axis, value });
    } else if (shouldCommit && state.axis) {
        emit('commit-instance-transform', {
            instanceId: state.instanceId,
            position: state.position,
            rotation: state.rotation,
            initialPosition: state.initialPosition,
            initialRotation: state.initialRotation,
        });
    } else {
        applyTransformPreview(0);
    }

    transformState.value = null;
    clearTransformGuide();
    controls.enabled = true;
    renderer.domElement.style.cursor = '';
};

const grooveFaceFromNormal = (normal) => {
    const absolute = { x: Math.abs(normal.x), y: Math.abs(normal.y), z: Math.abs(normal.z) };

    if (absolute.x >= absolute.y && absolute.x >= absolute.z) {
        return normal.x >= 0 ? 'end' : 'start';
    }

    if (absolute.y >= absolute.z) {
        return normal.y >= 0 ? 'top' : 'bottom';
    }

    return normal.z >= 0 ? 'right' : 'left';
};

const roundoverEdgeFromIntersection = (part, intersection) => {
    const point = intersection.object.worldToLocal(intersection.point.clone());
    const face = grooveFaceFromNormal(intersection.face.normal);
    const { length, width, thickness } = partSize(part);
    const candidates = {
        top: [
            ['top_left', Math.abs(point.z + width / 2)],
            ['top_right', Math.abs(point.z - width / 2)],
            ['top_start', Math.abs(point.x + length / 2)],
            ['top_end', Math.abs(point.x - length / 2)],
        ],
        bottom: [
            ['bottom_left', Math.abs(point.z + width / 2)],
            ['bottom_right', Math.abs(point.z - width / 2)],
            ['bottom_start', Math.abs(point.x + length / 2)],
            ['bottom_end', Math.abs(point.x - length / 2)],
        ],
        left: [
            ['top_left', Math.abs(point.y - thickness / 2)],
            ['bottom_left', Math.abs(point.y + thickness / 2)],
            ['start_left', Math.abs(point.x + length / 2)],
            ['end_left', Math.abs(point.x - length / 2)],
        ],
        right: [
            ['top_right', Math.abs(point.y - thickness / 2)],
            ['bottom_right', Math.abs(point.y + thickness / 2)],
            ['start_right', Math.abs(point.x + length / 2)],
            ['end_right', Math.abs(point.x - length / 2)],
        ],
        start: [
            ['top_start', Math.abs(point.y - thickness / 2)],
            ['bottom_start', Math.abs(point.y + thickness / 2)],
            ['start_left', Math.abs(point.z + width / 2)],
            ['start_right', Math.abs(point.z - width / 2)],
        ],
        end: [
            ['top_end', Math.abs(point.y - thickness / 2)],
            ['bottom_end', Math.abs(point.y + thickness / 2)],
            ['end_left', Math.abs(point.z + width / 2)],
            ['end_right', Math.abs(point.z - width / 2)],
        ],
    }[face];

    return candidates.reduce((nearest, candidate) => candidate[1] < nearest[1] ? candidate : nearest)[0];
};

const pointerDown = (event) => {
    lastAssemblyPick = null;
    lastPointerPosition.set(event.clientX, event.clientY);
    hasPointerPosition = true;

    if (dragState?.pointerId === event.pointerId) finishToolDrag();

    if (props.mode === 'assembly' && transformState.value) {
        if (event.button === 0) {
            finishTransform(true);
        } else if (event.button === 2) {
            finishTransform(false);
        }

        event.preventDefault();
        return;
    }

    const raycaster = rayFromEvent(event);
    const isAltRotate = event.button === 0 && event.altKey;

    if (isAltRotate) {
        controls.mouseButtons.LEFT = THREE.MOUSE.ROTATE;
        return;
    }

    controls.mouseButtons.LEFT = null;

    if (props.mode === 'assembly' && props.fastenerMode && event.button === 0) {
        event.preventDefault();
        event.stopImmediatePropagation();
        const selectable = objectMeshes.filter(mesh => mesh.visible && canPickInstance(mesh.userData.instanceId)
            && !lockedInstanceIdSet.value.has(mesh.userData.instanceId));
        const hit = raycaster.intersectObjects(selectable, false)[0];
        const selectableSet = new Set(selectable);
        const placed = props.fastenerRowCopy ? [] : [...fastenerMeshes.values()].filter(entry => !entry.preview && selectableSet.has(entry.object.parent));
        const screw = raycaster.intersectObjects(placed.map(entry => entry.object), true)[0];
        if (screw && screw.distance <= (hit?.distance ?? Infinity) + 0.001) {
            emit('select-fastener', screw.object.userData.fastenerId);
            return;
        }
        if (!hit?.face) return;
        const part = hit.object.userData.part;
        const face = grooveFaceFromNormal(hit.face.normal);
        const frame = grooveFaceFrame(part, face);
        const point = hit.object.worldToLocal(hit.point.clone()).sub(frame.center);
        if (Math.abs(point.dot(frame.inward)) > 0.001) return;
        emit('pick-fastener-point', {
            source_instance_id: hit.object.userData.instanceId,
            face,
            center_u: Math.round(clamp((point.dot(frame.u) + frame.uSize / 2) / millimeterScale, 0, frame.uSize / millimeterScale) * 100) / 100,
            center_v: Math.round(clamp((point.dot(frame.v) + frame.vSize / 2) / millimeterScale, 0, frame.vSize / millimeterScale) * 100) / 100,
        });
        return;
    }

    if (props.mode === 'assembly' && props.measurementTool && event.button === 0) {
        const intersection = raycaster.intersectObjects(objectMeshes.filter(mesh => mesh.visible), false)[0];

        if (intersection) {
            addMeasurementPoint(intersection.point);
            event.preventDefault();
        }

        return;
    }

    if (props.mode === 'part') {
        if (event.button !== 0) return;

        const helper = raycaster.intersectObjects(operationHelpers, false)[0]?.object;

        if (!helper && selectedOperation.value?.type === 'groove') {
            const stockIntersection = pickPartStock(raycaster);

            if (stockIntersection?.face) {
                const face = grooveFaceFromNormal(stockIntersection.face.normal);
                const frame = grooveFaceFrame(props.activePart, face);
                const maximumLength = Math.max(frame.uSize / millimeterScale * 0.8, 0.1);
                emit('update-operation', selectedOperation.value.id, {
                    face,
                    center_u: frame.uSize / millimeterScale / 2,
                    center_v: frame.vSize / millimeterScale / 2,
                    path_angle: 0,
                    groove_length: Math.min(Number(selectedOperation.value.groove_length), maximumLength),
                    depth: Math.min(Number(selectedOperation.value.depth), frame.maximumDepth / millimeterScale),
                });
                event.preventDefault();
            }

            return;
        }

        if (!helper && selectedOperation.value?.type === 'edge_roundover') {
            const stockIntersection = pickPartStock(raycaster);

            if (stockIntersection?.face) {
                const edge = roundoverEdgeFromIntersection(props.activePart, stockIntersection);
                emit('update-operation', selectedOperation.value.id, {
                    edge,
                    radius: Math.min(Number(selectedOperation.value.radius), edgeRoundoverMaximumRadius(props.activePart, edge)),
                });
                event.preventDefault();
            }

            return;
        }

        if (!helper && selectedOperation.value?.type === 'plunge_route') {
            const stockIntersection = pickPartStock(raycaster);

            if (stockIntersection?.face) {
                const operation = selectedOperation.value;
                const face = grooveFaceFromNormal(stockIntersection.face.normal);
                const frame = grooveFaceFrame(props.activePart, face);
                const localPoint = stockIntersection.object.worldToLocal(stockIntersection.point.clone());
                const relativePoint = localPoint.sub(frame.center);
                const maximumU = frame.uSize / millimeterScale;
                const maximumV = frame.vSize / millimeterScale;
                const radius = Math.min(plungeMaximumRadius(operation), maximumU / 2, maximumV / 2);
                const travelLength = operation.route_mode === 'path'
                    ? Math.min(Number(operation.travel_length), Math.max(maximumU - radius * 2, 0.1))
                    : 0;
                const nextOperation = {
                    ...operation,
                    face,
                    path_angle: 0,
                    travel_length: travelLength,
                };
                const start = clampPlungeStart(
                    nextOperation,
                    frame,
                    relativePoint.dot(frame.u) / millimeterScale + maximumU / 2,
                    relativePoint.dot(frame.v) / millimeterScale + maximumV / 2,
                );
                emit('update-operation', operation.id, {
                    face,
                    start_u: Math.round(start.start_u * 10) / 10,
                    start_v: Math.round(start.start_v * 10) / 10,
                    path_angle: 0,
                    travel_length: travelLength,
                    depth: Math.min(Number(operation.depth), frame.maximumDepth / millimeterScale),
                });
                event.preventDefault();
            }

            return;
        }

        if (!helper && selectedOperation.value?.type === 'drill') {
            const stockIntersection = pickPartStock(raycaster);

            if (stockIntersection?.face) {
                const operation = selectedOperation.value;
                const face = grooveFaceFromNormal(stockIntersection.face.normal);
                const frame = grooveFaceFrame(props.activePart, face);
                const localPoint = stockIntersection.object.worldToLocal(stockIntersection.point.clone());
                const relativePoint = localPoint.sub(frame.center);
                const center = clampDrillCenter(
                    operation,
                    frame,
                    relativePoint.dot(frame.u) / millimeterScale + frame.uSize / millimeterScale / 2,
                    relativePoint.dot(frame.v) / millimeterScale + frame.vSize / millimeterScale / 2,
                );
                emit('update-operation', operation.id, {
                    face,
                    center_u: Math.round(center.center_u * 10) / 10,
                    center_v: Math.round(center.center_v * 10) / 10,
                    diameter: Math.min(Number(operation.diameter), frame.uSize / millimeterScale, frame.vSize / millimeterScale),
                    depth: operation.through
                        ? frame.maximumDepth / millimeterScale
                        : Math.min(Number(operation.depth), frame.maximumDepth / millimeterScale),
                });
                event.preventDefault();
            }

            return;
        }

        if (!helper) return;

        const operation = props.activePart?.operations?.find((item) => item.id === helper.userData.operationId);

        if (!operation) return;

        emit('select-operation', operation.id);

        if (operation.type === 'edge_roundover') {
            event.preventDefault();
            return;
        }

        const dragPlane = ['groove', 'plunge_route', 'drill'].includes(operation.type)
            ? partFaceDragPlane(partGroup, grooveFaceFrame(props.activePart, operation.face))
            : new THREE.Plane().setFromNormalAndCoplanarPoint(
                camera.getWorldDirection(new THREE.Vector3()),
                helper.getWorldPosition(new THREE.Vector3()),
            );

        const startPoint = raycaster.ray.intersectPlane(dragPlane, new THREE.Vector3());

        if (!startPoint) return;

        dragState = {
            pointerId: event.pointerId,
            operation: { ...operation },
            dragPlane,
            startPoint: partGroup.worldToLocal(startPoint),
            grooveHandle: helper.userData.grooveHandle ?? null,
            plungeHandle: helper.userData.plungeHandle ?? null,
            drillHandle: helper.userData.drillHandle ?? null,
        };
        renderer.domElement.setPointerCapture(event.pointerId);
        renderer.domElement.style.cursor = 'grabbing';
        event.preventDefault();
        event.stopImmediatePropagation();

        return;
    }

    if (props.mode !== 'assembly' || event.button !== 0) return;

    const connectionIntersection = connectionsGroup
        ? raycaster.intersectObjects(connectionsGroup.children, false).find(({ object }) => {
            const connection = props.connections.find(item => item.id === object.userData.connectionId);
            return connection && canPickInstance(connection.primary_instance_id) && canPickInstance(connection.secondary_instance_id);
        })
        : null;

    if (connectionIntersection?.object.userData.connectionId) {
        const connectionId = connectionIntersection.object.userData.connectionId;
        const role = connectionIntersection.object.userData.connectionRole;
        const connection = props.connections.find((item) => item.id === connectionId);

        emit('select-connection', connectionId);

        if (connectionId === props.selectedConnectionId && role && connection) {
            const instanceId = connection[`${role}_instance_id`];
            const mesh = objectMeshes.find((candidate) => candidate.userData.instanceId === instanceId);

            if (mesh) {
                const placement = connectionPoint(connection, role, mesh);
                const worldNormal = placement.frame.inward.clone().applyQuaternion(mesh.quaternion).normalize();
                const dragPlane = new THREE.Plane().setFromNormalAndCoplanarPoint(worldNormal, placement.point);

                dragState = {
                    pointerId: event.pointerId,
                    connectionId,
                    connectionRole: role,
                    dragPlane,
                    frame: placement.frame,
                    mesh,
                    surface: placement.surface,
                };
                renderer.domElement.setPointerCapture(event.pointerId);
                renderer.domElement.style.cursor = 'grabbing';
                event.stopImmediatePropagation();
            }
        }

        event.preventDefault();
        return;
    }

    const intersection = raycaster.intersectObjects(objectMeshes.filter(mesh => mesh.visible && canPickInstance(mesh.userData.instanceId)), false)[0];

    const instanceId = intersection?.object.userData.instanceId ?? null;
    lastAssemblyPick = instanceId;
    emit('select-instance', instanceId);
};

const openPickedGroup = (event) => {
    if (props.mode !== 'assembly' || event.altKey || props.measurementTool || lastAssemblyPick === null) return;
    emit('open-instance-group', lastAssemblyPick);
    lastAssemblyPick = null;
};

const cancelOperationToolHide = () => {
    if (hoverClearTimer !== null) {
        window.clearTimeout(hoverClearTimer);
        hoverClearTimer = null;
    }
};

const scheduleOperationToolHide = () => {
    if (anglePanelOpen.value || operationToolHovered.value || hoverClearTimer !== null) return;

    hoverClearTimer = window.setTimeout(() => {
        hoveredOperationId.value = null;
        hoverClearTimer = null;
    }, 220);
};

const keepOperationToolVisible = () => {
    operationToolHovered.value = true;
    cancelOperationToolHide();
};

const releaseOperationTool = () => {
    operationToolHovered.value = false;
    scheduleOperationToolHide();
};

const scalePointerMove = (event) => {
    if (props.mode !== 'assembly' || !event.isPrimary) return;

    lastPointerPosition.set(event.clientX, event.clientY);
    hasPointerPosition = true;
    const state = transformState.value;
    if (state?.mode !== 'scale') return;

    const factor = state.scalePointerBase * scaleFactorFromPointer(state.startPointer, lastPointerPosition, state.screenPivot, state.axis !== null);
    state.numericInput = '';
    applyTransformPreview(factor);
};

const pointerMove = (event) => {
    lastPointerPosition.set(event.clientX, event.clientY);
    hasPointerPosition = true;

    if (dragState && event.pointerId !== dragState.pointerId) return;

    if (props.mode === 'assembly' && transformState.value) {
        const state = transformState.value;
        if (state.mode !== 'scale' && state.axis) {
            transformState.value.numericInput = '';
            const value = transformState.value.mode === 'move'
                ? moveValueFromPointer(event)
                : rotationValueFromPointer(event);
            transformState.value.pointerValue = value;
            applyTransformPreview(value, { snap: transformState.value.mode === 'move' && event.shiftKey });
        }

        return;
    }

    if (props.mode === 'assembly' && dragState?.connectionId) {
        const worldPoint = rayFromEvent(event).ray.intersectPlane(dragState.dragPlane, new THREE.Vector3());

        if (!worldPoint) return;

        const localPoint = dragState.mesh.worldToLocal(worldPoint.clone());
        const relativePoint = localPoint.sub(dragState.frame.center);
        const centerU = clamp(
            relativePoint.dot(dragState.frame.u) / millimeterScale + dragState.surface.u / 2,
            0,
            dragState.surface.u,
        );
        const centerV = clamp(
            relativePoint.dot(dragState.frame.v) / millimeterScale + dragState.surface.v / 2,
            0,
            dragState.surface.v,
        );
        emit('update-connection-parameters', dragState.connectionId, {
            [`${dragState.connectionRole}_center_u`]: Math.round(centerU * 10) / 10,
            [`${dragState.connectionRole}_center_v`]: Math.round(centerV * 10) / 10,
        });

        return;
    }

    if (!dragState) {
        if (props.mode === 'part') {
            const raycaster = rayFromEvent(event);
            const hoveredHelper = raycaster.intersectObjects(operationHelpers, false)[0]?.object;
            const hoveredStock = ['groove', 'edge_roundover', 'plunge_route', 'drill'].includes(selectedOperation.value?.type)
                ? pickPartStock(raycaster)?.object
                : null;

            if (hoveredHelper) {
                cancelOperationToolHide();
                hoveredOperationId.value = hoveredHelper.userData.operationId;
            } else {
                scheduleOperationToolHide();
            }

            renderer.domElement.style.cursor = hoveredHelper
                ? ['groove', 'plunge_route', 'drill'].includes(selectedOperation.value?.type) ? 'grab' : 'pointer'
                : hoveredStock ? 'pointer' : '';
        } else if (props.mode === 'assembly') {
            const connectionObject = connectionsGroup
                ? rayFromEvent(event).intersectObjects(connectionsGroup.children, false)[0]?.object
                : null;
            renderer.domElement.style.cursor = connectionObject?.userData.connectionRole
                ? connectionObject.userData.connectionId === props.selectedConnectionId ? 'grab' : 'pointer'
                : '';
        }

        return;
    }

    if (!props.activePart) return;

    const point = rayFromEvent(event).ray.intersectPlane(dragState.dragPlane, new THREE.Vector3());

    if (!point) return;

    const movement = partGroup.worldToLocal(point).sub(dragState.startPoint);
    const operation = dragState.operation;
    const dimensions = props.activePart.dimensions;

    if (operation.type === 'cross_cut') {
        emit('update-operation', operation.id, {
            position: Math.round(clamp(Number(operation.position) + movement.x / millimeterScale, 0, Number(dimensions.length)) * 10) / 10,
        });
    }

    if (operation.type === 'rip_cut') {
        const direction = operation.reference_side === 'right' ? -1 : 1;
        const offsetDelta = movement.z / millimeterScale * direction;
        emit('update-operation', operation.id, {
            start_offset: Math.round(clamp(Number(operation.start_offset) + offsetDelta, 0, Number(dimensions.width)) * 10) / 10,
            end_offset: Math.round(clamp(Number(operation.end_offset) + offsetDelta, 0, Number(dimensions.width)) * 10) / 10,
        });
    }

    if (operation.type === 'groove') {
        const frame = grooveFaceFrame(props.activePart, operation.face);
        const movementU = movement.dot(frame.u) / millimeterScale;
        const movementV = movement.dot(frame.v) / millimeterScale;

        if (dragState.grooveHandle === 'body') {
            const center = clampGrooveCenter(
                operation,
                frame,
                Number(operation.center_u) + movementU,
                Number(operation.center_v) + movementV,
            );
            emit('update-operation', operation.id, {
                center_u: Math.round(center.center_u * 10) / 10,
                center_v: Math.round(center.center_v * 10) / 10,
            });
            return;
        }

        const angle = THREE.MathUtils.degToRad(Number(operation.path_angle));
        const halfLength = Number(operation.groove_length) / 2;
        const directionU = Math.cos(angle) * halfLength;
        const directionV = Math.sin(angle) * halfLength;
        const start = { u: Number(operation.center_u) - directionU, v: Number(operation.center_v) - directionV };
        const end = { u: Number(operation.center_u) + directionU, v: Number(operation.center_v) + directionV };
        const movingPoint = dragState.grooveHandle === 'start' ? start : end;
        const fixedPoint = dragState.grooveHandle === 'start' ? end : start;
        const edgeMargin = Math.min(Number(operation.width) / 2, frame.uSize / millimeterScale / 2, frame.vSize / millimeterScale / 2);
        const movedPoint = {
            u: clamp(movingPoint.u + movementU, edgeMargin, frame.uSize / millimeterScale - edgeMargin),
            v: clamp(movingPoint.v + movementV, edgeMargin, frame.vSize / millimeterScale - edgeMargin),
        };
        const nextStart = dragState.grooveHandle === 'start' ? movedPoint : fixedPoint;
        const nextEnd = dragState.grooveHandle === 'start' ? fixedPoint : movedPoint;
        const deltaU = nextEnd.u - nextStart.u;
        const deltaV = nextEnd.v - nextStart.v;
        const grooveLength = Math.max(Math.hypot(deltaU, deltaV), 0.1);
        const pathAngle = THREE.MathUtils.radToDeg(Math.atan2(deltaV, deltaU));
        const draftOperation = { ...operation, groove_length: grooveLength, path_angle: pathAngle };
        const center = clampGrooveCenter(
            draftOperation,
            frame,
            (nextStart.u + nextEnd.u) / 2,
            (nextStart.v + nextEnd.v) / 2,
        );
        emit('update-operation', operation.id, {
            center_u: Math.round(center.center_u * 10) / 10,
            center_v: Math.round(center.center_v * 10) / 10,
            groove_length: Math.round(grooveLength * 10) / 10,
            path_angle: Math.round(pathAngle * 10) / 10,
        });
    }

    if (operation.type === 'plunge_route') {
        const frame = grooveFaceFrame(props.activePart, operation.face);
        const movementU = movement.dot(frame.u) / millimeterScale;
        const movementV = movement.dot(frame.v) / millimeterScale;

        if (dragState.plungeHandle !== 'end') {
            const start = clampPlungeStart(
                operation,
                frame,
                Number(operation.start_u) + movementU,
                Number(operation.start_v) + movementV,
            );
            emit('update-operation', operation.id, {
                start_u: Math.round(start.start_u * 10) / 10,
                start_v: Math.round(start.start_v * 10) / 10,
            });
            return;
        }

        const angle = THREE.MathUtils.degToRad(Number(operation.path_angle));
        const currentEndU = Number(operation.start_u) + Math.cos(angle) * Number(operation.travel_length);
        const currentEndV = Number(operation.start_v) + Math.sin(angle) * Number(operation.travel_length);
        const radius = Math.min(
            plungeMaximumRadius(operation),
            frame.uSize / millimeterScale / 2,
            frame.vSize / millimeterScale / 2,
        );
        const endU = clamp(currentEndU + movementU, radius, frame.uSize / millimeterScale - radius);
        const endV = clamp(currentEndV + movementV, radius, frame.vSize / millimeterScale - radius);
        const deltaU = endU - Number(operation.start_u);
        const deltaV = endV - Number(operation.start_v);
        emit('update-operation', operation.id, {
            path_angle: Math.round(THREE.MathUtils.radToDeg(Math.atan2(deltaV, deltaU)) * 10) / 10,
            travel_length: Math.round(Math.max(Math.hypot(deltaU, deltaV), 0.1) * 10) / 10,
        });
    }

    if (operation.type === 'drill') {
        const frame = grooveFaceFrame(props.activePart, operation.face);
        const center = clampDrillCenter(
            operation,
            frame,
            Number(operation.center_u) + movement.dot(frame.u) / millimeterScale,
            Number(operation.center_v) + movement.dot(frame.v) / millimeterScale,
        );
        emit('update-operation', operation.id, {
            center_u: Math.round(center.center_u * 10) / 10,
            center_v: Math.round(center.center_v * 10) / 10,
        });
    }
};

const finishToolDrag = () => {
    if (!dragState) return;

    const { pointerId, connectionId } = dragState;
    dragState = null;

    if (renderer.domElement.hasPointerCapture(pointerId)) {
        renderer.domElement.releasePointerCapture(pointerId);
    }

    renderer.domElement.style.cursor = '';

    if (connectionId) emit('commit-connection-parameters', connectionId);
};

const pointerUp = (event) => {
    controls.mouseButtons.LEFT = null;

    if (dragState?.pointerId === event.pointerId) finishToolDrag();
};

const positionOperationControls = () => {
    if (!operationControls.value) return;

    const helper = operationHelpers.find((item) => item.userData.operationId === props.selectedOperationId);

    if (!helper) {
        operationControls.value.style.display = 'none';
        return;
    }

    const { clientWidth, clientHeight } = viewport.value;
    helper.geometry.computeBoundingBox();
    helper.updateWorldMatrix(true, false);
    const bounds = helper.geometry.boundingBox;
    const projectedCorners = [];

    for (const x of [bounds.min.x, bounds.max.x]) {
        for (const y of [bounds.min.y, bounds.max.y]) {
            for (const z of [bounds.min.z, bounds.max.z]) {
                projectedCorners.push(new THREE.Vector3(x, y, z).applyMatrix4(helper.matrixWorld).project(camera));
            }
        }
    }

    const position = projectedCorners.reduce((best, corner) => corner.x + corner.y > best.x + best.y ? corner : best);
    const controlWidth = operationControls.value.offsetWidth || (anglePanelOpen.value ? 252 : 38);
    const controlHeight = operationControls.value.offsetHeight || (anglePanelOpen.value ? 190 : 38);

    if (anglePanelOpen.value) {
        operationControls.value.style.display = '';
        operationControls.value.style.left = `${Math.max(clientWidth - controlWidth - 16, 12)}px`;
        operationControls.value.style.top = '54px';
        return;
    }

    const cornerX = (position.x + 1) / 2 * clientWidth;
    const cornerY = (-position.y + 1) / 2 * clientHeight;
    const x = clamp(cornerX - controlWidth - 6, 12, Math.max(clientWidth - controlWidth - 12, 12));
    const y = clamp(cornerY + 6, 54, Math.max(clientHeight - controlHeight - 36, 54));
    operationControls.value.style.display = '';
    operationControls.value.style.left = `${x}px`;
    operationControls.value.style.top = `${y}px`;
};

const adjustOperation = (field, delta) => {
    if (!selectedOperation.value || !(field in selectedOperation.value)) return;

    const limit = field === 'path_angle' ? 180 : field === 'miter_angle' ? 60 : 45;
    emit('update-operation', selectedOperation.value.id, {
        [field]: clamp(Number(selectedOperation.value[field]) + delta, -limit, limit),
    });
};

const setOperationValue = (field, event) => {
    if (!selectedOperation.value || !(field in selectedOperation.value)) return;

    const value = Number(event.target.value);

    if (!Number.isFinite(value)) return;

    if (field === 'cut_depth') {
        emit('update-operation', selectedOperation.value.id, {
            cut_depth: clamp(value, 0.1, Number(props.activePart.dimensions.thickness)),
        });
        return;
    }

    const limit = field === 'miter_angle' ? 60 : 45;
    emit('update-operation', selectedOperation.value.id, { [field]: clamp(value, -limit, limit) });
};

const setOperationDirection = (event) => {
    if (!selectedOperation.value) return;

    emit('update-operation', selectedOperation.value.id, { cut_direction: event.target.value });
};

const handleAssemblyTransformKey = (event) => {
    const key = assemblyShortcutKey(event);
    const mode = { g: 'move', r: 'rotate', s: 'scale' }[key];

    if (!transformState.value) {
        if (mode && !event.repeat) {
            beginTransform(mode);
            event.preventDefault();
        }

        return;
    }

    if (event.key === 'Escape') {
        finishTransform(false);
        event.preventDefault();
        return;
    }

    if (event.key === 'Enter') {
        finishTransform(true);
        event.preventDefault();
        return;
    }

    if (mode) {
        if (!event.repeat) beginTransform(mode);
        event.preventDefault();
        return;
    }

    if (['x', 'y', 'z'].includes(key)) {
        if (!event.repeat) selectTransformAxis(key);
        event.preventDefault();
        return;
    }

    const scaling = transformState.value.mode === 'scale';
    if (!transformState.value.axis && !scaling) return;

    if (event.key === 'Backspace') {
        transformState.value.pointerValue = null;
        transformState.value.numericInput = transformState.value.numericInput.slice(0, -1);
        const value = Number(transformState.value.numericInput || (scaling ? 1 : 0));
        applyTransformPreview(Number.isFinite(value) ? value : (scaling ? 1 : 0));
        event.preventDefault();
        return;
    }

    const character = event.key === ',' ? '.' : event.key;

    if (/^[0-9.]$/.test(character) || (character === '-' && transformState.value.numericInput === '')) {
        const nextInput = `${transformState.value.numericInput}${character}`;

        if (nextInput === '-' || /^-?(?:\d+\.?\d*|\.\d*)$/.test(nextInput)) {
            transformState.value.pointerValue = null;
            transformState.value.numericInput = nextInput;
            const value = Number(nextInput);

            if (Number.isFinite(value)) {
                applyTransformPreview(value);
            }
        }

        event.preventDefault();
    }
};

const refreshMoveSnap = (shiftHeld) => {
    const state = transformState.value;
    if (state?.mode === 'move' && state.axis && state.pointerValue !== null) {
        applyTransformPreview(state.pointerValue, { snap: shiftHeld });
    }
};

const keyUp = (event) => {
    if (event.key === 'Shift' && !event.shiftKey) refreshMoveSnap(false);
};

const keyDown = (event) => {
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(event.target?.tagName) || event.target?.isContentEditable) return;
    if (event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return;

    if (props.mode === 'assembly') {
        if (event.key === 'Shift' && !event.repeat) refreshMoveSnap(true);
        handleAssemblyTransformKey(event);
        return;
    }

    if (props.mode !== 'part' || !selectedOperation.value) return;

    if (event.shiftKey && event.key.toLowerCase() === 'a' && angleCapableOperation.value) {
        anglePanelOpen.value = !anglePanelOpen.value;
        event.preventDefault();
        return;
    }

    if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;

    const delta = event.key === 'ArrowRight' ? 1 : -1;

    if (event.shiftKey && selectedOperation.value.type === 'cross_cut') {
        adjustOperation('miter_angle', delta);
        event.preventDefault();
    } else if (event.shiftKey && selectedOperation.value.type === 'groove') {
        adjustOperation('path_angle', delta);
        event.preventDefault();
    } else if (event.altKey && ['cross_cut', 'rip_cut'].includes(selectedOperation.value.type)) {
        adjustOperation('bevel_angle', delta);
        event.preventDefault();
    }
};

onMounted(() => {
    const container = viewport.value;
    scene = new THREE.Scene();
    scene.background = new THREE.Color('#eef1ed');
    objectsGroup = new THREE.Group();
    partGroup = new THREE.Group();
    measurementGroup = new THREE.Group();
    connectionsGroup = new THREE.Group();
    scene.add(objectsGroup);
    scene.add(partGroup);
    scene.add(measurementGroup);
    scene.add(connectionsGroup);

    camera = new THREE.PerspectiveCamera(42, 1, 0.1, 1000);
    camera.position.set(9, 7, 11);

    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.shadowMap.enabled = true;
    renderer.localClippingEnabled = true;
    renderer.domElement.addEventListener('pointerdown', pointerDown);
    renderer.domElement.addEventListener('dblclick', openPickedGroup);
    renderer.domElement.addEventListener('pointermove', pointerMove);
    renderer.domElement.addEventListener('pointerup', pointerUp);
    renderer.domElement.addEventListener('pointercancel', pointerUp);
    renderer.domElement.addEventListener('lostpointercapture', pointerUp);
    renderer.domElement.addEventListener('contextmenu', preventContextMenu);
    window.addEventListener('keydown', keyDown);
    window.addEventListener('keyup', keyUp);
    window.addEventListener('blur', finishToolDrag);
    window.addEventListener('pointermove', scalePointerMove, true);
    container.prepend(renderer.domElement);

    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.mouseButtons.LEFT = null;
    controls.mouseButtons.MIDDLE = THREE.MOUSE.ROTATE;
    controls.mouseButtons.RIGHT = THREE.MOUSE.PAN;
    controls.target.set(0, 1, 0);

    scene.add(new THREE.HemisphereLight('#ffffff', '#718078', 2.2));
    const keyLight = new THREE.DirectionalLight('#fff8df', 3);
    keyLight.position.set(4, 8, 5);
    keyLight.castShadow = true;
    scene.add(keyLight);
    scene.add(new THREE.GridHelper(40, 80, '#91a198', '#d2d9d5'));

    const render = () => {
        controls.update();
        renderer.render(scene, camera);
        positionOperationControls();
        animationFrame = requestAnimationFrame(render);
    };

    const resize = () => {
        const { clientWidth, clientHeight } = container;
        camera.aspect = clientWidth / Math.max(clientHeight, 1);
        camera.updateProjectionMatrix();
        renderer.setSize(clientWidth, clientHeight, false);
    };

    resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container);
    resize();
    syncPartView();
    rebuildAssembly();
    rebuildPart();
    render();
});

watch(() => props.mode === 'part' && props.activePart
    ? JSON.stringify([props.activePart.id, geometryKey(props.activePart, props.selectedOperationId)])
    : null, schedulePartRebuild);
watch(() => JSON.stringify(props.parts.filter(part => part.instances?.length).map(part => (
    [part.id, geometryKey(part), part.instances.map(instance => instance.id)]
))), rebuildAssembly);
watch(() => [props.mode, props.activePart?.id, props.activePart?.dimensions.thickness, props.partContextInstance], syncPartView, { deep: true });
watch(() => [props.parts, props.visibleInstanceIds, props.partContextVisibleInstanceIds], syncAssemblyMeshes, { deep: true });
watch(() => [props.mode, props.partContextInstance?.id], () => {
    finishToolDrag();
    finishTransform(false);
    clearMeasurement();
    clearConnectionHelpers();
    if (props.mode === 'assembly') updateConnectionHelpers();
});
watch(() => props.selectedInstanceId, (selectedInstanceId, previousInstanceId) => {
    if (transformState.value && selectedInstanceId !== previousInstanceId) {
        finishTransform(false);
    }

    clearTransformGuide();
    objectMeshes.forEach((mesh) => updateMeshAppearance(mesh, mesh.userData.instanceId === selectedInstanceId));
});
watch(() => [props.ghostedInstanceIds, props.selectedGroupInstanceIds], () => {
    objectMeshes.forEach((mesh) => updateMeshAppearance(mesh, mesh.userData.instanceId === props.selectedInstanceId));
}, { deep: true });
watch(() => props.groupMoveInstanceIds.join(','), () => {
    if (transformState.value?.group) finishTransform(false);
});
watch(() => props.explodeDistance, applyExplodedPositions);
watch(() => props.connections, updateConnectionHelpers, { deep: true });
watch(() => props.selectedConnectionId, updateConnectionHelpers);
watch(() => [props.connectionMachiningPreview, props.fastenerMachiningPreview], updateConnectionHelpers);
watch(() => [props.mode, props.fasteners, props.fastenerPreview, props.selectedFastenerId, props.fastenerMachiningPreview, props.focusedFasteners], syncFastenerMeshes, { deep: true });
watch(() => props.focusedInstanceIds, () => {
    objectMeshes.forEach((mesh) => updateMeshAppearance(mesh, mesh.userData.instanceId === props.selectedInstanceId));
}, { deep: true });
watch(() => props.focusRequestId, () => window.requestAnimationFrame(focusInstances));
watch(() => props.measurementTool, clearMeasurement);
watch(() => props.measurementResetId, clearMeasurement);
watch(() => [props.sectionAxis, props.sectionOffset, props.sectionInverted], updateSection);
watch(() => props.selectedOperationId, () => {
    anglePanelOpen.value = false;
    hoveredOperationId.value = null;
    operationToolHovered.value = false;
    cancelOperationToolHide();
});

onBeforeUnmount(() => {
    cancelAnimationFrame(animationFrame);
    window.clearTimeout(rebuildTimer);
    rebuildVersion++;
    assemblyRebuildVersion++;
    geometryWorker.dispose();
    assemblyGeometryWorker.dispose();
    resizeObserver?.disconnect();
    finishTransform(false);
    renderer?.domElement.removeEventListener('pointerdown', pointerDown);
    renderer?.domElement.removeEventListener('dblclick', openPickedGroup);
    renderer?.domElement.removeEventListener('pointermove', pointerMove);
    renderer?.domElement.removeEventListener('pointerup', pointerUp);
    renderer?.domElement.removeEventListener('pointercancel', pointerUp);
    renderer?.domElement.removeEventListener('lostpointercapture', pointerUp);
    renderer?.domElement.removeEventListener('contextmenu', preventContextMenu);
    window.removeEventListener('keydown', keyDown);
    window.removeEventListener('keyup', keyUp);
    window.removeEventListener('blur', finishToolDrag);
    window.removeEventListener('pointermove', scalePointerMove, true);
    cancelOperationToolHide();
    clearMeasurement();
    clearConnectionHelpers();
    if (sectionHelper) {
        scene?.remove(sectionHelper);
        sectionHelper.geometry.dispose();
        sectionHelper.material.dispose();
        sectionHelper = null;
    }
    clearObjects();
    clearPartObjects();
    renderer?.dispose();
});
</script>
