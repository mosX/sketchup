import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { ADDITION, Brush, Evaluator, SUBTRACTION } from 'three-bvh-csg';
import {
    drillPlacement,
    groovePlacement,
    millimeterScale,
    partSize,
    plungeRoutePlacement,
    roundoverEdgeFrame,
} from './coordinates.js';

const halfSpaceCutter = (part, point, normal, kerf = 0) => {
    const { length, width, thickness } = partSize(part);
    const extent = Math.max(length, width, thickness) * 6 + 20;
    const cutter = new Brush(new THREE.BoxGeometry(extent, extent, extent));
    cutter.quaternion.setFromUnitVectors(new THREE.Vector3(1, 0, 0), normal);
    cutter.position.copy(point).addScaledVector(normal, extent / 2 + kerf / 2);

    return cutter;
};

export const crossCutPlane = (part, operation) => {
    const { length } = partSize(part);
    const position = -length / 2 + Number(operation.position) * millimeterScale;
    const miter = THREE.MathUtils.degToRad(Number(operation.miter_angle));
    const bevel = THREE.MathUtils.degToRad(Number(operation.bevel_angle));
    const normal = new THREE.Vector3(1, Math.tan(bevel), Math.tan(miter)).normalize();

    return {
        point: new THREE.Vector3(position, 0, 0),
        normal,
    };
};

const isPartialDepthCut = (part, operation) => Number(operation.cut_depth ?? part.dimensions.thickness) < Number(part.dimensions.thickness) - 0.01;

const depthLimitedKerfCutter = (part, operation, point, normal) => {
    const { length, width, thickness } = partSize(part);
    const extent = Math.max(length, width, thickness) * 3 + 10;
    const kerf = Math.max(Number(operation.kerf ?? 0.1) * millimeterScale, 0.001);
    const depth = Math.min(Number(operation.cut_depth) * millimeterScale, thickness);
    const cutter = new Brush(new THREE.BoxGeometry(kerf, depth + 0.006, extent));
    cutter.quaternion.setFromUnitVectors(new THREE.Vector3(1, 0, 0), normal);
    const direction = operation.cut_direction ?? 'top_down';
    cutter.position.copy(point);
    cutter.position.y = direction === 'bottom_up'
        ? -thickness / 2 + depth / 2 - 0.003
        : thickness / 2 - depth / 2 + 0.003;

    return cutter;
};

const crossCutCutter = (part, operation) => {
    const plane = crossCutPlane(part, operation);

    if (isPartialDepthCut(part, operation)) {
        return depthLimitedKerfCutter(part, operation, plane.point, plane.normal);
    }

    const keepSign = operation.keep_side === 'end' ? -1 : 1;
    return halfSpaceCutter(
        part,
        plane.point,
        plane.normal.clone().multiplyScalar(keepSign),
        Number(operation.kerf) * millimeterScale,
    );
};

export const ripCutPlane = (part, operation) => {
    const { length, width } = partSize(part);
    const startOffset = Number(operation.start_offset) * millimeterScale;
    const endOffset = Number(operation.end_offset) * millimeterScale;
    const leftReference = operation.reference_side !== 'right';
    const startZ = leftReference ? -width / 2 + startOffset : width / 2 - startOffset;
    const endZ = leftReference ? -width / 2 + endOffset : width / 2 - endOffset;
    const tangent = new THREE.Vector3(length, 0, endZ - startZ).normalize();
    const normal = new THREE.Vector3(-tangent.z, 0, tangent.x);
    normal.applyAxisAngle(tangent, THREE.MathUtils.degToRad(Number(operation.bevel_angle)));

    return {
        point: new THREE.Vector3(0, 0, (startZ + endZ) / 2),
        normal,
        leftReference,
    };
};

const ripCutCutter = (part, operation) => {
    const plane = ripCutPlane(part, operation);

    if (isPartialDepthCut(part, operation)) {
        return depthLimitedKerfCutter(part, operation, plane.point, plane.normal);
    }

    const referenceSign = plane.leftReference ? -1 : 1;
    const keepReference = operation.keep_side === 'reference';
    const removedSign = keepReference ? -referenceSign : referenceSign;
    return halfSpaceCutter(
        part,
        plane.point,
        plane.normal.clone().multiplyScalar(removedSign),
        Number(operation.kerf) * millimeterScale,
    );
};

const grooveCutter = (part, operation) => {
    const placement = groovePlacement(part, operation);
    const epsilon = 0.004;
    const geometry = new THREE.BoxGeometry(
        placement.length,
        placement.width,
        placement.depth + epsilon,
    );
    const brush = new Brush(geometry);
    const orientation = new THREE.Matrix4().makeBasis(placement.path, placement.across, placement.frame.inward);
    brush.quaternion.setFromRotationMatrix(orientation);
    brush.position.copy(placement.surfacePoint).addScaledVector(placement.frame.inward, placement.depth / 2 - epsilon / 2);

    return brush;
};

const plungeCylinder = (placement, point) => {
    const epsilon = 0.004;
    const cylinder = new Brush(new THREE.CylinderGeometry(
        Math.max(placement.bottomRadius, 0.00001),
        placement.surfaceRadius,
        placement.depth + epsilon,
        40,
    ));
    cylinder.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), placement.frame.inward);
    cylinder.position.copy(point).addScaledVector(placement.frame.inward, placement.depth / 2 - epsilon / 2);

    return cylinder;
};

