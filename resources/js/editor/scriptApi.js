import { createScriptTransforms } from './scriptTransforms.js';

// Serialize this factory together with createScriptTransforms into the worker.
export function createScriptApi(parameterValues = {}) {
    const math = createScriptTransforms();
    const commands = [];
    const connections = [];
    const fasteners = [];
    const assemblySteps = [];
    const stockAssignments = new Map();
    const supplies = new Map();
    let defaultMaterialId;
    let renderSettings;
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
            stock(materialId) { stockAssignments.set(command.temporary_id, catalogId(materialId)); return handle; },
            drill(parameters = {}) { const s = surface(parameters); return handle.operation('drill', { face: s.face, center_u: s.u / 2, center_v: s.v / 2, diameter: Math.min(8, s.u, s.v), depth: parameters.through ? s.depth : Math.min(18, s.depth), through: false, ...parameters }); },
            groove(parameters = {}) { const s = surface(parameters); return handle.operation('groove', { face: s.face, center_u: s.u / 2, center_v: s.v / 2, path_angle: 0, groove_length: s.u, width: Math.min(8, s.v), depth: Math.min(6, s.depth), blade_diameter: 190, ...parameters }); },
            roundover(parameters = {}) { return handle.operation('edge_roundover', { edge: 'top_left', radius: Math.min(4, command.data.width / 2, command.data.thickness / 2), ...parameters }); },
            rabbet(parameters) { return handle.operation('rabbet', parameters); },
            pocket(parameters) { return handle.operation('pocket', parameters); },
            chamfer(parameters) { return handle.operation('edge_chamfer', parameters); },
            jigsawCut(parameters) { return handle.operation('jigsaw_cut', parameters); },
            drillRow(parameters) { return handle.operation('drill_row', parameters); },
            pocketScrew(parameters) { return handle.operation('pocket_screw', parameters); },
            curveRoute(parameters) { return handle.operation('curve_route', parameters); },
            taper(parameters) { return handle.operation('taper', parameters); },
            turn(parameters) { return handle.operation('turning', parameters); },
            crossCut(parameters = {}) { return handle.operation('cross_cut', { position: command.data.length * 0.9, miter_angle: 0, bevel_angle: 0, kerf: 3.2, cut_depth: command.data.thickness, cut_direction: 'top_down', keep_side: 'start', ...parameters }); },
            ripCut(parameters = {}) { return handle.operation('rip_cut', { reference_side: 'left', start_offset: command.data.width / 4, end_offset: command.data.width / 4, bevel_angle: 0, kerf: 3.2, cut_depth: command.data.thickness, cut_direction: 'top_down', keep_side: 'opposite', ...parameters }); },
            plungeRoute(parameters = {}) { const s = surface(parameters); return handle.operation('plunge_route', { face: s.face, route_mode: 'point', start_u: s.u / 2, start_v: s.v / 2, path_angle: 0, travel_length: 0, cutter_profile: 'straight', cutter_angle: 0, cutter_diameter: Math.min(8, s.u, s.v), depth: Math.min(6, s.depth), ...parameters }); },
            operation(type, parameters = {}) {
                if (!['cross_cut', 'rip_cut', 'groove', 'edge_roundover', 'plunge_route', 'drill', 'rabbet', 'pocket', 'edge_chamfer', 'jigsaw_cut',
                    'drill_row', 'pocket_screw', 'curve_route', 'taper', 'turning'].includes(type)) throw new Error(`Unknown operation: ${type}`);
                if (command.data.operations.length >= 50) throw new Error('Limit: 50 operations per part.');
                if (++operationCount > 100) throw new Error('Limit: 100 machining operations per script.');
                command.data.operations.push({ ...parameters, id: `operation_${operationCount}`, type, status: 'applied' });
                return handle;
            },
        });
        handles.set(handle, { kind: 'part', id: command.temporary_id, command });
        return handle;
    };
    const group = (name, parent = null, options = {}) => {
        const command = append('create_group', { ...options, name }, parent ? { parent_group_ref: reference(parent, 'group') } : {});
        const entry = { kind: 'group', id: command.temporary_id, command, parent: parent ? entryFor(parent, 'group') : null, position: [0, 0, 0], matrix: math.identity() };
        const handle = Object.freeze({ add: (definition, options = {}) => add(definition, { ...options, group: handle }), ...movable(entry) });
        entry.handle = handle;
        handles.set(handle, entry);
        return handle;
    };
    const clone = (original, parent = original.parent, references = new Map()) => {
        if (!['instance', 'group'].includes(original.kind)) throw new Error('Copy an instance or group, or repeat a part.');
        let copy;
        if (original.kind === 'instance') {
            const definition = [...handles.keys()].find(handle => handles.get(handle).command === original.definition);
            copy = add(definition, parent ? { group: parent.handle } : {});
        } else {
            const children = [...handles.values()].filter(entry => entry.parent === original);
            copy = group(`${original.command.data.name} copy`, parent?.handle, original.command.data);
            children.forEach(child => clone(child, entryFor(copy), references));
        }
        const entry = entryFor(copy);
        entry.position = [...original.position];
        entry.matrix = original.matrix.map(row => [...row]);
        references.set(original.id, entry.id);
        return copy;
    };
    const cloneAssembly = (original) => {
        const references = new Map();
        const copy = clone(original, original.parent, references);
        for (const connection of [...connections]) {
            if (!references.has(connection.primary_ref) || !references.has(connection.secondary_ref)) continue;
            if (connections.length >= 30) throw new Error('Limit: 30 connections per script.');
            connections.push({ ...connection, parameters: { ...connection.parameters }, primary_ref: references.get(connection.primary_ref), secondary_ref: references.get(connection.secondary_ref) });
        }
        for (const row of [...fasteners]) {
            const endpoints = [row.source_ref, row.target_ref, ...(row.row_target_refs ?? [])].filter(Boolean);
            if (!endpoints.every(ref => references.has(ref))) continue;
            const mapped = {
                ...row,
                ref: nextFastenerRef(),
                source_ref: references.get(row.source_ref),
                placement: { ...row.placement },
            };
            if (row.target_ref) mapped.target_ref = references.get(row.target_ref);
            if (row.row_target_refs) mapped.row_target_refs = row.row_target_refs.map(ref => ref === null ? null : references.get(ref));
            fasteners.push(mapped);
        }
        return copy;
    };
    const repeat = (target, { count, step = [0, 0, 0], position = [0, 0, 0], rotation = [0, 0, 0], group: parent = null } = {}) => {
        if (!Number.isInteger(count) || count < 1 || count > 98) throw new Error('Repeat count must be between 1 and 98.');
        math.vector(step);
        const original = entryFor(target);
        return Array.from({ length: count }, (_, index) => {
            if (original.kind === 'part') return add(target, { position: math.add(math.vector(position), math.scale(step, index)), rotation, ...(parent ? { group: parent } : {}) });
            const copy = cloneAssembly(original);
            copy.move(math.scale(step, index + 1));
            return copy;
        });
    };
    const mirror = (target, { axis = 'x', origin = [0, 0, 0] } = {}) => {
        const original = entryFor(target);
        const pose = world(original);
        const matrix = math.reflection(axis);
        const pivot = math.vector(origin);
        const copy = cloneAssembly(original);
        setWorld(entryFor(copy), { matrix: math.multiply(matrix, pose.matrix), position: math.add(pivot, math.apply(matrix, math.add(pose.position, math.scale(pivot, -1)))) });
        return copy;
    };
    const parameter = (name, { label = name, default: defaultValue, min = -100000, max = 100000, step = 1, kind = 'number', unit, diameterParameter, lengthParameter } = {}) => {
        if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(name) || parameters.some(item => item.name === name)) throw new Error('Parameter names must be unique English identifiers.');
        if (parameters.length >= 30 || ![defaultValue, min, max, step].every(Number.isFinite) || min > max || step <= 0) throw new Error('Invalid parameter definition (maximum 30).');
        const value = Object.hasOwn(parameterValues, name) ? parameterValues[name] : defaultValue;
        if (!Number.isFinite(value) || value < min || value > max) throw new Error(`Parameter ${name} must be between ${min} and ${max}.`);
        if (!['number', 'screw', 'hidden'].includes(kind)) throw new Error('Unknown parameter kind.');
        parameters.push({
            name, label: String(label).slice(0, 100), default: defaultValue, min, max, step, value,
            ...(kind !== 'number' ? { kind } : {}),
            ...(unit ? { unit: String(unit).slice(0, 12) } : {}),
            ...(kind === 'screw' ? { diameterParameter, lengthParameter } : {}),
        });
        return value;
    };
    const connect = (primary, secondary, { type = 'butt', label = '', note = null, is_verified = false, ...parameters } = {}) => {
        const first = reference(primary, 'instance'), second = reference(secondary, 'instance');
        if (first === second) throw new Error('A connection requires two different instances.');
        if (connections.length >= 30) throw new Error('Limit: 30 connections per script.');
        const connection = { primary_ref: first, secondary_ref: second, type, label, ...(note === null ? {} : { note }), ...(is_verified ? { is_verified } : {}), parameters, generate_machining: false };
        connections.push(connection);
        return Object.freeze({ generateMachining() { connection.generate_machining = true; return this; } });
    };
    const catalogId = (id) => {
        if (!Number.isSafeInteger(id) || id < 1) throw new Error('Expected a positive catalog material ID.');
        return id;
    };
    const defaultStock = (id) => { defaultMaterialId = id === null ? null : catalogId(id); };
    const supply = (id, quantity) => {
        catalogId(id);
        if (!Number.isFinite(quantity) || quantity <= 0) throw new Error('Supply quantity must be positive.');
        supplies.set(id, Math.round(((supplies.get(id) ?? 0) + quantity) * 1000) / 1000);
    };
    const nextFastenerRef = () => {
        if (fasteners.length >= 100) throw new Error('Limit: 100 fastener placements or rows per script.');
        return `fastener_${fasteners.length + 1}`;
    };
    const fastener = (source, { target = null, rowTargets, face = 'top', sink = 0, ...placement } = {}) => {
        const ref = nextFastenerRef();
        const data = {
            ref, source_ref: reference(source, 'instance'),
            placement: { face, sink, ...placement },
            ...(target ? { target_ref: reference(target, 'instance') } : {}),
            ...(rowTargets ? { row_target_refs: rowTargets.map(item => item === null ? null : reference(item, 'instance')) } : {}),
        };
        fasteners.push(data);
        const handle = Object.freeze({
            id: ref,
            prepareDrilling(settings) { data.drilling = { ...settings }; return handle; },
        });
        handles.set(handle, { id: ref, kind: 'fastener' });
        return handle;
    };
    const instanceRefs = (items) => [...new Set(items.flatMap((item) => {
        const entry = entryFor(item);
        if (entry.kind === 'instance') return [entry.id];
        if (entry.kind !== 'group') throw new Error('Use instance or group handles in assembly steps.');
        return [...handles.values()].filter(child => {
            if (child.kind !== 'instance') return false;
            for (let parent = child.parent; parent; parent = parent.parent) {
                if (parent === entry) return true;
            }
            return false;
        }).map(child => child.id);
    }))];
    const assemblyStep = (title, { parts = [], highlight = null, fasteners: hardware = null, instruction, notes, camera } = {}) => {
        if (assemblySteps.length >= 200) throw new Error('Limit: 200 assembly steps.');
        const point = (value) => {
            const [x, y, z] = math.vector(value);
            return { x, y, z };
        };
        assemblySteps.push({
            title, instruction, notes,
            instance_refs: instanceRefs(parts),
            highlight_refs: highlight === null ? null : instanceRefs(highlight),
            fastener_refs: hardware === null ? null : hardware.map(item => reference(item, 'fastener')),
            camera: camera ? { position: point(camera.position), target: point(camera.target) } : null,
        });
    };
    const render = (options) => { renderSettings = { ...renderSettings, ...options }; };
    const log = (...values) => {
        if (logs.length < 50) logs.push(values.map(String).join(' ').slice(0, 500));
    };
    const result = () => {
        for (const entry of handles.values()) {
            if (entry.kind !== 'instance') continue;
            const pose = world(entry);
            entry.command.data = transform({ position: pose.position.map(v => Math.round(v * 1e6) / 1e6), ...math.decompose(pose.matrix) });
        }
        const materials = {
            ...(defaultMaterialId === undefined ? {} : { default_material_id: defaultMaterialId }),
            ...(stockAssignments.size ? { assignments: [...stockAssignments].map(([part_ref, material_id]) => ({ part_ref, material_id })) } : {}),
            ...(supplies.size ? { supplies: [...supplies].map(([material_id, quantity]) => ({ material_id, quantity })) } : {}),
        };
        return {
            commands, connections, parameters, logs,
            ...(fasteners.length ? { fasteners } : {}),
            ...(assemblySteps.length ? { assembly_steps: assemblySteps } : {}),
            ...(Object.keys(materials).length ? { materials } : {}),
            ...(renderSettings ? { render_settings: renderSettings } : {}),
        };
    };
    return { part, group, add, log, repeat, mirror, parameter, connect, fastener, defaultStock, supply, assemblyStep, render, result };
}

