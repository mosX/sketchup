import { createScriptTransforms } from './scriptTransforms.js';

// Serialize this factory together with createScriptTransforms into the worker.
export function createScriptApi(parameterValues = {}) {
    const math = createScriptTransforms();
    const commands = [];
    const connections = [];
    const parameters = [];
    const logs = [];
    const handles = new Map();
    let operationCount = 0;
    const entryFor = (handle, kind) => {
        const entry = handles.get(handle);
        if (!entry || (kind && entry.kind !== kind)) throw new Error(`Expected a ${kind ?? 'handle'} created by this script.`);
        return entry;
    };
    const world = (entry) => {
        if (!entry.parent) return { matrix: entry.matrix, position: entry.position };
        const parent = world(entry.parent);
        return { matrix: math.multiply(parent.matrix, entry.matrix), position: math.add(parent.position, math.apply(parent.matrix, entry.position)) };
    };
    const setWorld = (entry, pose) => {
        const parent = entry.parent ? world(entry.parent) : { matrix: math.identity(), position: [0, 0, 0] };
        const inverse = math.transpose(parent.matrix);
        entry.matrix = math.multiply(inverse, pose.matrix);
        entry.position = math.apply(inverse, math.add(pose.position, math.scale(parent.position, -1)));
    };
    const movable = (entry) => ({
        move(offset) { entry.position = math.add(entry.position, math.vector(offset)); return entry.handle; },
        rotate(angles) { entry.matrix = math.multiply(math.rotation(angles), entry.matrix); return entry.handle; },
        alignTo(target, { face = 'start', targetFace = 'end', gap = 0, offset = [0, 0, 0] } = {}) {
            if (entry.kind !== 'instance') throw new Error('alignTo applies to instances, not groups.');
            const other = entryFor(target, 'instance');
            if (other === entry) throw new Error('Cannot align an instance to itself.');
            if (!Number.isFinite(gap)) throw new Error('Gap must be finite.');
            const ownFace = math.face(entry.definition.data, face);
            const otherFace = math.face(other.definition.data, targetFace);
            const ownPose = world(entry), otherPose = world(other);
            const normal = math.apply(otherPose.matrix, otherFace.normal);
            const matrix = math.multiply(math.align(math.apply(ownPose.matrix, ownFace.normal), math.scale(normal, -1)), ownPose.matrix);
            const targetPoint = math.add(math.add(otherPose.position, math.apply(otherPose.matrix, otherFace.point)), math.add(math.scale(normal, gap), math.vector(offset)));
            setWorld(entry, { matrix, position: math.add(targetPoint, math.scale(math.apply(matrix, ownFace.point), -1)) });
            return entry.handle;
        },
    });
    const append = (type, data, references = {}) => {
        if (commands.length >= 99) throw new Error('Limit: 99 groups, parts and instances per script.');
        const command = { type, temporary_id: `item_${commands.length + 1}`, data, ...references };
        commands.push(command);
        return command;
    };
    const reference = (handle, kind) => {
        const entry = handles.get(handle);
        if (!entry || entry.kind !== kind) throw new Error(`Expected a ${kind} created by this script.`);
        return entry.id;
    };
    const transform = ({ position = [0, 0, 0], rotation = [0, 0, 0], mirrored = false } = {}) => {
        for (const vector of [position, rotation]) {
            if (!Array.isArray(vector) || vector.length !== 3 || !vector.every(Number.isFinite)) {
                throw new Error('Position and rotation must contain three finite numbers: [x, y, z].');
            }
        }
        return {
            position_x: position[0], position_y: position[1], position_z: position[2],
            rotation_x: rotation[0], rotation_y: rotation[1], rotation_z: rotation[2], mirrored,
        };
    };
    const add = (definition, options = {}) => {
        const command = append('create_instance', transform(options), {
            part_ref: reference(definition, 'part'),
            ...(options.group ? { group_ref: reference(options.group, 'group') } : {}),
        });
        const entry = { kind: 'instance', id: command.temporary_id, command, definition: entryFor(definition, 'part').command,
            parent: options.group ? entryFor(options.group, 'group') : null,
            position: math.vector(options.position ?? [0, 0, 0]), matrix: math.rotation(options.rotation ?? [0, 0, 0]) };
        if (options.mirrored) entry.matrix = math.multiply(entry.matrix, math.reflection('x'));
        const handle = Object.freeze({ id: entry.id, ...movable(entry) });
        entry.handle = handle;
        handles.set(handle, entry);
        return handle;
    };
    const part = (name, dimensions) => {
        const command = append('create_part', { name, material: 'Wood', grain_axis: 'length', ...dimensions, operations: [] });
        const surface = (parameters) => {
            const { length, width, thickness } = command.data;
            const face = parameters.face ?? 'top';
            const sizes = { top: [length, width, thickness], bottom: [length, width, thickness], left: [length, thickness, width], right: [length, thickness, width], start: [width, thickness, length], end: [width, thickness, length] }[face];
            if (!sizes) throw new Error(`Unknown face: ${face}`);
            return { u: sizes[0], v: sizes[1], depth: sizes[2], face };
        };
        const handle = Object.freeze({
            drill(parameters = {}) { const s = surface(parameters); return handle.operation('drill', { face: s.face, center_u: s.u / 2, center_v: s.v / 2, diameter: Math.min(8, s.u, s.v), depth: parameters.through ? s.depth : Math.min(18, s.depth), through: false, ...parameters }); },
            groove(parameters = {}) { const s = surface(parameters); return handle.operation('groove', { face: s.face, center_u: s.u / 2, center_v: s.v / 2, path_angle: 0, groove_length: s.u, width: Math.min(8, s.v), depth: Math.min(6, s.depth), blade_diameter: 190, ...parameters }); },
            roundover(parameters = {}) { return handle.operation('edge_roundover', { edge: 'top_left', radius: Math.min(4, command.data.width / 2, command.data.thickness / 2), ...parameters }); },
            crossCut(parameters = {}) { return handle.operation('cross_cut', { position: command.data.length * 0.9, miter_angle: 0, bevel_angle: 0, kerf: 3.2, cut_depth: command.data.thickness, cut_direction: 'top_down', keep_side: 'start', ...parameters }); },
            ripCut(parameters = {}) { return handle.operation('rip_cut', { reference_side: 'left', start_offset: command.data.width / 4, end_offset: command.data.width / 4, bevel_angle: 0, kerf: 3.2, cut_depth: command.data.thickness, cut_direction: 'top_down', keep_side: 'opposite', ...parameters }); },
            plungeRoute(parameters = {}) { const s = surface(parameters); return handle.operation('plunge_route', { face: s.face, route_mode: 'point', start_u: s.u / 2, start_v: s.v / 2, path_angle: 0, travel_length: 0, cutter_profile: 'straight', cutter_angle: 0, cutter_diameter: Math.min(8, s.u, s.v), depth: Math.min(6, s.depth), ...parameters }); },
            operation(type, parameters = {}) {
                if (!['cross_cut', 'rip_cut', 'groove', 'edge_roundover', 'plunge_route', 'drill'].includes(type)) throw new Error(`Unknown operation: ${type}`);
                if (command.data.operations.length >= 50) throw new Error('Limit: 50 operations per part.');
                if (++operationCount > 100) throw new Error('Limit: 100 machining operations per script.');
                command.data.operations.push({ ...parameters, id: `operation_${operationCount}`, type, status: 'applied' });
                return handle;
            },
        });
        handles.set(handle, { kind: 'part', id: command.temporary_id, command });
        return handle;
    };
    const group = (name, parent = null) => {
        const command = append('create_group', { name }, parent ? { parent_group_ref: reference(parent, 'group') } : {});
        const entry = { kind: 'group', id: command.temporary_id, command, parent: parent ? entryFor(parent, 'group') : null, position: [0, 0, 0], matrix: math.identity() };
        const handle = Object.freeze({ add: (definition, options = {}) => add(definition, { ...options, group: handle }), ...movable(entry) });
        entry.handle = handle;
        handles.set(handle, entry);
        return handle;
    };
    const clone = (original, parent = original.parent, references = new Map()) => {
        if (original.kind === 'part') throw new Error('Copy an instance or group, or repeat a part.');
        let copy;
        if (original.kind === 'instance') {
            const definition = [...handles.keys()].find(handle => handles.get(handle).command === original.definition);
            copy = add(definition, parent ? { group: parent.handle } : {});
        } else {
            const children = [...handles.values()].filter(entry => entry.parent === original);
            copy = group(`${original.command.data.name} copy`, parent?.handle);
            children.forEach(child => clone(child, entryFor(copy), references));
        }
        const entry = entryFor(copy);
        entry.position = [...original.position];
        entry.matrix = original.matrix.map(row => [...row]);
        references.set(original.id, entry.id);
        return copy;
    };
    const cloneWithConnections = (original) => {
        const references = new Map();
        const copy = clone(original, original.parent, references);
        for (const connection of [...connections]) {
            if (!references.has(connection.primary_ref) || !references.has(connection.secondary_ref)) continue;
            if (connections.length >= 30) throw new Error('Limit: 30 connections per script.');
            connections.push({ ...connection, parameters: { ...connection.parameters }, primary_ref: references.get(connection.primary_ref), secondary_ref: references.get(connection.secondary_ref) });
        }
        return copy;
    };
    const repeat = (target, { count, step = [0, 0, 0], position = [0, 0, 0], rotation = [0, 0, 0], group: parent = null } = {}) => {
        if (!Number.isInteger(count) || count < 1 || count > 98) throw new Error('Repeat count must be between 1 and 98.');
        math.vector(step);
        const original = entryFor(target);
        return Array.from({ length: count }, (_, index) => {
            if (original.kind === 'part') return add(target, { position: math.add(math.vector(position), math.scale(step, index)), rotation, ...(parent ? { group: parent } : {}) });
            const copy = cloneWithConnections(original);
            copy.move(math.scale(step, index + 1));
            return copy;
        });
    };
    const mirror = (target, { axis = 'x', origin = [0, 0, 0] } = {}) => {
        const original = entryFor(target);
        const pose = world(original);
        const matrix = math.reflection(axis);
        const pivot = math.vector(origin);
        const copy = cloneWithConnections(original);
        setWorld(entryFor(copy), { matrix: math.multiply(matrix, pose.matrix), position: math.add(pivot, math.apply(matrix, math.add(pose.position, math.scale(pivot, -1)))) });
        return copy;
    };
    const parameter = (name, { label = name, default: defaultValue, min = -100000, max = 100000, step = 1 } = {}) => {
        if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(name) || parameters.some(item => item.name === name)) throw new Error('Parameter names must be unique English identifiers.');
        if (parameters.length >= 30 || ![defaultValue, min, max, step].every(Number.isFinite) || min > max || step <= 0) throw new Error('Invalid parameter definition (maximum 30).');
        const value = Object.hasOwn(parameterValues, name) ? parameterValues[name] : defaultValue;
        if (!Number.isFinite(value) || value < min || value > max) throw new Error(`Parameter ${name} must be between ${min} and ${max}.`);
        parameters.push({ name, label: String(label).slice(0, 100), default: defaultValue, min, max, step, value });
        return value;
    };
    const connect = (primary, secondary, { type = 'butt', label = '', ...parameters } = {}) => {
        const first = reference(primary, 'instance'), second = reference(secondary, 'instance');
        if (first === second) throw new Error('A connection requires two different instances.');
        if (connections.length >= 30) throw new Error('Limit: 30 connections per script.');
        const connection = { primary_ref: first, secondary_ref: second, type, label, parameters, generate_machining: false };
        connections.push(connection);
        return Object.freeze({ generateMachining() { connection.generate_machining = true; return this; } });
    };
    const log = (...values) => {
        if (logs.length < 50) logs.push(values.map(String).join(' ').slice(0, 500));
    };
    const result = () => {
        for (const entry of handles.values()) {
            if (entry.kind !== 'instance') continue;
            const pose = world(entry);
            entry.command.data = transform({ position: pose.position.map(v => Math.round(v * 1e6) / 1e6), ...math.decompose(pose.matrix) });
        }
        return { commands, connections, parameters, logs };
    };
    return { part, group, add, log, repeat, mirror, parameter, connect, result };
}

