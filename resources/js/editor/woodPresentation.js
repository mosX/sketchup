import * as THREE from 'three';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { createEndGrainTexture, createSpruceTexture, orientPineTexture, woodGrainLength, woodGrainWidth, woodSideOffset } from './presentationTextures.js';

const oakUrl = new URL('../../images/presentation-oak.jpg', import.meta.url).href;
const darkWoodUrl = new URL('../../images/presentation-dark-wood.jpg', import.meta.url).href;
const pineUrl = new URL('../../images/presentation-pine.jpg', import.meta.url).href;

const finishes = {
    raw: { roughness: 0.74, coat: 0, color: '#ffffff' },
    oil: { roughness: 0.47, coat: 0.12, color: '#f5e4d0' },
    lacquer: { roughness: 0.27, coat: 0.58, color: '#fff4e5' },
};
const setups = {
    studio: { background: '#dfddd8', floor: '#d2d0c9', key: '#fff4e6', intensity: 1.65, environment: 0.65, azimuth: 42, elevation: 52 },
    daylight: { background: '#e3eaf1', floor: '#d4dce4', key: '#ecf5ff', intensity: 2.05, environment: 0.78, azimuth: -58, elevation: 46 },
    contrast: { background: '#414951', floor: '#353e47', key: '#ffe5c7', intensity: 2.25, environment: 0.34, azimuth: 122, elevation: 40 },
};

export const presentationLightingDefaults = { exposure: -0.4, keyPower: 100, fillPower: 100, azimuth: 0, elevation: 0 };

export const resolvePresentationLighting = (settings) => {
    const preset = setups[settings.light] ?? setups.studio;
    const azimuth = THREE.MathUtils.degToRad(preset.azimuth + (settings.azimuth ?? presentationLightingDefaults.azimuth));
    const elevation = THREE.MathUtils.degToRad(THREE.MathUtils.clamp(preset.elevation + (settings.elevation ?? presentationLightingDefaults.elevation), 5, 85));
    const horizontal = Math.cos(elevation);
    return {
        ...preset,
        exposure: 2 ** (settings.exposure ?? presentationLightingDefaults.exposure),
        intensity: preset.intensity * (settings.keyPower ?? presentationLightingDefaults.keyPower) / 100,
        environment: preset.environment * (settings.fillPower ?? presentationLightingDefaults.fillPower) / 100,
        direction: new THREE.Vector3(horizontal * Math.sin(azimuth), Math.sin(elevation), horizontal * Math.cos(azimuth)),
    };
};

const woodMaterial = ({ grain, end }, grainAlongWidth) => {
    const material = new THREE.MeshPhysicalMaterial({ metalness: 0.02, clearcoatRoughness: 0.2 });
    updateFinish(material, {});
    material.onBeforeCompile = (shader) => {
        shader.uniforms.woodTexture = { value: grain };
        shader.uniforms.endGrainTexture = { value: end };
        shader.vertexShader = `varying vec3 woodPosition; varying vec3 woodNormal;\n${shader.vertexShader}`
            .replace('#include <begin_vertex>', '#include <begin_vertex>\nwoodPosition = position; woodNormal = normal;');
        shader.fragmentShader = `uniform sampler2D woodTexture; uniform sampler2D endGrainTexture; varying vec3 woodPosition; varying vec3 woodNormal;\n${shader.fragmentShader}`
            .replace('#include <map_fragment>', `
                vec3 woodP = ${grainAlongWidth ? 'woodPosition.zyx' : 'woodPosition.xyz'};
                vec3 woodN = ${grainAlongWidth ? 'woodNormal.zyx' : 'woodNormal.xyz'};
                vec3 weights = pow(abs(normalize(woodN)), vec3(10.0));
                weights /= max(dot(weights, vec3(1.0)), 0.0001);
                vec3 grainY = texture2D(woodTexture, vec2(woodP.x / ${woodGrainLength}, woodP.z / ${woodGrainWidth})).rgb;
                vec3 grainZ = texture2D(woodTexture, vec2(woodP.x / ${woodGrainLength}, woodP.y / ${woodGrainWidth} + ${woodSideOffset})).rgb;
                vec3 endColor = texture2D(endGrainTexture, woodP.yz / ${woodGrainWidth} + 0.5).rgb;
                diffuseColor.rgb *= endColor * weights.x + grainY * weights.y + grainZ * weights.z;
            `);
    };
    material.customProgramCacheKey = () => grainAlongWidth ? 'wood-preview-width' : 'wood-preview-length';
    return material;
};

const updateFinish = (material, settings) => {
    const finish = finishes[settings.finish] ?? finishes.raw;
    material.roughness = finish.roughness;
    material.clearcoat = finish.coat;
    material.color.set(finish.color);
};