export function compileProjectScript(source, values = {}) {
    if (typeof source !== 'string' || source.length > 50000) throw new Error('Script exceeds 50,000 characters.');
    const { result, ...bindings } = createScriptApi(values);
    new Function(...Object.keys(bindings), '"use strict";\n' + source)(...Object.values(bindings));
    const compiled = result();
    if (JSON.stringify(compiled).length > 250000) throw new Error('Script output exceeds 250 KB.');
    return compiled;
}

export function compileProjectScriptPayload(source, values = {}) {
    const { parameters, logs, ...payload } = compileProjectScript(source, values);
    return {
        ...payload,
        source,
        parameter_values: Object.fromEntries(parameters.map(item => [item.name, item.value])),
    };
}

export const assemblyScript = `const wood = group("Box");
const base = wood.add(part("Base", { length: 300, width: 180, thickness: 18 }), { position: [0, 0, 9] });
const lid = wood.add(part("Lid", { length: 300, width: 180, thickness: 18 }), { position: [0, 0, 27] });
// Use your catalog IDs to assign stock and hardware:
// const screws = fastener(lid, { target: base, catalog_material_id: 12, center_u: 40, center_v: 30, count: 3, row_axis: "u", spacing: 60 });
// screws.prepareDrilling({ clearance_diameter: 4, pilot: true, pilot_diameter: 2, pilot_depth: 10 });
assemblyStep("Prepare the base", { parts: [base], notes: "Place both stock faces flat before joining." });
assemblyStep("Fit the lid", {
    parts: [lid], highlight: [base, lid], instruction: "Align the long edges.",
    camera: { position: [480, -550, 400], target: [0, 0, 20] }
    // Add fasteners: [screws] after selecting a catalog ID.
});
render({ wood: "pine", finish: "oil", light: "studio", exposure: -0.5 });`;

