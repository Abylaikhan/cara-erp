// Excel exports (ADR-0044): the page sends the rows it shows — as legacy hands them to SheetJS aoa_to_sheet — and the server
// builds the file (POST /export/xlsx, only for a page the user may open). The file name is the one legacy gives.
export type Cell = string | number | boolean | null;
export interface Sheet {
    name: string;
    rows: Cell[][];
    /** Column widths in characters (legacy `!cols` wch). */
    cols?: (number | null)[];
}

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || '');

export async function downloadXlsx(page: string, filename: string, sheets: Sheet[]): Promise<void> {
    let response: Response;
    try {
        response = await fetch('/export/xlsx', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ page, filename, sheets }),
        });
    } catch {
        window.alert('Нет связи с сервером.\n\nФайл не сформирован. Проверьте соединение и повторите.');
        return;
    }
    if (!response.ok) {
        window.alert(`Файл не сформирован (HTTP ${response.status}).`);
        return;
    }
    const url = URL.createObjectURL(await response.blob());
    const a = document.createElement('a');
    a.href = url;
    a.download = filename.endsWith('.xlsx') ? filename : filename + '.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Legacy `new Date().toISOString().slice(0, 10)`: the date in UTC, as legacy names its files. */
export const isoToday = (): string => new Date().toISOString().slice(0, 10);

/** The rows of a table body as legacy reads them for its exports: the text of every cell, trimmed. */
export const tableCells = (selector: string): string[][] =>
    [...document.querySelectorAll(`${selector} tr`)].map((tr) => [...tr.querySelectorAll('td')].map((td) => (td.textContent || '').trim()));

/** Legacy `new Date().toLocaleDateString('ru-RU').replace(/\./g, '-')`: today as dd-mm-yyyy. */
export const ruToday = (): string => new Date().toLocaleDateString('ru-RU').replace(/\./g, '-');
