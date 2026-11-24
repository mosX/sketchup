export const materialKindLabels = { board: 'Доска или брус', sheet: 'Лист', consumable: 'Расходник' };

const money = new Intl.NumberFormat('ru-UA', { style: 'currency', currency: 'UAH' });

export const materialCost = (minorUnits) => minorUnits == null ? 'Цена не указана' : money.format(minorUnits / 100);

export const materialSize = (material) => material.kind === 'consumable'
    ? `${Number(material.pack_quantity)} ${material.unit} в упаковке`
    : `${Number(material.width_mm)} × ${Number(material.thickness_mm)} × ${Number(material.length_mm)} мм`;
