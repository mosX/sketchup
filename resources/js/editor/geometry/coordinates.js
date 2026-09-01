import * as THREE from 'three';

export const millimeterScale = 0.01;

export const clamp = (value, minimum, maximum) => Math.min(Math.max(value, minimum), maximum);

export const partSize = (part) => ({
    length: Number(part.dimensions.length) * millimeterScale,
    width: Number(part.dimensions.width) * millimeterScale,
    thickness: Number(part.dimensions.thickness) * millimeterScale,
});

export const grooveFaceFrame = (part, face) => {
    const { length, width, thickness } = partSize(part);

    return {
        top: {
            center: new THREE.Vector3(0, thickness / 2, 0),
            u: new THREE.Vector3(1, 0, 0),
            v: new THREE.Vector3(0, 0, 1),
            inward: new THREE.Vector3(0, -1, 0),
            uSize: length,
            vSize: width,
            maximumDepth: thickness,
        },
        bottom: {
            center: new THREE.Vector3(0, -thickness / 2, 0),
            u: new THREE.Vector3(1, 0, 0),
            v: new THREE.Vector3(0, 0, -1),
            inward: new THREE.Vector3(0, 1, 0),
            uSize: length,
            vSize: width,
            maximumDepth: thickness,
        },
        left: {
            center: new THREE.Vector3(0, 0, -width / 2),
            u: new THREE.Vector3(1, 0, 0),
            v: new THREE.Vector3(0, 1, 0),
            inward: new THREE.Vector3(0, 0, 1),
            uSize: length,
            vSize: thickness,
            maximumDepth: width,
        },
        right: {
            center: new THREE.Vector3(0, 0, width / 2),
            u: new THREE.Vector3(-1, 0, 0),
            v: new THREE.Vector3(0, 1, 0),
            inward: new THREE.Vector3(0, 0, -1),
            uSize: length,
            vSize: thickness,
            maximumDepth: width,
        },
        start: {
            center: new THREE.Vector3(-length / 2, 0, 0),
            u: new THREE.Vector3(0, 0, -1),
            v: new THREE.Vector3(0, 1, 0),
            inward: new THREE.Vector3(1, 0, 0),
            uSize: width,
            vSize: thickness,
            maximumDepth: length,
        },
        end: {
            center: new THREE.Vector3(length / 2, 0, 0),
            u: new THREE.Vector3(0, 0, 1),
            v: new THREE.Vector3(0, 1, 0),
            inward: new THREE.Vector3(-1, 0, 0),
            uSize: width,
            vSize: thickness,
            maximumDepth: length,
        },
    }[face] ?? null;
};

export const groovePlacement = (part, operation) => {
    const isSurfaceGroove = 'center_u' in operation && 'center_v' in operation && 'path_angle' in operation && 'groove_length' in operation;
    const direction = operation.direction ?? 'length';
    const start = Number(operation.start ?? 0);
    const end = Number(operation.end ?? part.dimensions.length);
    const offset = Number(operation.offset ?? part.dimensions.width / 2);
    const normalized = isSurfaceGroove ? operation : {
        ...operation,
        center_u: direction === 'width' ? offset : (start + end) / 2,
        center_v: direction === 'width' ? (start + end) / 2 : offset,
        path_angle: direction === 'width' ? 90 : 0,
        groove_length: Math.max(end - start, 0.1),
    };
    const frame = grooveFaceFrame(part, normalized.face) ?? grooveFaceFrame(part, 'top');
    const angle = THREE.MathUtils.degToRad(Number(normalized.path_angle));
    const path = frame.u.clone().multiplyScalar(Math.cos(angle)).addScaledVector(frame.v, Math.sin(angle)).normalize();
    const across = frame.u.clone().multiplyScalar(-Math.sin(angle)).addScaledVector(frame.v, Math.cos(angle)).normalize();
    const length = Math.max(Number(normalized.groove_length) * millimeterScale, 0.001);
    const width = Math.max(Number(normalized.width) * millimeterScale, 0.001);
    const depth = Math.min(Math.max(Number(normalized.depth) * millimeterScale, 0.001), frame.maximumDepth);
    const surfacePoint = frame.center.clone()
        .addScaledVector(frame.u, Number(normalized.center_u) * millimeterScale - frame.uSize / 2)
        .addScaledVector(frame.v, Number(normalized.center_v) * millimeterScale - frame.vSize / 2);

    return { frame, path, across, length, width, depth, surfacePoint };
};

