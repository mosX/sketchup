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
                        <span><input :value="selectedOperation.miter_angle" type="number" min="-60" max="60" step="1" aria-label="Точный угол в плане" @change="setOperationValue('miter_angle', $event)"><b>°</b></span>
                    </div>
                </div>
                <div class="scene-angle-editor">
                    <label for="scene-bevel-angle">Наклон диска</label>
                    <div>
                        <input id="scene-bevel-angle" :value="selectedOperation.bevel_angle" type="range" min="-45" max="45" step="1" @input="setOperationValue('bevel_angle', $event)">
                        <span><input :value="selectedOperation.bevel_angle" type="number" min="-45" max="45" step="1" aria-label="Точный наклон диска" @change="setOperationValue('bevel_angle', $event)"><b>°</b></span>
                    </div>
                </div>
                <div class="scene-angle-editor">
                    <label for="scene-cut-depth">Глубина пропила</label>
                    <div>
                        <input id="scene-cut-depth" :value="selectedOperation.cut_depth" type="range" min="0.1" :max="props.activePart.dimensions.thickness" step="0.1" @input="setOperationValue('cut_depth', $event)">
                        <span><input :value="selectedOperation.cut_depth" type="number" min="0.1" :max="props.activePart.dimensions.thickness" step="0.1" aria-label="Точная глубина пропила" @change="setOperationValue('cut_depth', $event)"><b>мм</b></span>
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
        <div v-if="transformState" class="scene-transform-hud">
            <div>
                <strong>{{ transformModeLabel }}</strong>
                <span v-if="transformState.axis" class="transform-axis-badge" :class="`transform-axis-${transformState.axis}`">{{ transformState.axis.toUpperCase() }}</span>
            </div>
            <p v-if="!transformState.axis">Выберите ось: <kbd>X</kbd> <kbd>Y</kbd> <kbd>Z</kbd></p>
            <p v-else>Двигайте мышь · <b>{{ transformValueLabel }}</b></p>
            <small>ЛКМ / Enter — применить · Esc / ПКМ — отменить · Shift — точно</small>
        </div>
        <div class="viewport-hint">СКМ / Alt+ЛКМ — вращение · Колесо — масштаб · ПКМ — перемещение</div>
        <div class="axis-widget" aria-hidden="true"><span class="axis-x">X</span><span class="axis-y">Y</span><span class="axis-z">Z</span></div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { Brush, Evaluator, INTERSECTION, SUBTRACTION } from 'three-bvh-csg';

const props = defineProps({
    mode: { type: String, default: 'assembly' },
    parts: { type: Array, default: () => [] },
    activePart: { type: Object, default: null },
    selectedInstanceId: { type: Number, default: null },
    selectedOperationId: { type: String, default: null },
});

const emit = defineEmits(['select-instance', 'select-operation', 'update-operation', 'commit-instance-transform']);
const viewport = ref(null);
const operationControls = ref(null);
const anglePanelOpen = ref(false);
const hoveredOperationId = ref(null);
const operationToolHovered = ref(false);
const transformState = ref(null);
const millimeterScale = 0.01;
const editorAxisDirections = {
    x: new THREE.Vector3(1, 0, 0),
    y: new THREE.Vector3(0, 0, 1),
    z: new THREE.Vector3(0, 1, 0),
};
const editorToSceneBasis = new THREE.Matrix4().set(
    1, 0, 0, 0,
    0, 0, 1, 0,
    0, 1, 0, 0,
    0, 0, 0, 1,
);
const editorRotationEuler = new THREE.Euler(0, 0, 0, 'ZYX');
const editorRotationMatrix = new THREE.Matrix4();
const sceneRotationMatrix = new THREE.Matrix4();
const palette = ['#b98552', '#c99b65', '#a87345', '#d0a878', '#98704c'];
const objectMeshes = [];
const operationHelpers = [];
const lastPointerPosition = new THREE.Vector2();
let animationFrame;
let camera;
let controls;
let renderer;
let resizeObserver;
let scene;
let objectsGroup;
let dragState = null;
let hoverClearTimer = null;
let transformGuide = null;
let hasPointerPosition = false;

