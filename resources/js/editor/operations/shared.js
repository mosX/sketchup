export const operationId = () => window.crypto?.randomUUID?.() ?? `operation-${Date.now()}-${Math.random().toString(16).slice(2)}`;

export const surfaceDimensions = (face, dimensions) => {
    if (!dimensions) return { u: 0, v: 0, depth: 0 };

    if (['left', 'right'].includes(face)) {
        return { u: Number(dimensions.length), v: Number(dimensions.thickness), depth: Number(dimensions.width) };
    }

    if (['start', 'end'].includes(face)) {
        return { u: Number(dimensions.width), v: Number(dimensions.thickness), depth: Number(dimensions.length) };
    }

    return { u: Number(dimensions.length), v: Number(dimensions.width), depth: Number(dimensions.thickness) };
};

export const faceLabel = (face) => ({
    top: 'Верхняя пласть',
    bottom: 'Нижняя пласть',
    left: 'Левая кромка',
    right: 'Правая кромка',
    start: 'Начальный торец',
    end: 'Конечный торец',
}[face] ?? 'Поверхность');
