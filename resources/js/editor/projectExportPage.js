import * as THREE from 'three';
import { assemblyInstanceState } from './assemblyTree.js';
import { createPartGeometry } from './geometry/csg.js';
import { setPartEditingFrame } from './geometry/coordinates.js';
import { createFastenerMesh } from './fasteners.js';
import { exportPresentationModel } from './presentationExport.js';
import { createWoodPresentation } from './woodPresentation.js';

const views = {
    isometric: [1, 0.8, 1.25],
    front: [0, 0.12, -1],
    rear: [0, 0.12, 1],
    side: [1, 0.12, 0],
    top: [0, 1, 0.0001],
};

const createModel = ({ parts, groups, fasteners }) => {
    const visible = new Set(assemblyInstanceState(groups, parts).visibleInstanceIds);
    const surfaces = new Map();
    const hardware = [];
    for (const part of parts) {
        const instances = part.instances.filter(instance => visible.has(instance.id));
        if (!instances.length) continue;
        const geometry = createPartGeometry({
            ...part, operations: (part.operations ?? []).filter(operation => operation.status !== 'draft' && operation.enabled !== false),
        });
        for (const instance of instances) {
            const mesh = new THREE.Mesh(geometry);
            mesh.userData = { part, instanceId: instance.id, partName: part.name };
            setPartEditingFrame(mesh, part, instance);
            surfaces.set(instance.id, mesh);
        }
    }
    for (const fastener of fasteners) {
        const surface = surfaces.get(fastener.source_instance_id);
        if (!surface) continue;
        const group = createFastenerMesh(surface.userData.part, fastener);
        surface.add(group);
        hardware.push(group);
    }
    return { surfaces: [...surfaces.values()], hardware };
};

window.exportWoodworkingProject = async ({ model, format, width, height, view }) => {
    const { surfaces, hardware } = createModel(model);
    if (!surfaces.length) throw new Error('The project has no visible instances.');
    const renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.setSize(width, height);
    const stage = await createWoodPresentation(renderer);
    try {
        stage.configure({ wood: 'pine', finish: 'raw', light: 'studio', ...model.render_settings });
        stage.setModel(surfaces, hardware);
        if (format !== 'png') {
            const blob = await exportPresentationModel(stage.model, format, stage.woodMaps);
            return await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result.split(',')[1]);
                reader.onerror = reject;
                reader.readAsDataURL(blob);
            });
        }

        const camera = new THREE.PerspectiveCamera(35, width / height);
        const sphere = stage.bounds.getBoundingSphere(new THREE.Sphere());
        const halfFov = THREE.MathUtils.degToRad(camera.fov / 2);
        const fittingAngle = Math.min(halfFov, Math.atan(Math.tan(halfFov) * camera.aspect));
        const distance = Math.max(sphere.radius, 0.05) * 1.14 / Math.sin(fittingAngle);
        camera.position.copy(sphere.center).addScaledVector(new THREE.Vector3(...views[view]).normalize(), distance);
        camera.near = Math.max(0.001, distance / 1000);
        camera.far = Math.max(1000, distance * 12);
        camera.updateProjectionMatrix();
        camera.lookAt(sphere.center);
        renderer.toneMappingExposure = stage.exposure;
        renderer.render(stage.scene, camera);
        return renderer.domElement.toDataURL('image/png').split(',')[1];
    } finally {
        stage.dispose();
        renderer.dispose();
    }
};
