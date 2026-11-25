import * as THREE from 'three';
import { millimeterScale } from './geometry/coordinates.js';
import { woodGrainLength, woodGrainWidth, woodSideOffset } from './presentationTextures.js';

const triangleRange = (geometry) => {
    const total = geometry.index?.count ?? geometry.attributes.position.count;
    const start = Math.min(total, geometry.drawRange.start);
    const count = Math.floor(Math.min(total - start, geometry.drawRange.count) / 3) * 3;
    return { start, count, total };
};

const geometryWithTriangleRange = (geometry, reverseWinding) => {
    const { start, count, total } = triangleRange(geometry);
    if (start === 0 && count === total && !reverseWinding) return geometry;

    const indices = new Uint32Array(count);
    for (let offset = 0; offset < count; offset++) {
        const vertex = offset % 3;
        const index = start + offset + (reverseWinding && vertex !== 0 ? 3 - 2 * vertex : 0);
        indices[offset] = geometry.index?.getX(index) ?? index;
    }
    const result = new THREE.BufferGeometry();
    for (const attribute of ['position', 'normal', 'uv']) {
        if (geometry.hasAttribute(attribute)) result.setAttribute(attribute, geometry.getAttribute(attribute));
    }
    result.setIndex(new THREE.BufferAttribute(indices, 1));
    return result;
};

const woodExportGeometry = (geometry, grainAlongWidth) => {
    const { start, count } = triangleRange(geometry);
    const positions = new Float32Array(count * 3);
    const normals = new Float32Array(count * 3);
    const uvs = new Float32Array(count * 2);
    const side = [];
    const end = [];
    const position = geometry.getAttribute('position');
    const normal = geometry.getAttribute('normal');
    const face = [new THREE.Vector3(), new THREE.Vector3(), new THREE.Vector3()];
    const edge = new THREE.Vector3();
    const faceNormal = new THREE.Vector3();
    const vertexNormal = new THREE.Vector3();
    const originalIndex = offset => geometry.index?.getX(start + offset) ?? start + offset;

    for (let offset = 0; offset < count; offset += 3) {
        face.forEach((vertex, corner) => vertex.fromBufferAttribute(position, originalIndex(offset + corner)));
        edge.subVectors(face[2], face[0]);
        faceNormal.subVectors(face[1], face[0]).cross(edge).normalize();
        const along = Math.abs(grainAlongWidth ? faceNormal.z : faceNormal.x);
        const across = Math.abs(grainAlongWidth ? faceNormal.x : faceNormal.z);
        const thickness = Math.abs(faceNormal.y);
        const isEndGrain = along >= Math.max(across, thickness);
        const indices = isEndGrain ? end : side;

        face.forEach((vertex, corner) => {
            const index = offset + corner;
            vertex.toArray(positions, index * 3);
            if (normal) vertexNormal.fromBufferAttribute(normal, originalIndex(index)).toArray(normals, index * 3);
            else faceNormal.toArray(normals, index * 3);
            const u = grainAlongWidth ? vertex.z : vertex.x;
            const v = grainAlongWidth ? vertex.x : vertex.z;
            uvs[index * 2] = isEndGrain ? vertex.y / woodGrainWidth + 0.5 : u / woodGrainLength;
            uvs[index * 2 + 1] = isEndGrain ? v / woodGrainWidth + 0.5
                : across > thickness ? vertex.y / woodGrainWidth + woodSideOffset : v / woodGrainWidth;
            indices.push(index);
        });
    }

    const result = new THREE.BufferGeometry();
    result.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    result.setAttribute('normal', new THREE.BufferAttribute(normals, 3));
    result.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
    const indices = new Uint32Array(count);
    indices.set(side);
    indices.set(end, side.length);
    result.setIndex(new THREE.BufferAttribute(indices, 1));
    if (side.length) result.addGroup(0, side.length, 0);
    if (end.length) result.addGroup(side.length, end.length, 1);
    return result;
};

export const createPresentationExportModel = (model, format, woodMaps) => {
    const root = new THREE.Group();
    const isObj = format === 'obj';
    const geometryCache = new Map();
    const ownedGeometries = new Set();
    const ownedMaterials = [];
    let woodMaterials;
    const millimetersPerUnit = isObj ? 1 : 1000;
    root.scale.setScalar(1 / (millimeterScale * millimetersPerUnit));
    model.updateWorldMatrix(true, true);

    for (const source of model.children) {
        if (!source.visible || !triangleRange(source.geometry).count) continue;
        const textured = !isObj && !source.userData.hardware;
        const reverseWinding = isObj && source.matrixWorld.determinant() < 0;
        const grainAlongWidth = source.userData.grainAlongWidth;
        const key = `${source.geometry.id}:${textured ? Boolean(grainAlongWidth) : reverseWinding ? 'reverse' : 'plain'}`;
        if (!geometryCache.has(key)) {
            const geometry = textured ? woodExportGeometry(source.geometry, grainAlongWidth)
                : geometryWithTriangleRange(source.geometry, reverseWinding);
            geometryCache.set(key, geometry);
            if (geometry !== source.geometry) ownedGeometries.add(geometry);
        }
        if (textured && !woodMaterials) {
            woodMaterials = [woodMaps.grain, woodMaps.end].map((map, index) => {
                const material = source.material.clone();
                material.map = map;
                material.name = index === 0 ? 'Wood grain' : 'End grain';
                return material;
            });
            ownedMaterials.push(...woodMaterials);
        }
        const mesh = new THREE.Mesh(geometryCache.get(key), textured ? woodMaterials : source.material);
        mesh.name = source.name;
        if (isObj) mesh.name = mesh.name.replace(/[\r\n#]/g, '_');
        source.matrixWorld.decompose(mesh.position, mesh.quaternion, mesh.scale);
        root.add(mesh);
    }
    root.updateMatrixWorld(true);
    const dispose = () => {
        for (const geometry of ownedGeometries) geometry.dispose();
        for (const material of ownedMaterials) material.dispose();
    };
    return { root, dispose };
};

export const exportPresentationModel = async (model, format, woodMaps) => {
    const { root, dispose } = createPresentationExportModel(model, format, woodMaps);
    try {
        if (format === 'obj') {
            const { OBJExporter } = await import('three/addons/exporters/OBJExporter.js');
            const obj = new OBJExporter().parse(root).replace(/^o (.+)$/gm, (line, name) => `${line}\ng ${name.replace(/\s+/g, '_')}`);
            return new Blob([obj], { type: 'text/plain' });
        }

        const { GLTFExporter } = await import('three/addons/exporters/GLTFExporter.js');
        const glb = await new GLTFExporter().parseAsync(root, { binary: true, trs: true });
        return new Blob([glb], { type: 'model/gltf-binary' });
    } finally {
        dispose();
    }
};