const plungeConnectingSweep = (placement) => {
    const epsilon = 0.004;
    const shape = new THREE.Shape();
    shape.moveTo(-placement.surfaceRadius, -epsilon);
    shape.lineTo(placement.surfaceRadius, -epsilon);
    shape.lineTo(Math.max(placement.bottomRadius, 0.00001), placement.depth);
    shape.lineTo(-Math.max(placement.bottomRadius, 0.00001), placement.depth);
    shape.closePath();
    const sweep = new Brush(new THREE.ExtrudeGeometry(shape, {
        depth: placement.travelLength,
        steps: 1,
        bevelEnabled: false,
        curveSegments: 1,
    }));
    sweep.quaternion.setFromRotationMatrix(new THREE.Matrix4().makeBasis(
        placement.across,
        placement.frame.inward,
        placement.path,
    ));
    sweep.position.copy(placement.startPoint);

    return sweep;
};

const plungeRouteCutter = (part, operation) => {
    const placement = plungeRoutePlacement(part, operation);
    const startCylinder = plungeCylinder(placement, placement.startPoint);

    if (operation.route_mode !== 'path' || placement.travelLength <= 0.001) {
        return startCylinder;
    }

    const endPoint = placement.startPoint.clone().addScaledVector(placement.path, placement.travelLength);
    const endCylinder = plungeCylinder(placement, endPoint);
    const connectingSweep = plungeConnectingSweep(placement);
    startCylinder.updateMatrixWorld();
    connectingSweep.updateMatrixWorld();
    endCylinder.updateMatrixWorld();

    const evaluator = new Evaluator();
    evaluator.useGroups = false;
    const firstUnion = evaluator.evaluate(startCylinder, connectingSweep, ADDITION);
    firstUnion.updateMatrixWorld();
    const cutter = evaluator.evaluate(firstUnion, endCylinder, ADDITION);
    startCylinder.geometry.dispose();
    connectingSweep.geometry.dispose();
    endCylinder.geometry.dispose();
    firstUnion.geometry.dispose();

    return cutter;
};

const plungeRoutePreview = (part, operation) => {
    const placement = plungeRoutePlacement(part, operation);
    const brushes = [plungeCylinder(placement, placement.startPoint)];

    if (operation.route_mode === 'path' && placement.travelLength > 0.001) {
        const endPoint = placement.startPoint.clone().addScaledVector(placement.path, placement.travelLength);
        brushes.push(plungeConnectingSweep(placement), plungeCylinder(placement, endPoint));
    }

    const geometries = brushes.map((brush) => {
        brush.updateMatrixWorld();
        const geometry = (brush.geometry.index ? brush.geometry.toNonIndexed() : brush.geometry.clone())
            .applyMatrix4(brush.matrixWorld);
        geometry.deleteAttribute('uv');
        brush.geometry.dispose();

        return geometry;
    });
    const geometry = mergeGeometries(geometries, false);
    geometries.forEach((item) => item.dispose());

    return new THREE.Mesh(geometry);
};

const drillCutter = (part, operation) => {
    const placement = drillPlacement(part, operation);
    const epsilon = 0.004;
    const cylinder = new Brush(new THREE.CylinderGeometry(
        placement.radius,
        placement.radius,
        placement.depth + epsilon * 2,
        40,
    ));
    cylinder.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), placement.frame.inward);
    cylinder.position.copy(placement.surfacePoint).addScaledVector(placement.frame.inward, placement.depth / 2);

    return cylinder;
};

const edgeRoundoverCutter = (part, operation) => {
    const frame = roundoverEdgeFrame(part, operation.edge);
    const radius = Math.max(Number(operation.radius) * millimeterScale, 0.001);
    const epsilon = 0.006;
    const transverseExtent = radius + epsilon;
    const axialExtent = frame.extent + epsilon * 2;
    const boxDimensions = {
        x: [axialExtent, transverseExtent, transverseExtent],
        y: [transverseExtent, axialExtent, transverseExtent],
        z: [transverseExtent, transverseExtent, axialExtent],
    }[frame.axis];
    const cornerBox = new Brush(new THREE.BoxGeometry(...boxDimensions));
    cornerBox.position.copy(frame.corner)
        .addScaledVector(frame.inwardA, radius / 2 - epsilon / 2)
        .addScaledVector(frame.inwardB, radius / 2 - epsilon / 2);
    cornerBox.updateMatrixWorld();

    const cylinder = new Brush(new THREE.CylinderGeometry(radius, radius, axialExtent + epsilon, 40, 1, false));
    const axisVector = {
        x: new THREE.Vector3(1, 0, 0),
        y: new THREE.Vector3(0, 1, 0),
        z: new THREE.Vector3(0, 0, 1),
    }[frame.axis];
    cylinder.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), axisVector);
    cylinder.position.copy(frame.corner)
        .addScaledVector(frame.inwardA, radius)
        .addScaledVector(frame.inwardB, radius);
    cylinder.updateMatrixWorld();

    const evaluator = new Evaluator();
    evaluator.useGroups = false;
    const cutter = evaluator.evaluate(cornerBox, cylinder, SUBTRACTION);
    cornerBox.geometry.dispose();
    cylinder.geometry.dispose();

    return cutter;
};

export const operationCutter = (part, operation) => {
    if (operation.type === 'cross_cut') return crossCutCutter(part, operation);
    if (operation.type === 'rip_cut') return ripCutCutter(part, operation);
    if (operation.type === 'groove') return grooveCutter(part, operation);
    if (operation.type === 'edge_roundover') return edgeRoundoverCutter(part, operation);
    if (operation.type === 'plunge_route') return plungeRouteCutter(part, operation);
    if (operation.type === 'drill') return drillCutter(part, operation);

    return null;
};

export const operationPreviewObject = (part, operation) => {
    if (operation.type === 'plunge_route') return plungeRoutePreview(part, operation);

    return operationCutter(part, operation);
};