export const clampGrooveCenter = (operation, frame, centerU, centerV) => {
    const angle = THREE.MathUtils.degToRad(Number(operation.path_angle));
    const halfLength = Number(operation.groove_length) / 2;
    const halfWidth = Number(operation.width) / 2;
    const extentU = Math.abs(Math.cos(angle)) * halfLength + Math.abs(Math.sin(angle)) * halfWidth;
    const extentV = Math.abs(Math.sin(angle)) * halfLength + Math.abs(Math.cos(angle)) * halfWidth;
    const maximumU = frame.uSize / millimeterScale;
    const maximumV = frame.vSize / millimeterScale;

    return {
        center_u: clamp(centerU, Math.min(extentU, maximumU / 2), Math.max(maximumU - extentU, maximumU / 2)),
        center_v: clamp(centerV, Math.min(extentV, maximumV / 2), Math.max(maximumV - extentV, maximumV / 2)),
    };
};

export const plungeProfileRadii = (operation, depth = Number(operation.depth)) => {
    const profile = operation.cutter_profile ?? 'straight';
    const nominalRadius = Math.max(Number(operation.cutter_diameter) / 2, 0.05);
    const cutterAngle = Number(operation.cutter_angle ?? 0);

    if (profile === 'dovetail') {
        return {
            surfaceRadius: Math.max(nominalRadius - depth * Math.tan(THREE.MathUtils.degToRad(cutterAngle)), 0.05),
            bottomRadius: nominalRadius,
        };
    }

    if (profile === 'v_groove') {
        return {
            surfaceRadius: Math.min(nominalRadius, depth * Math.tan(THREE.MathUtils.degToRad(cutterAngle / 2))),
            bottomRadius: 0,
        };
    }

    return { surfaceRadius: nominalRadius, bottomRadius: nominalRadius };
};

export const plungeMaximumRadius = (operation) => {
    const radii = plungeProfileRadii(operation);

    return Math.max(radii.surfaceRadius, radii.bottomRadius);
};

export const plungeRoutePlacement = (part, operation) => {
    const frame = grooveFaceFrame(part, operation.face) ?? grooveFaceFrame(part, 'top');
    const angle = THREE.MathUtils.degToRad(Number(operation.path_angle ?? 0));
    const path = frame.u.clone().multiplyScalar(Math.cos(angle)).addScaledVector(frame.v, Math.sin(angle)).normalize();
    const across = frame.u.clone().multiplyScalar(-Math.sin(angle)).addScaledVector(frame.v, Math.cos(angle)).normalize();
    const travelLength = operation.route_mode === 'path'
        ? Math.max(Number(operation.travel_length) * millimeterScale, 0.001)
        : 0;
    const depth = Math.min(Math.max(Number(operation.depth) * millimeterScale, 0.001), frame.maximumDepth);
    const profileRadii = plungeProfileRadii(operation, depth / millimeterScale);
    const surfaceRadius = Math.max(profileRadii.surfaceRadius * millimeterScale, 0.0005);
    const bottomRadius = Math.max(profileRadii.bottomRadius * millimeterScale, 0);
    const startPoint = frame.center.clone()
        .addScaledVector(frame.u, Number(operation.start_u) * millimeterScale - frame.uSize / 2)
        .addScaledVector(frame.v, Number(operation.start_v) * millimeterScale - frame.vSize / 2);

    return { frame, path, across, travelLength, depth, surfaceRadius, bottomRadius, startPoint };
};

export const clampPlungeStart = (operation, frame, startU, startV) => {
    const angle = THREE.MathUtils.degToRad(Number(operation.path_angle ?? 0));
    const travelLength = operation.route_mode === 'path' ? Number(operation.travel_length) : 0;
    const deltaU = Math.cos(angle) * travelLength;
    const deltaV = Math.sin(angle) * travelLength;
    const radius = Math.min(
        plungeMaximumRadius(operation),
        frame.uSize / millimeterScale / 2,
        frame.vSize / millimeterScale / 2,
    );
    const maximumU = frame.uSize / millimeterScale;
    const maximumV = frame.vSize / millimeterScale;
    const minimumStartU = radius - Math.min(0, deltaU);
    const maximumStartU = maximumU - radius - Math.max(0, deltaU);
    const minimumStartV = radius - Math.min(0, deltaV);
    const maximumStartV = maximumV - radius - Math.max(0, deltaV);

    return {
        start_u: clamp(startU, Math.min(minimumStartU, maximumU / 2), Math.max(maximumStartU, maximumU / 2)),
        start_v: clamp(startV, Math.min(minimumStartV, maximumV / 2), Math.max(maximumStartV, maximumV / 2)),
    };
};

