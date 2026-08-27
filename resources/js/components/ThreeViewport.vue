<template>
    <div ref="viewport" class="three-viewport">
        <div v-if="emptyMessage" class="viewport-empty">
            <span class="viewport-empty-mark">◇</span>
            <strong>{{ emptyMessage.title }}</strong>
            <small>{{ emptyMessage.text }}</small>
        </div>
        <div v-if="angleCapableOperation && operationToolVisible" ref="operationControls" class="scene-operation-tool">
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
                    <div><span>{{ selectedOperationLabel }}</span><small>Настройка плоскости реза</small></div>
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
                <p>Shift+A — закрыть · Shift + ←/→ — план · Alt + ←/→ — наклон</p>
            </div>
        </div>
        <div v-if="selectedOperation" class="offcut-legend"><i></i> Полупрозрачная часть будет удалена</div>
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

const emit = defineEmits(['select-instance', 'select-operation', 'update-operation']);
const viewport = ref(null);
const operationControls = ref(null);
const anglePanelOpen = ref(false);
const hoveredOperationId = ref(null);
const millimeterScale = 0.01;
const palette = ['#b98552', '#c99b65', '#a87345', '#d0a878', '#98704c'];
const objectMeshes = [];
const operationHelpers = [];
let animationFrame;
let camera;
let controls;
let renderer;
let resizeObserver;
let scene;
let objectsGroup;
let dragState = null;

const selectedOperation = computed(() => props.activePart?.operations?.find((operation) => operation.id === props.selectedOperationId) ?? null);
const angleCapableOperation = computed(() => ['cross_cut', 'rip_cut'].includes(selectedOperation.value?.type));
const operationToolVisible = computed(() => anglePanelOpen.value || hoveredOperationId.value === props.selectedOperationId);
const selectedOperationLabel = computed(() => ({
    cross_cut: 'Поперечный рез',
    rip_cut: 'Продольный рез',
    groove: 'Паз пилой',
}[selectedOperation.value?.type] ?? 'Операция'));

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

const crossCutCutter = (part, operation) => {
    const { length } = partSize(part);
    const miter = THREE.MathUtils.degToRad(Number(operation.miter_angle));
    const bevel = THREE.MathUtils.degToRad(Number(operation.bevel_angle));
    const normal = new THREE.Vector3(
        Math.cos(bevel) * Math.cos(miter),
        Math.sin(bevel),
        Math.cos(bevel) * Math.sin(miter),
    ).normalize();

    if (operation.keep_side === 'end') {
        normal.negate();
    }

    const point = new THREE.Vector3(-length / 2 + Number(operation.position) * millimeterScale, 0, 0);

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
            ? { cutter: crossCutCutter(part, operation) }
            : { ...ripCutPlane(part, operation) };
        preview = new THREE.Mesh(new THREE.BoxGeometry(0.012, extent, extent));

        if (plane.cutter) {
            preview.quaternion.copy(plane.cutter.quaternion);
            const normal = new THREE.Vector3(1, 0, 0).applyQuaternion(preview.quaternion);
            preview.position.copy(plane.cutter.position).addScaledVector(normal, -Math.max(length, width, thickness) * 3 - 10);
            plane.cutter.geometry.dispose();
        } else {
            preview.quaternion.setFromUnitVectors(new THREE.Vector3(1, 0, 0), plane.normal);
            preview.position.copy(plane.point);
        }
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
    (part.operations ?? []).forEach((operation) => createOperationPreview(part, operation));
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
        mesh.position.set(
            Number(instance.position.x) * millimeterScale,
            Number(instance.position.y) * millimeterScale + Number(part.dimensions.thickness) * millimeterScale / 2,
            Number(instance.position.z) * millimeterScale,
        );
        mesh.rotation.set(
            THREE.MathUtils.degToRad(Number(instance.rotation.x)),
            THREE.MathUtils.degToRad(Number(instance.rotation.y)),
            THREE.MathUtils.degToRad(Number(instance.rotation.z)),
        );
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

const pointerDown = (event) => {
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

const pointerMove = (event) => {
    if (!dragState) {
        if (props.mode === 'part') {
            const hoveredHelper = rayFromEvent(event).intersectObjects(operationHelpers, false)[0]?.object;
            hoveredOperationId.value = hoveredHelper?.userData.operationId ?? null;
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
    const x = clamp((position.x + 1) / 2 * clientWidth + 8, 12, Math.max(clientWidth - controlWidth - 12, 12));
    const y = clamp((-position.y + 1) / 2 * clientHeight - controlHeight - 8, 54, Math.max(clientHeight - controlHeight - 36, 54));
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

    const limit = field === 'miter_angle' ? 60 : 45;
    emit('update-operation', selectedOperation.value.id, { [field]: clamp(value, -limit, limit) });
};

const keyDown = (event) => {
    if (props.mode !== 'part' || !selectedOperation.value) return;
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;

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
watch(() => props.selectedOperationId, () => { anglePanelOpen.value = false; });

onBeforeUnmount(() => {
    cancelAnimationFrame(animationFrame);
    resizeObserver?.disconnect();
    renderer?.domElement.removeEventListener('pointerdown', pointerDown);
    renderer?.domElement.removeEventListener('pointermove', pointerMove);
    renderer?.domElement.removeEventListener('pointerup', pointerUp);
    renderer?.domElement.removeEventListener('pointercancel', pointerUp);
    window.removeEventListener('keydown', keyDown);
    clearObjects();
    renderer?.dispose();
});
</script>
