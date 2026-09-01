import * as THREE from 'three';
import { Brush, Evaluator, INTERSECTION, SUBTRACTION } from 'three-bvh-csg';
import { operationCutter } from './cutters.js';
import { partSize } from './coordinates.js';

const cutterCacheKey = (part, operation) => JSON.stringify({
    partId: part.id,
    dimensions: part.dimensions,
    operation,
});

const cloneBrush = (brush) => {
    const clone = new Brush(brush.geometry.clone());
    clone.position.copy(brush.position);
    clone.quaternion.copy(brush.quaternion);
    clone.scale.copy(brush.scale);

    return clone;
};

export const createCsgContext = () => {
    const cutters = new Map();

    return {
        cutter(part, operation) {
            const key = cutterCacheKey(part, operation);
            let cached = cutters.get(key);

            if (!cached) {
                cached = operationCutter(part, operation);

                if (!cached) return null;

                cutters.set(key, cached);
            }

            return cloneBrush(cached);
        },
        dispose() {
            cutters.forEach((cutter) => cutter.geometry.dispose());
            cutters.clear();
        },
    };
};

const subtractOperations = (part, operations, context) => {
    const { length, width, thickness } = partSize(part);
    const evaluator = new Evaluator();
    evaluator.useGroups = false;
    let result = new Brush(new THREE.BoxGeometry(length, thickness, width));

    operations.filter((operation) => operation.enabled !== false).forEach((operation) => {
        const cutter = context.cutter(part, operation);

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

export const createPartGeometry = (part, providedContext = null) => {
    const context = providedContext ?? createCsgContext();
    const ownsContext = providedContext === null;
    const { length, width, thickness } = partSize(part);
    let result;

    try {
        result = subtractOperations(part, part.operations ?? [], context);
    } catch (error) {
        result?.geometry.dispose();
        console.error('Не удалось построить операцию заготовки.', error);
        result = new Brush(new THREE.BoxGeometry(length, thickness, width));
    } finally {
        if (ownsContext) context.dispose();
    }

    result.geometry.computeVertexNormals();
    result.geometry.computeBoundingSphere();

    return result.geometry;
};

export const createOffcutGeometry = (part, operation, providedContext = null) => {
    if (!operation || operation.enabled === false) return null;

    const context = providedContext ?? createCsgContext();
    const ownsContext = providedContext === null;
    const operationIndex = (part.operations ?? []).findIndex((item) => item.id === operation.id);

    if (operationIndex < 0) return null;

    let stock;
    let cutter;

    try {
        stock = subtractOperations(part, part.operations.slice(0, operationIndex), context);
        cutter = context.cutter(part, operation);

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
    } finally {
        if (ownsContext) context.dispose();
    }
};