export const drillPlacement = (part, operation) => {
    const frame = grooveFaceFrame(part, operation.face) ?? grooveFaceFrame(part, 'top');
    const radius = Math.max(Number(operation.diameter) * millimeterScale / 2, 0.0005);
    const depth = operation.through
        ? frame.maximumDepth
        : Math.min(Math.max(Number(operation.depth) * millimeterScale, 0.001), frame.maximumDepth);
    const surfacePoint = frame.center.clone()
        .addScaledVector(frame.u, Number(operation.center_u) * millimeterScale - frame.uSize / 2)
        .addScaledVector(frame.v, Number(operation.center_v) * millimeterScale - frame.vSize / 2);

    return { frame, radius, depth, surfacePoint };
};

export const clampDrillCenter = (operation, frame, centerU, centerV) => {
    const maximumU = frame.uSize / millimeterScale;
    const maximumV = frame.vSize / millimeterScale;
    const radius = Math.min(Number(operation.diameter) / 2, maximumU / 2, maximumV / 2);

    return {
        center_u: clamp(centerU, radius, maximumU - radius),
        center_v: clamp(centerV, radius, maximumV - radius),
    };
};

export const roundoverEdgeFrame = (part, edge) => {
    const { length, width, thickness } = partSize(part);
    const x = length / 2;
    const y = thickness / 2;
    const z = width / 2;
    const frames = {
        top_left: ['x', length, new THREE.Vector3(0, y, -z), new THREE.Vector3(0, -1, 0), new THREE.Vector3(0, 0, 1)],
        top_right: ['x', length, new THREE.Vector3(0, y, z), new THREE.Vector3(0, -1, 0), new THREE.Vector3(0, 0, -1)],
        bottom_left: ['x', length, new THREE.Vector3(0, -y, -z), new THREE.Vector3(0, 1, 0), new THREE.Vector3(0, 0, 1)],
        bottom_right: ['x', length, new THREE.Vector3(0, -y, z), new THREE.Vector3(0, 1, 0), new THREE.Vector3(0, 0, -1)],
        top_start: ['z', width, new THREE.Vector3(-x, y, 0), new THREE.Vector3(1, 0, 0), new THREE.Vector3(0, -1, 0)],
        top_end: ['z', width, new THREE.Vector3(x, y, 0), new THREE.Vector3(-1, 0, 0), new THREE.Vector3(0, -1, 0)],
        bottom_start: ['z', width, new THREE.Vector3(-x, -y, 0), new THREE.Vector3(1, 0, 0), new THREE.Vector3(0, 1, 0)],
        bottom_end: ['z', width, new THREE.Vector3(x, -y, 0), new THREE.Vector3(-1, 0, 0), new THREE.Vector3(0, 1, 0)],
        start_left: ['y', thickness, new THREE.Vector3(-x, 0, -z), new THREE.Vector3(1, 0, 0), new THREE.Vector3(0, 0, 1)],
        start_right: ['y', thickness, new THREE.Vector3(-x, 0, z), new THREE.Vector3(1, 0, 0), new THREE.Vector3(0, 0, -1)],
        end_left: ['y', thickness, new THREE.Vector3(x, 0, -z), new THREE.Vector3(-1, 0, 0), new THREE.Vector3(0, 0, 1)],
        end_right: ['y', thickness, new THREE.Vector3(x, 0, z), new THREE.Vector3(-1, 0, 0), new THREE.Vector3(0, 0, -1)],
    };
    const [axis, extent, corner, inwardA, inwardB] = frames[edge] ?? frames.top_left;

    return { axis, extent, corner, inwardA, inwardB };
};

export const edgeRoundoverMaximumRadius = (part, edge) => {
    const { length, width, thickness } = part.dimensions;

    if (['top_left', 'top_right', 'bottom_left', 'bottom_right'].includes(edge)) {
        return Math.min(Number(width), Number(thickness)) / 2;
    }

    if (['top_start', 'top_end', 'bottom_start', 'bottom_end'].includes(edge)) {
        return Math.min(Number(length), Number(thickness)) / 2;
    }

    return Math.min(Number(length), Number(width)) / 2;
};