export const createWoodPresentation = async (renderer, signal) => {
    const loader = new THREE.TextureLoader();
    const loaded = await Promise.allSettled([oakUrl, darkWoodUrl, pineUrl].map(url => loader.loadAsync(url)));
    if (signal?.aborted || loaded.some(result => result.status === 'rejected')) {
        for (const result of loaded) if (result.status === 'fulfilled') result.value.dispose();
        throw new Error('Wood textures could not be loaded.');
    }
    const [oak, dark, pine] = loaded.map(({ value }) => value);
    const grainMaps = { oak, dark, pine: orientPineTexture(pine), spruce: createSpruceTexture() };
    const palettes = Object.fromEntries(Object.entries(grainMaps).map(([name, grain]) => {
        const maps = { grain, end: createEndGrainTexture(grain) };
        return [name, { maps, materials: [woodMaterial(maps, false), woodMaterial(maps, true)] }];
    }));
    const textures = Object.values(palettes).flatMap(({ maps }) => Object.values(maps));
    for (const texture of textures) {
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.wrapS = texture.wrapT = THREE.RepeatWrapping;
        texture.anisotropy = Math.min(renderer.capabilities.getMaxAnisotropy(), 8);
    }

    const scene = new THREE.Scene();
    const model = new THREE.Group();
    const floor = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshStandardMaterial({ roughness: 1 }));
    floor.rotation.x = -Math.PI / 2;
    floor.receiveShadow = true;
    const key = new THREE.DirectionalLight();
    key.castShadow = true;
    key.shadow.mapSize.set(2048, 2048);
    key.shadow.normalBias = 0.01;
    key.shadow.radius = 3;
    key.shadow.autoUpdate = false;
    scene.add(model, floor, key, key.target);
    const pmrem = new THREE.PMREMGenerator(renderer);
    const room = new RoomEnvironment();
    const environment = pmrem.fromScene(room, 0.04);
    room.dispose();
    pmrem.dispose();
    scene.environment = environment.texture;

    const metal = new THREE.MeshStandardMaterial({ color: '#777e83', metalness: 0.86, roughness: 0.29 });
    const bounds = new THREE.Box3();
    const size = new THREE.Vector3();
    const center = new THREE.Vector3();
    let settings = {};
    let light = resolvePresentationLighting(settings);
    let palette = palettes.pine;

    const positionLights = () => {
        const span = Math.max(size.length(), 0.5);
        floor.position.set(center.x, bounds.isEmpty() ? 0 : bounds.min.y - 0.003, center.z);
        floor.scale.setScalar(Math.max(span * 35, 120));
        key.target.position.copy(center);
        key.position.copy(center).addScaledVector(light.direction, span * 1.6);
        const shadow = key.shadow.camera;
        shadow.left = shadow.bottom = -span * 0.65;
        shadow.right = shadow.top = span * 0.65;
        shadow.near = Math.max(span * 0.02, 0.01);
        shadow.far = span * 3.2;
        shadow.updateProjectionMatrix();
        key.shadow.needsUpdate = true;
    };

    const assignMaterials = () => {
        for (const mesh of model.children) {
            mesh.material = mesh.userData.hardware ? metal : palette.materials[Number(mesh.userData.grainAlongWidth)];
        }
    };

    const configure = (next) => {
        const nextLight = resolvePresentationLighting(next);
        const directionChanged = !nextLight.direction.equals(light.direction);
        light = nextLight;
        palette = palettes[next.wood] ?? palettes.pine;
        if (settings.wood !== next.wood || settings.finish !== next.finish) {
            for (const material of palette.materials) updateFinish(material, next);
            assignMaterials();
        }
        if (settings.light !== next.light || !scene.background) {
            scene.background = new THREE.Color(light.background);
            floor.material.color.set(light.floor);
            key.color.set(light.key);
        }
        settings = { ...next };
        scene.environmentIntensity = light.environment;
        key.intensity = light.intensity;
        if (directionChanged) positionLights();
    };

    const setModel = (surfaces, hardware) => {
        model.clear();
        const add = (source, name, isHardware) => {
            source.updateWorldMatrix(true, false);
            const mesh = new THREE.Mesh(source.geometry, metal);
            mesh.name = name;
            source.matrixWorld.decompose(mesh.position, mesh.quaternion, mesh.scale);
            mesh.castShadow = mesh.receiveShadow = true;
            mesh.userData.hardware = isHardware;
            mesh.userData.grainAlongWidth = source.userData.part?.grain_axis === 'width';
            model.add(mesh);
        };
        for (const surface of surfaces) {
            add(surface, `${surface.userData.partName ?? 'Wood'} #${surface.userData.instanceId}`, false);
        }
        for (const [index, group] of hardware.entries()) {
            group.children.slice(0, 3).forEach((child, piece) => add(child, `Fastener ${index + 1}.${piece + 1}`, true));
        }
        bounds.setFromObject(model);
        bounds.getSize(size);
        bounds.getCenter(center);
        assignMaterials();
        positionLights();
    };

    const dispose = () => {
        model.clear();
        floor.geometry.dispose();
        floor.material.dispose();
        for (const { materials } of Object.values(palettes)) {
            for (const material of materials) material.dispose();
        }
        for (const texture of textures) texture.dispose();
        metal.dispose();
        key.shadow.dispose();
        environment.dispose();
    };

    return {
        scene, model, bounds, configure, setModel, dispose,
        get exposure() { return light.exposure; },
        get woodMaps() { return palette.maps; },
    };
};

export const exportPresentationPng = (renderer, scene, camera) => {
    const size = renderer.getSize(new THREE.Vector2());
    const pixelRatio = renderer.getPixelRatio();
    const scale = Math.min(2, 4096 / Math.max(size.x, size.y));
    return new Promise((resolve, reject) => {
        try {
            renderer.setPixelRatio(1);
            renderer.setSize(Math.max(1, Math.round(size.x * scale)), Math.max(1, Math.round(size.y * scale)), false);
            renderer.render(scene, camera);
            renderer.domElement.toBlob(blob => blob ? resolve(blob) : reject(new Error('PNG export failed.')), 'image/png');
        } finally {
            renderer.setPixelRatio(pixelRatio);
            renderer.setSize(size.x, size.y, false);
        }
    });
};
