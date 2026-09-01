const cloneTransform = (instance) => ({
    position: { ...instance.position },
    rotation: { ...instance.rotation },
    mirrored: instance.mirrored,
    assemblyGroupId: instance.assembly_group_id ?? null,
});

export const duplicateBlueprint = (instance) => cloneTransform(instance);

export const mirroredBlueprint = (instance) => ({
    ...cloneTransform(instance),
    mirrored: !instance.mirrored,
});

export const linearArrayBlueprints = (instance, { axis, copies, spacing }) => {
    const safeCopies = Math.min(Math.max(Math.trunc(Number(copies)), 1), 20);
    const safeSpacing = Math.min(Math.max(Number(spacing), -100000), 100000);

    return Array.from({ length: safeCopies }, (_, index) => {
        const blueprint = cloneTransform(instance);
        blueprint.position[axis] += safeSpacing * (index + 1);

        return blueprint;
    });
};

export const instanceBlueprintPayload = (blueprint) => ({
    quantity: 1,
    position_x: blueprint.position.x,
    position_y: blueprint.position.y,
    position_z: blueprint.position.z,
    rotation_x: blueprint.rotation.x,
    rotation_y: blueprint.rotation.y,
    rotation_z: blueprint.rotation.z,
    mirrored: blueprint.mirrored,
    assembly_group_id: blueprint.assemblyGroupId ?? null,
});