export const parametricBoxScript = `// Outer dimensions in mm. The lid lifts off with two locating cleats.
const length = parameter("length", { label: "Длина", default: 630, min: 250, max: 1200, unit: "мм" });
const width = parameter("width", { label: "Ширина", default: 430, min: 180, max: 800, unit: "мм" });
const height = parameter("height", { label: "Высота", default: 240, min: 120, max: 550, unit: "мм" });
const thickness = parameter("thickness", { label: "Толщина доски", default: 18, min: 14, max: 30, unit: "мм" });
const screwId = parameter("screwId", { label: "Шуруп из каталога", default: 0, min: 0, max: 100000, kind: "screw", diameterParameter: "screwDiameter", lengthParameter: "screwLength" });
const screwDiameter = parameter("screwDiameter", { default: 3.5, min: 3, max: 5, kind: "hidden" });
const screwLength = parameter("screwLength", { default: 35, min: 1, max: 1000, kind: "hidden" });

const wallHeight = height - 2 * thickness;
const innerWidth = width - 2 * thickness;
const minScrewLength = thickness + 6;
const maxScrewLength = thickness + Math.min(40, wallHeight - 10);
if (screwId && (screwLength < minScrewLength || screwLength > maxScrewLength)) {
    throw new Error("Для этих размеров нужен шуруп длиной " + minScrewLength + "–" + maxScrewLength + " мм.");
}
const box = group("Деревянный ящик");
const shell = group("Корпус", box);
const cover = group("Съёмная крышка", box);
const board = (name, length, width, depth = thickness) => part(name, { length, width, thickness: depth, material: "Сосна" });
const baseBoard = board("Дно", length, width);
const longBoard = board("Продольная стенка", length, wallHeight);
const endBoard = board("Торцевая стенка", innerWidth, wallHeight);
const base = shell.add(baseBoard, { position: [0, 0, thickness / 2] });
const wallZ = height / 2;
const front = shell.add(longBoard, { position: [0, -(width - thickness) / 2, wallZ], rotation: [90, 0, 0] });
const back = shell.add(longBoard, { position: [0, (width - thickness) / 2, wallZ], rotation: [90, 0, 0] });
const left = shell.add(endBoard, { position: [-(length - thickness) / 2, 0, wallZ], rotation: [90, 0, 90] });
const right = shell.add(endBoard, { position: [(length - thickness) / 2, 0, wallZ], rotation: [90, 0, 90] });
const lid = cover.add(board("Крышка", length, width), { position: [0, 0, height - thickness / 2] });
const cleatBoard = board("Упор крышки", innerWidth - 4, 28, 12);
const cleats = [-1, 1].map(side => cover.add(cleatBoard, {
    position: [side * (length / 2 - thickness - 16), 0, height - thickness - 6], rotation: [0, 0, 90]
}));

const round = value => Math.round(value * 100) / 100;
const clearance = round(screwDiameter + 0.5);
const edge = Math.min(30, wallHeight / 4);
const cornerScrews = [];
const bottomScrews = [];
const pilot = {
    clearance_diameter: clearance, pilot: true,
    pilot_diameter: round(screwDiameter * 0.65), pilot_depth: Math.min(36, screwLength - thickness + 1)
};
const prepare = (list, source, target, definition, face, u, v, axis, spacing, drawHoles) => {
    const placement = { face, center_u: round(u), center_v: round(v), count: 2, row_axis: axis, spacing: round(spacing) };
    if (screwId) {
        list.push(fastener(source, { ...placement, target, catalog_material_id: screwId }).prepareDrilling(pilot));
    } else if (drawHoles) {
        for (let index = 0; index < placement.count; index++) {
            definition.drill({ face, through: true, diameter: clearance,
                center_u: round(u + (axis === "u" ? index * spacing : 0)),
                center_v: round(v + (axis === "v" ? index * spacing : 0)) });
        }
    }
};
for (const [panel, face] of [[front, "top"], [back, "bottom"]]) {
    for (const [end, u] of [[left, thickness / 2], [right, length - thickness / 2]]) {
        prepare(cornerScrews, panel, end, longBoard, face, u, edge, "v", wallHeight - 2 * edge, panel === front);
        connect(panel, end, { type: "butt" });
    }
}
for (const [panel, v] of [[back, thickness / 2], [front, width - thickness / 2]]) {
    prepare(bottomScrews, base, panel, baseBoard, "bottom", length / 3, v, "u", length / 3, true);
}
for (const [panel, u] of [[left, thickness / 2], [right, length - thickness / 2]]) {
    prepare(bottomScrews, base, panel, baseBoard, "bottom", u, width / 3, "v", width / 3, true);
}

const target = [0, 0, height / 2];
assemblyStep("Соберите стенки", { parts: [front, back, left, right], fasteners: cornerScrews,
    instruction: "Торцевые стенки входят между продольными. Выровняйте верхние кромки и проверьте диагонали.",
    camera: { position: [length, -width * 1.4, height * 2], target } });
assemblyStep("Закрепите дно", { parts: [base], highlight: [base, front, back], fasteners: bottomScrews,
    instruction: "Совместите дно с наружными кромками стенок. Крепите снизу.",
    camera: { position: [length, -width * 1.5, -height * 1.25], target } });
assemblyStep("Соберите съёмную крышку", { parts: [lid, ...cleats], highlight: [lid, ...cleats],
    instruction: "Приклейте два упора к нижней стороне крышки. С каждой стороны оставьте 2 мм зазора до стенок.",
    camera: { position: [length, -width * 1.6, height * 2.5], target } });
render({ wood: "pine", finish: "raw", light: "studio" });`;

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
