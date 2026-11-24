const physicalKeys = { KeyG: 'g', KeyR: 'r', KeyS: 's', KeyX: 'x', KeyY: 'y', KeyZ: 'z', KeyD: 'd' };

export const assemblyShortcutKey = event => physicalKeys[event.code] ?? event.key.toLowerCase();

export const translatedPosition = (position, axis, delta) => ({
    ...position,
    [axis]: Math.round((Number(position[axis]) + delta) * 100) / 100,
});

export const groupMoveLimit = 100;
