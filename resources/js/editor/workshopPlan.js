import { operationSummary } from './operations/catalog.js';
import { faceLabel } from './operations/shared.js';

export const partWorkshopCode = (id) => `Д-${String(id).padStart(3, '0')}`;

const point = (u, v) => `U ${u} / V ${v} мм`;

export const workshopOperationDetails = (operation, thickness) => {
    if (operation.type === 'cross_cut' || operation.type === 'rip_cut') {
        const depth = operation.cut_depth >= Number(thickness) - 0.01
            ? (operation.type === 'cross_cut'
                ? (operation.keep_side === 'end' ? 'сквозной, оставить часть к конечному торцу' : 'сквозной, оставить часть к начальному торцу')
                : (operation.keep_side === 'reference' ? 'сквозной, оставить часть у базы' : 'сквозной, оставить часть дальше от базы'))
            : `глубина ${operation.cut_depth} мм, ${operation.cut_direction === 'bottom_up' ? 'с нижней пласти' : 'с верхней пласти'}`;
        const position = operation.type === 'cross_cut'
            ? `${operation.position} мм от начального торца · угол в плане ${operation.miter_angle}°`
            : `${operation.reference_side === 'right' ? 'правая' : 'левая'} кромка · отступы ${operation.start_offset} → ${operation.end_offset} мм`;

        return `${position} · наклон диска ${operation.bevel_angle}° · пропил ${operation.kerf} мм · ${depth}`;
    }

    if (operation.type === 'drill') return `${operationSummary(operation)} · центр ${point(operation.center_u, operation.center_v)}`;
    if (operation.type === 'groove') return `${faceLabel(operation.face)} · центр ${point(operation.center_u, operation.center_v)} · ${operationSummary(operation)}`;
    if (operation.type === 'plunge_route') {
        const angle = operation.cutter_angle ? ` · угол фрезы ${operation.cutter_angle}°` : '';
        return `${faceLabel(operation.face)} · начало ${point(operation.start_u, operation.start_v)} · ${operationSummary(operation)}${angle}`;
    }

    return operationSummary(operation);
};
