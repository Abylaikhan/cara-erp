// M17 «Сотрудники» — what the page of legacy keeps in its own code (L21647–21676, L27867): the kinds of activity («цеха») of an
// employee. The staffing table (departments and positions) is a directory on the server since № 76 (ADR-0051).

/** A department of the staffing table (legacy staffDepts; a directory since № 76 — ADR-0051). */
export interface StaffDept {
    id: number;
    name: string;
    icon: string;
    lock_version: number;
}
/** A position of the staffing table (legacy staffPositions). */
export interface StaffPosition {
    id: number;
    deptId: number;
    title: string;
    grade: string;
    salaryMin: number;
    salaryMax: number;
    slots: number;
    wageType: 'salary' | 'piecework';
    lock_version: number;
}

export const WORKSHOPS = [
    { id: 'act_chair', name: 'Стул', icon: '🪑' },
    { id: 'act_table', name: 'Стол', icon: '🪞' },
    { id: 'act_bed', name: 'Кровать', icon: '🛏' },
    { id: 'act_soft', name: 'Мягкая мебель', icon: '🛋' },
    { id: 'act_cabinet', name: 'Корпусная мебель', icon: '🗄' },
];

export interface EmployeeChangeRow {
    date: string;
    type: string;
    base_salary: number | null;
    deduction_meal: number | null;
    opv: number | null;
    osms: number | null;
    so: number | null;
    comment: string | null;
}
export interface Employee {
    id: string;
    name: string;
    pos: string;
    type: string;
    ws: string;
    salary: number;
    phone: string;
    iin: string;
    hired: string;
    comment: string;
    base_salary: number;
    deduction_meal: number;
    deduction_opv_pct: number | null;
    deduction_osms_pct: number | null;
    deduction_so_pct: number | null;
    status: string;
    fired_date: string | null;
    changes: EmployeeChangeRow[];
    // «Комиссии салонов»: only the roles that see that tab get them (ADR-0002, п. 111)
    commissionPct?: number;
    commissionMethod?: string;
    salaryHistory?: { from: string; salary: number; pct: number; note: string | null }[];
}

/** Legacy initials: the first letters of the first two words. */
export const initials = (name: string): string =>
    name
        .split(' ')
        .map((x) => x[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

/** Legacy tariffData (L17470): the piece rates by operation (₸ per piece), kept in the page's own code; the production stages
 * take their names from it. */
export const TARIFFS: { op: string; grade: number; rate: number; workshop: string; stage: string }[] = [
    { op: 'Раскрой ЛДСП/МДФ', grade: 4, rate: 170, workshop: 'cut', stage: 'machine' },
    { op: 'Кромление деталей', grade: 3, rate: 95, workshop: 'cut', stage: 'machine' },
    { op: 'Фрезеровка фасадов', grade: 5, rate: 210, workshop: 'cut', stage: 'machine' },
    { op: 'Сверление/присадка', grade: 3, rate: 110, workshop: 'cut', stage: 'machine' },
    { op: 'Раскрой ткани/кожи', grade: 3, rate: 130, workshop: 'cut', stage: 'machine' },
    { op: 'Шлифовка деталей', grade: 3, rate: 120, workshop: 'grind', stage: 'prep' },
    { op: 'Шлифовка финишная', grade: 4, rate: 150, workshop: 'grind', stage: 'finish' },
    { op: 'Грунтовка', grade: 3, rate: 130, workshop: 'paint', stage: 'prep' },
    { op: 'Окраска/покраска', grade: 4, rate: 180, workshop: 'paint', stage: 'cover' },
    { op: 'Лакировка', grade: 4, rate: 170, workshop: 'paint', stage: 'finish' },
    { op: 'Сборка каркаса', grade: 4, rate: 200, workshop: 'assemble', stage: 'machine' },
    { op: 'Сборка корпуса', grade: 4, rate: 210, workshop: 'assemble', stage: 'machine' },
    { op: 'Сборка ящиков', grade: 3, rate: 150, workshop: 'assemble', stage: 'machine' },
    { op: 'Установка фасадов/фурнитуры', grade: 4, rate: 160, workshop: 'assemble', stage: 'finish' },
    { op: 'Установка механизмов', grade: 4, rate: 190, workshop: 'assemble', stage: 'finish' },
    { op: 'Финальная сборка', grade: 4, rate: 200, workshop: 'assemble', stage: 'finish' },
    { op: 'Настил поролона/синтепона', grade: 3, rate: 160, workshop: 'upholstery', stage: 'prep' },
    { op: 'Обивка сиденья/спинки', grade: 4, rate: 180, workshop: 'upholstery', stage: 'cover' },
    { op: 'Пошив и крепление чехлов', grade: 5, rate: 280, workshop: 'upholstery', stage: 'cover' },
    { op: 'Обивка изголовья', grade: 4, rate: 200, workshop: 'upholstery', stage: 'cover' },
    { op: 'Шпонирование поверхностей', grade: 5, rate: 250, workshop: 'veneer', stage: 'cover' },
    { op: 'Прессование шпона', grade: 4, rate: 180, workshop: 'veneer', stage: 'machine' },
    { op: 'Упаковка и маркировка', grade: 2, rate: 80, workshop: 'pack', stage: 'pack' },
    { op: 'Комплектация заказа', grade: 3, rate: 100, workshop: 'pack', stage: 'pack' },
];