const selectedOperation = computed(() => props.activePart?.operations?.find((operation) => operation.id === props.selectedOperationId) ?? null);
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
    mesh.position.set(
        Number(position.x) * millimeterScale,
        Number(position.z) * millimeterScale + Number(part.dimensions.thickness) * millimeterScale / 2,
        Number(position.y) * millimeterScale,
    );

    editorRotationEuler.set(
        THREE.MathUtils.degToRad(Number(rotation.x)),
        THREE.MathUtils.degToRad(Number(rotation.y)),
        THREE.MathUtils.degToRad(Number(rotation.z)),
    );
    editorRotationMatrix.makeRotationFromEuler(editorRotationEuler);
    sceneRotationMatrix
        .copy(editorToSceneBasis)
        .multiply(editorRotationMatrix)
        .multiply(editorToSceneBasis);
    mesh.quaternion.setFromRotationMatrix(sceneRotationMatrix);
};
const transformModeLabel = computed(() => transformState.value?.mode === 'move' ? 'Перемещение' : 'Вращение');
const transformValueLabel = computed(() => {
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

const partSize = (part) => ({
    length: Number(part.dimensions.length) * millimeterScale,
    width: Number(part.dimensions.width) * millimeterScale,
    thickness: Number(part.dimensions.thickness) * millimeterScale,
});

const halfSpaceCutter = (part, point, normal, kerf = 0) => {
    const { length, width, thickness } = partSize(part);
    const extent = Math.max(length, width, thickness) * 6 + 20;
    const removalNormal = normal.clone().normalize();
    const boundary = point.clone().addScaledVector(removalNormal, -Number(kerf) * millimeterScale / 2);
    const brush = new Brush(new THREE.BoxGeometry(extent, extent, extent));
    brush.quaternion.setFromUnitVectors(new THREE.Vector3(1, 0, 0), removalNormal);
    brush.position.copy(boundary).addScaledVector(removalNormal, extent / 2);

    return brush;
};

const crossCutPlane = (part, operation) => {
    const { length } = partSize(part);
    const miter = THREE.MathUtils.degToRad(Number(operation.miter_angle));
    const bevel = THREE.MathUtils.degToRad(Number(operation.bevel_angle));
    const normal = new THREE.Vector3(
        Math.cos(bevel) * Math.cos(miter),
        Math.sin(bevel),
        Math.cos(bevel) * Math.sin(miter),
    ).normalize();
    const point = new THREE.Vector3(-length / 2 + Number(operation.position) * millimeterScale, 0, 0);

    return { point, normal };
};

const isPartialDepthCut = (part, operation) => Number(operation.cut_depth ?? part.dimensions.thickness) < Number(part.dimensions.thickness) - 0.01;

const depthLimitedKerfCutter = (part, operation, point, normal) => {
    const { length, width, thickness } = partSize(part);
    const extent = Math.max(length, width, thickness) * 6 + 20;
    const kerf = Math.max(Number(operation.kerf) * millimeterScale, 0.001);
    const depth = Math.min(Number(operation.cut_depth) * millimeterScale, thickness);
    const epsilon = 0.004;
    const blade = new Brush(new THREE.BoxGeometry(kerf, extent, extent));
    blade.quaternion.setFromUnitVectors(new THREE.Vector3(1, 0, 0), normal.clone().normalize());
    blade.position.copy(point);
    blade.updateMatrixWorld();

    const depthBand = new Brush(new THREE.BoxGeometry(extent, depth + epsilon, extent));
    depthBand.position.y = operation.cut_direction === 'bottom_up'
        ? -thickness / 2 + depth / 2 - epsilon / 2
        : thickness / 2 - depth / 2 + epsilon / 2;
    depthBand.updateMatrixWorld();

    const evaluator = new Evaluator();
    evaluator.useGroups = false;
    const cutter = evaluator.evaluate(blade, depthBand, INTERSECTION);
    blade.geometry.dispose();
    depthBand.geometry.dispose();

    return cutter;
};

const crossCutCutter = (part, operation) => {
    const { point, normal } = crossCutPlane(part, operation);

    if (isPartialDepthCut(part, operation)) {
        return depthLimitedKerfCutter(part, operation, point, normal);
    }

    if (operation.keep_side === 'end') {
        normal.negate();
    }

    return halfSpaceCutter(part, point, normal, operation.kerf);
};

const ripCutPlane = (part, operation) => {
    const { length, width } = partSize(part);
    const side = operation.reference_side === 'right' ? 1 : -1;
    const startZ = side * (width / 2 - Number(operation.start_offset) * millimeterScale);
    const endZ = side * (width / 2 - Number(operation.end_offset) * millimeterScale);
    const direction = new THREE.Vector3(length, 0, endZ - startZ);
    const inward = new THREE.Vector3(-direction.z, 0, direction.x).normalize();

    if (operation.reference_side === 'right') {
        inward.negate();
    }

    const bevel = THREE.MathUtils.degToRad(Number(operation.bevel_angle));
    const normal = inward.multiplyScalar(Math.cos(bevel));
    normal.y = Math.sin(bevel);

    return { point: new THREE.Vector3(-length / 2, 0, startZ), normal: normal.normalize() };
};

const ripCutCutter = (part, operation) => {
    const { point, normal } = ripCutPlane(part, operation);

    if (isPartialDepthCut(part, operation)) {
        return depthLimitedKerfCutter(part, operation, point, normal);
    }

    if (operation.keep_side === 'reference') {
        normal.negate();
    }

    return halfSpaceCutter(part, point, normal, operation.kerf);
};

const grooveCutter = (part, operation) => {
    const { length, width, thickness } = partSize(part);
    const grooveWidth = Number(operation.width) * millimeterScale;
    const depth = Number(operation.depth) * millimeterScale;
    const start = Number(operation.start) * millimeterScale;
    const end = Number(operation.end) * millimeterScale;
    const offset = Number(operation.offset) * millimeterScale;
    const epsilon = 0.004;
    const alongLength = operation.direction === 'length';
    const geometry = new THREE.BoxGeometry(
        alongLength ? end - start : grooveWidth,
        depth + epsilon,
        alongLength ? grooveWidth : end - start,
    );
    const brush = new Brush(geometry);
    brush.position.set(
        alongLength ? -length / 2 + (start + end) / 2 : -length / 2 + offset,
        operation.face === 'bottom' ? -thickness / 2 + depth / 2 - epsilon / 2 : thickness / 2 - depth / 2 + epsilon / 2,
        alongLength ? -width / 2 + offset : -width / 2 + (start + end) / 2,
    );

    return brush;
};

const operationCutter = (part, operation) => {
    if (operation.type === 'cross_cut') return crossCutCutter(part, operation);
    if (operation.type === 'rip_cut') return ripCutCutter(part, operation);
    if (operation.type === 'groove') return grooveCutter(part, operation);

    return null;
};

const subtractOperations = (part, operations) => {
    const { length, width, thickness } = partSize(part);
    const evaluator = new Evaluator();
    evaluator.useGroups = false;
    let result = new Brush(new THREE.BoxGeometry(length, thickness, width));

    operations.filter((operation) => operation.enabled !== false).forEach((operation) => {
        const cutter = operationCutter(part, operation);

        if (!cutter) return;

        result.updateMatrixWorld();
        cutter.updateMatrixWorld();
        const previous = result;
        result = evaluator.evaluate(previous, cutter, SUBTRACTION);
        previous.geometry.dispose();
        cutter.geometry.dispose();
    });

    return result;
};

const createPartGeometry = (part) => {
    const { length, width, thickness } = partSize(part);
    let result;

    try {
        result = subtractOperations(part, part.operations ?? []);
    } catch (error) {
        result?.geometry.dispose();
        console.error('Не удалось построить операцию заготовки.', error);
        result = new Brush(new THREE.BoxGeometry(length, thickness, width));
    }

    result.geometry.computeVertexNormals();
    result.geometry.computeBoundingSphere();

    return result.geometry;
};

const createOffcutGeometry = (part, operation) => {
    if (!operation || operation.enabled === false) return null;

    const operationIndex = (part.operations ?? []).findIndex((item) => item.id === operation.id);

    if (operationIndex < 0) return null;

    let stock;
    let cutter;

    try {
        stock = subtractOperations(part, part.operations.slice(0, operationIndex));
        cutter = operationCutter(part, operation);

        if (!cutter) {
            stock.geometry.dispose();
            return null;
        }

        stock.updateMatrixWorld();
        cutter.updateMatrixWorld();
        const evaluator = new Evaluator();
        evaluator.useGroups = false;
        const offcut = evaluator.evaluate(stock, cutter, INTERSECTION);
        stock.geometry.dispose();
        cutter.geometry.dispose();
        offcut.geometry.computeVertexNormals();
        offcut.geometry.computeBoundingSphere();

        return offcut.geometry;
    } catch (error) {
        stock?.geometry.dispose();
        cutter?.geometry.dispose();
        console.error('Не удалось показать удаляемую часть заготовки.', error);

        return null;
    }
};

const createOffcutPreview = (part) => {
    const operation = part.operations?.find((item) => item.id === props.selectedOperationId);
    const geometry = createOffcutGeometry(part, operation);

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
    offcut.position.y = Number(part.dimensions.thickness) * millimeterScale / 2;
    offcut.renderOrder = 2;

    const outlineGeometry = new THREE.EdgesGeometry(geometry);
    const outline = new THREE.LineSegments(
        outlineGeometry,
        new THREE.LineDashedMaterial({ color: '#aa3f28', dashSize: 0.12, gapSize: 0.07, transparent: true, opacity: 0.75 }),
    );
    outline.computeLineDistances();
    offcut.add(outline);
    objectsGroup.add(offcut);
};

const createOperationPreview = (part, operation) => {
    if (operation.enabled === false) return;

    let preview;

    if (operation.type === 'groove') {
        preview = grooveCutter(part, operation);
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
    preview.position.y += Number(part.dimensions.thickness) * millimeterScale / 2;
    preview.userData.operationId = operation.id;
    preview.userData.isOperationHelper = true;
    operationHelpers.push(preview);
    objectsGroup.add(preview);
};

const createOperationPreviews = (part) => {
    const selectedOperation = part.operations?.find((operation) => operation.id === props.selectedOperationId);

    if (selectedOperation) {
        createOperationPreview(part, selectedOperation);
    }
};

const clearObjects = () => {
    objectMeshes.splice(0);
    operationHelpers.splice(0);
    objectsGroup.children.slice().forEach((object) => {
        object.traverse((child) => {
            child.geometry?.dispose();
            child.material?.dispose();
        });
        objectsGroup.remove(object);
    });
};

const createMesh = (part, instance = null) => {
    const geometry = createPartGeometry(part);
    const isSelected = instance?.id === props.selectedInstanceId;
    const material = new THREE.MeshStandardMaterial({
        color: palette[part.id % palette.length],
        emissive: isSelected ? '#315f4a' : '#000000',
        emissiveIntensity: isSelected ? 0.24 : 0,
        roughness: 0.72,
    });
    const mesh = new THREE.Mesh(geometry, material);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    mesh.userData.instanceId = instance?.id ?? null;

    const edges = new THREE.LineSegments(
        new THREE.EdgesGeometry(geometry),
        new THREE.LineBasicMaterial({ color: isSelected ? '#173d30' : '#6f5235' }),
    );
    mesh.add(edges);

    if (instance) {
        applyInstanceTransform(mesh, part, instance.position, instance.rotation);
        mesh.scale.x = instance.mirrored ? -1 : 1;
    } else {
        mesh.position.y = Number(part.dimensions.thickness) * millimeterScale / 2;
    }

    objectMeshes.push(mesh);
    objectsGroup.add(mesh);
};

const rebuildObjects = () => {
    if (!objectsGroup) {
        return;
    }

    clearObjects();

    if (props.mode === 'part' && props.activePart) {
        createMesh(props.activePart);
        createOffcutPreview(props.activePart);
        createOperationPreviews(props.activePart);
        controls.target.set(0, Number(props.activePart.dimensions.thickness) * millimeterScale / 2, 0);
        return;
    }

    props.parts.forEach((part) => {
        part.instances?.forEach((instance) => createMesh(part, instance));
    });
};

const rayFromEvent = (event) => {
    const bounds = renderer.domElement.getBoundingClientRect();
    const pointer = new THREE.Vector2(
        ((event.clientX - bounds.left) / bounds.width) * 2 - 1,
        -((event.clientY - bounds.top) / bounds.height) * 2 + 1,
    );
    const raycaster = new THREE.Raycaster();
    raycaster.setFromCamera(pointer, camera);

    return raycaster;
};

const clamp = (value, minimum, maximum) => Math.min(Math.max(value, minimum), maximum);
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
    const mesh = selectedInstanceMesh();

    if (!mesh) return;

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
    transformGuide.position.copy(mesh.position);
    transformGuide.renderOrder = 5;
    objectsGroup.add(transformGuide);
};

const applyTransformPreview = (value) => {
    const state = transformState.value;
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
    state.position = position;
    state.rotation = rotation;
    state.value = value;

    if (transformGuide) {
        transformGuide.position.copy(mesh.position);
    }
};

const beginTransform = (mode) => {
    const data = selectedInstanceData.value;
    const mesh = selectedInstanceMesh();

    if (!data || !mesh || props.mode !== 'assembly') return;

    if (transformState.value) {
        applyTransformPreview(0);
        clearTransformGuide();
    }

    if (!hasPointerPosition) {
        const bounds = renderer.domElement.getBoundingClientRect();
        lastPointerPosition.set(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2);
    }

    const initialWorldPosition = mesh.getWorldPosition(new THREE.Vector3());

    transformState.value = {
        mode,
        axis: null,
        instanceId: data.instance.id,
        initialPosition: { ...data.instance.position },
        initialRotation: { ...data.instance.rotation },
        position: { ...data.instance.position },
        rotation: { ...data.instance.rotation },
        initialWorldPosition: { x: initialWorldPosition.x, y: initialWorldPosition.y, z: initialWorldPosition.z },
        value: 0,
        startPointer: { x: lastPointerPosition.x, y: lastPointerPosition.y },
    };
    controls.enabled = false;
    renderer.domElement.style.cursor = 'crosshair';
};

const selectTransformAxis = (axis) => {
    if (!transformState.value) return;

    transformState.value.axis = axis;
    transformState.value.startPointer = { x: lastPointerPosition.x, y: lastPointerPosition.y };
    applyTransformPreview(0);
    showTransformGuide(axis);
};

const moveValueFromPointer = (event) => {
    const state = transformState.value;
    const mesh = selectedInstanceMesh();

    if (!state?.axis || !mesh) return 0;

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
    const precision = event.shiftKey ? 0.1 : 1;

    return Math.round(pointerDelta.dot(screenAxis) * worldPerPixel / millimeterScale * precision * 10) / 10;
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

    if (shouldCommit && state.axis) {
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

const pointerDown = (event) => {
    lastPointerPosition.set(event.clientX, event.clientY);
    hasPointerPosition = true;

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

    if (props.mode === 'part') {
        if (event.button !== 0) return;

        const helper = raycaster.intersectObjects(operationHelpers, false)[0]?.object;

        if (!helper) return;

        const operation = props.activePart?.operations?.find((item) => item.id === helper.userData.operationId);

        if (!operation) return;

        emit('select-operation', operation.id);
        const dragPlane = new THREE.Plane();
        const cameraDirection = camera.getWorldDirection(new THREE.Vector3());
        dragPlane.setFromNormalAndCoplanarPoint(cameraDirection, helper.position);
        const startPoint = raycaster.ray.intersectPlane(dragPlane, new THREE.Vector3());

        if (!startPoint) return;

        dragState = {
            operation: { ...operation },
            dragPlane,
            startPoint,
        };
        controls.enabled = false;
        renderer.domElement.setPointerCapture(event.pointerId);
        renderer.domElement.style.cursor = 'grabbing';
        event.preventDefault();

        return;
    }

    if (props.mode !== 'assembly' || event.button !== 0) return;

    const intersection = raycaster.intersectObjects(objectMeshes, false)[0];

    emit('select-instance', intersection?.object.userData.instanceId ?? null);
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

const pointerMove = (event) => {
    lastPointerPosition.set(event.clientX, event.clientY);
    hasPointerPosition = true;

    if (props.mode === 'assembly' && transformState.value) {
        if (transformState.value.axis) {
            const value = transformState.value.mode === 'move'
                ? moveValueFromPointer(event)
                : rotationValueFromPointer(event);
            applyTransformPreview(value);
        }

        return;
    }

    if (!dragState) {
        if (props.mode === 'part') {
            const hoveredHelper = rayFromEvent(event).intersectObjects(operationHelpers, false)[0]?.object;

            if (hoveredHelper) {
                cancelOperationToolHide();
                hoveredOperationId.value = hoveredHelper.userData.operationId;
            } else {
                scheduleOperationToolHide();
            }

            renderer.domElement.style.cursor = hoveredHelper ? 'grab' : '';
        }

        return;
    }

    if (!props.activePart) return;

    const point = rayFromEvent(event).ray.intersectPlane(dragState.dragPlane, new THREE.Vector3());

    if (!point) return;

    const movement = point.sub(dragState.startPoint);
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
        const alongLength = operation.direction === 'length';
        const movementValue = (alongLength ? movement.z : movement.x) / millimeterScale;
        const maximum = Number(alongLength ? dimensions.width : dimensions.length);
        const halfWidth = Number(operation.width) / 2;
        emit('update-operation', operation.id, {
            offset: Math.round(clamp(Number(operation.offset) + movementValue, halfWidth, maximum - halfWidth) * 10) / 10,
        });
    }
};

const pointerUp = (event) => {
    controls.mouseButtons.LEFT = null;

    if (!dragState) return;

    dragState = null;
    controls.enabled = true;

    if (renderer.domElement.hasPointerCapture?.(event.pointerId)) {
        renderer.domElement.releasePointerCapture(event.pointerId);
    }

    renderer.domElement.style.cursor = '';
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

    const limit = field === 'miter_angle' ? 60 : 45;
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
    const key = event.key.toLowerCase();

    if (!transformState.value) {
        if (key === 'g' || key === 'r') {
            beginTransform(key === 'g' ? 'move' : 'rotate');
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

    if (['g', 'r'].includes(key)) {
        beginTransform(key === 'g' ? 'move' : 'rotate');
        event.preventDefault();
        return;
    }

    if (['x', 'y', 'z'].includes(key)) {
        selectTransformAxis(key);
        event.preventDefault();
    }
};

const keyDown = (event) => {
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;

    if (props.mode === 'assembly') {
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
    scene.add(objectsGroup);

    camera = new THREE.PerspectiveCamera(42, 1, 0.1, 1000);
    camera.position.set(9, 7, 11);

    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.shadowMap.enabled = true;
    renderer.domElement.addEventListener('pointerdown', pointerDown);
    renderer.domElement.addEventListener('pointermove', pointerMove);
    renderer.domElement.addEventListener('pointerup', pointerUp);
    renderer.domElement.addEventListener('pointercancel', pointerUp);
    renderer.domElement.addEventListener('contextmenu', preventContextMenu);
    window.addEventListener('keydown', keyDown);
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
        positionOperationControls();
        renderer.render(scene, camera);
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
    rebuildObjects();
    render();
});

watch(() => [props.mode, props.parts, props.activePart, props.selectedInstanceId, props.selectedOperationId], rebuildObjects, { deep: true });
watch(() => props.selectedOperationId, () => {
    anglePanelOpen.value = false;
    hoveredOperationId.value = null;
    operationToolHovered.value = false;
    cancelOperationToolHide();
});

onBeforeUnmount(() => {
    cancelAnimationFrame(animationFrame);
    resizeObserver?.disconnect();
    finishTransform(false);
    renderer?.domElement.removeEventListener('pointerdown', pointerDown);
    renderer?.domElement.removeEventListener('pointermove', pointerMove);
    renderer?.domElement.removeEventListener('pointerup', pointerUp);
    renderer?.domElement.removeEventListener('pointercancel', pointerUp);
    renderer?.domElement.removeEventListener('contextmenu', preventContextMenu);
    window.removeEventListener('keydown', keyDown);
    cancelOperationToolHide();
    clearObjects();
    renderer?.dispose();
});
</script>
