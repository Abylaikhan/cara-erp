// A line of the salon payroll (legacy salon_payroll) as the page receives it.
export interface PayrollLine {
    employee: string;
    year: number;
    month: number;
    salary: number;
    commission: number;
    bonus: number;
    vacation: number;
    advance: number;
    total: number;
    remainder: number;
}

export const MONTH_NAMES = ['', 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

// Legacy initDynamicPeriods (L13573): the years of the selects go from the current year down to 2024.
export const yearsFrom = (today: string): string[] => {
    const cy = +today.slice(0, 4);
    return Array.from({ length: Math.max(cy - 2024 + 1, 1) }, (_, i) => String(cy - i));
};
