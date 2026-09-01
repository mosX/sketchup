export const roundoverEdgeLabels = {
    top_left: 'Верхняя левая',
    top_right: 'Верхняя правая',
    bottom_left: 'Нижняя левая',
    bottom_right: 'Нижняя правая',
    top_start: 'Верхняя начальная',
    top_end: 'Верхняя конечная',
    bottom_start: 'Нижняя начальная',
    bottom_end: 'Нижняя конечная',
    start_left: 'Начальная левая',
    start_right: 'Начальная правая',
    end_left: 'Конечная левая',
    end_right: 'Конечная правая',
};

export const maximumRoundoverRadius = (edge, dimensions) => {
    if (!dimensions) return 0.1;

    if (['top_left', 'top_right', 'bottom_left', 'bottom_right'].includes(edge)) {
        return Math.min(Number(dimensions.width), Number(dimensions.thickness)) / 2;
    }

    if (['top_start', 'top_end', 'bottom_start', 'bottom_end'].includes(edge)) {
        return Math.min(Number(dimensions.length), Number(dimensions.thickness)) / 2;
    }

    return Math.min(Number(dimensions.length), Number(dimensions.width)) / 2;
};

export const createRoundoverOperation = (common, dimensions) => ({
    ...common,
    edge: 'top_left',
    radius: Math.min(6, Number(dimensions.width) / 2, Number(dimensions.thickness) / 2),
});
