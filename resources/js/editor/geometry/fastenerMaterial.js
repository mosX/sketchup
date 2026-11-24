import { DoubleSide, Ray, Vector3 } from 'three';
import { Brush } from 'three-bvh-csg';

const epsilon = 1e-6;

export const mergeMaterialIntervals = (intervals) => {
    const merged = [];
    for (const interval of intervals.sort((a, b) => a.entry - b.entry)) {
        const last = merged.at(-1);
        if (last && interval.entry <= last.exit + epsilon) {
            last.exit = Math.max(last.exit, interval.exit);
        } else {
            merged.push({ ...interval });
        }
    }
    return merged;
};

export const createMaterialProbe = (geometry) => {
    if (!geometry.attributes.position?.count || geometry.index?.count === 0) return () => [];

    const brush = new Brush(geometry);
    brush.prepareGeometry();
    const tree = geometry.boundsTree;
    const extent = geometry.boundingSphere.radius + geometry.boundingSphere.center.length() + epsilon;
    return (origin, direction) => {
        const offset = origin.length() + extent;
        const ray = new Ray(new Vector3().copy(origin).addScaledVector(direction, -offset), direction);
        const hits = tree.raycast(ray, DoubleSide).sort((a, b) => a.distance - b.distance);
        const intervals = [];
        let entry = null;
        for (let index = 0; index < hits.length;) {
            const distance = hits[index].distance;
            let enters = false;
            let exits = false;
            do {
                const facing = hits[index++].face.normal.dot(direction);
                enters ||= facing < -epsilon;
                exits ||= facing > epsilon;
            } while (index < hits.length && hits[index].distance - distance <= epsilon);

            if (enters && !exits) entry ??= distance - offset;
            if (exits && !enters && entry !== null) {
                intervals.push({ entry, exit: distance - offset });
                entry = null;
            }
        }
        return intervals;
    };
};
