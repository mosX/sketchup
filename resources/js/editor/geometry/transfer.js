import * as THREE from 'three';

const transferableAttributes = ['position', 'normal'];

export const serializeGeometry = (geometry) => {
    if (!geometry) return null;

    const attributes = {};
    const transfers = [];

    transferableAttributes.forEach((name) => {
        const attribute = geometry.getAttribute(name);

        if (!attribute) return;

        attributes[name] = {
            array: attribute.array,
            itemSize: attribute.itemSize,
            normalized: attribute.normalized,
        };
        transfers.push(attribute.array.buffer);
    });

    const index = geometry.getIndex();
    const serializedIndex = index ? {
        array: index.array,
        itemSize: index.itemSize,
        normalized: index.normalized,
    } : null;

    if (serializedIndex) transfers.push(serializedIndex.array.buffer);

    return {
        payload: { attributes, index: serializedIndex },
        transfers,
    };
};

export const deserializeGeometry = (serialized) => {
    if (!serialized) return null;

    const geometry = new THREE.BufferGeometry();

    Object.entries(serialized.attributes).forEach(([name, attribute]) => {
        geometry.setAttribute(name, new THREE.BufferAttribute(attribute.array, attribute.itemSize, attribute.normalized));
    });

    if (serialized.index) {
        geometry.setIndex(new THREE.BufferAttribute(
            serialized.index.array,
            serialized.index.itemSize,
            serialized.index.normalized,
        ));
    }

    geometry.computeBoundingSphere();

    return geometry;
};