export const starterScript = `// Millimeters, degrees. X/Y = floor, Z = height.
// Part: length along X, width along Y, thickness along Z.
const benchLength = 800;
const benchWidth = 300;
const benchHeight = 450;
const boardThickness = 30;

const bench = group("Bench");
const seat = part("Seat", {
    length: benchLength, width: benchWidth,
    thickness: boardThickness, material: "Pine"
});
bench.add(seat, { position: [0, 0, benchHeight - boardThickness / 2] });

const legHeight = benchHeight - boardThickness;
const leg = part("Leg", {
    length: legHeight, width: 45, thickness: 45, material: "Pine"
});
for (const x of [-320, 320]) {
    for (const y of [-100, 100]) {
        bench.add(leg, { position: [x, y, legHeight / 2], rotation: [0, 90, 0] });
    }
}
log("Bench:", benchLength, "x", benchWidth, "x", benchHeight);
// Layout example only; joinery is not generated automatically.
`;

export const machiningScript = `const panel = part("Drilled panel", {
    length: 400, width: 200, thickness: 24, material: "Oak"
});
panel.operation("drill", {
    face: "top", center_u: 100, center_v: 60,
    diameter: 10, depth: 24, through: true
});
add(panel, { position: [0, 0, 12] });
`;

export const joineryScript = `const beamLength = parameter("beamLength", {
    label: "Длина бруска", default: 400, min: 100, max: 1200, step: 10
});
const tenonLength = parameter("tenonLength", {
    label: "Длина шипа", default: 20, min: 10, max: 40
});
const frame = group("Joined beams");
const beam = part("Beam", { length: beamLength, width: 60, thickness: 40, material: "Pine" });
const first = frame.add(beam);
const second = frame.add(beam);
second.alignTo(first, { face: "start", targetFace: "end", gap: -tenonLength });
connect(first, second, {
    type: "mortise_tenon", primary_face: "end", secondary_face: "start",
    tenon_width: 30, tenon_thickness: 16, tenon_length: tenonLength
}).generateMachining();
frame.rotate([0, 0, 15]).move([0, 0, 100]);
// Copy the whole assembly, including its internal connection.
repeat(frame, { count: 1, step: [0, 180, 0] });
log("Two assemblies with generated tenons and mortises");
`;
