// M17 «Сотрудники» — what the page of legacy keeps in its own code (L21647–21676, L27867): the staffing table (departments and
// positions with their salary range and number of posts) and the kinds of activity («цеха») of an employee.

export const STAFF_DEPTS = [
    { id: 'dept_prod', name: 'Производство', icon: '🏭' },
    { id: 'dept_aup', name: 'Административно-управленческий персонал (АУП)', icon: '🏢' },
    { id: 'dept_sales', name: 'Отдел продаж', icon: '📊' },
    { id: 'dept_store', name: 'Склад', icon: '📦' },
];

export interface StaffPosition {
    id: number;
    deptId: string;
    title: string;
    grade: string;
    salaryMin: number;
    salaryMax: number;
    slots: number;
    wageType: 'salary' | 'piecework';
}
export const STAFF_POSITIONS: StaffPosition[] = [
    { id: 1, deptId: 'dept_prod', title: 'Директор производства', grade: 'АУП', salaryMin: 300000, salaryMax: 400000, slots: 1, wageType: 'salary' },
    { id: 2, deptId: 'dept_prod', title: 'Начальник цеха', grade: 'АУП', salaryMin: 200000, salaryMax: 250000, slots: 1, wageType: 'salary' },
    { id: 3, deptId: 'dept_prod', title: 'Столяр 5 разряд', grade: '5р', salaryMin: 0, salaryMax: 0, slots: 2, wageType: 'piecework' },
    { id: 4, deptId: 'dept_prod', title: 'Столяр 4 разряд', grade: '4р', salaryMin: 0, salaryMax: 0, slots: 3, wageType: 'piecework' },
    { id: 5, deptId: 'dept_prod', title: 'Сборщик 5 разряд', grade: '5р', salaryMin: 0, salaryMax: 0, slots: 2, wageType: 'piecework' },
    { id: 6, deptId: 'dept_prod', title: 'Сборщик 4 разряд', grade: '4р', salaryMin: 0, salaryMax: 0, slots: 2, wageType: 'piecework' },
    { id: 7, deptId: 'dept_prod', title: 'Обивщик 5 разряд', grade: '5р', salaryMin: 0, salaryMax: 0, slots: 1, wageType: 'piecework' },
    { id: 8, deptId: 'dept_prod', title: 'Маляр 4 разряд', grade: '4р', salaryMin: 0, salaryMax: 0, slots: 1, wageType: 'piecework' },
    { id: 9, deptId: 'dept_aup', title: 'Директор', grade: 'АУП', salaryMin: 350000, salaryMax: 350000, slots: 1, wageType: 'salary' },
    { id: 10, deptId: 'dept_aup', title: 'Главный бухгалтер', grade: 'АУП', salaryMin: 180000, salaryMax: 220000, slots: 1, wageType: 'salary' },
    { id: 11, deptId: 'dept_aup', title: 'Бухгалтер', grade: 'АУП', salaryMin: 130000, salaryMax: 160000, slots: 1, wageType: 'salary' },
    { id: 12, deptId: 'dept_sales', title: 'Руководитель продаж', grade: 'АУП', salaryMin: 200000, salaryMax: 250000, slots: 1, wageType: 'salary' },
    { id: 13, deptId: 'dept_sales', title: 'Менеджер по продажам', grade: 'ИТР', salaryMin: 120000, salaryMax: 150000, slots: 2, wageType: 'salary' },
    { id: 14, deptId: 'dept_store', title: 'Кладовщик', grade: 'ИТР', salaryMin: 100000, salaryMax: 130000, slots: 1, wageType: 'salary' },
];

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
}

/** Legacy initials: the first letters of the first two words. */
export const initials = (name: string): string =>
    name
        .split(' ')
        .map((x) => x[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
