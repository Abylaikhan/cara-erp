// M02 «Производство» — the stages recorded on the production worksheet as the tabs «Загрузка цехов» and «Журнал работ» read
// them (legacy prodProgress), and the piece workshops of legacy (PIECE_WORKSHOPS, L17442).
export interface WorkStage {
    stage: string;
    workshop: string;
    posted: boolean;
    postedDate: string;
    assignees: { name: string; ktu: number }[];
}
export interface WorkItem {
    num: string;
    pos: number;
    name: string;
    category: string | null;
    date: string | null;
    deadline: string | null;
    stages: WorkStage[];
}
export interface WorkData {
    items: WorkItem[];
    execs: string[];
}

export const PIECE_WORKSHOPS = [
    { id: 'paint', name: 'Малярка', icon: '🎨' },
    { id: 'cut', name: 'Столярка/Раскройный', icon: '✂️' },
    { id: 'grind', name: 'Шлифовка', icon: '🔧' },
    { id: 'assemble', name: 'Сборка', icon: '🔩' },
    { id: 'upholstery', name: 'Обивочный цех', icon: '🧵' },
    { id: 'veneer', name: 'Шпонирование', icon: '🪵' },
    { id: 'pack', name: 'Упаковка', icon: '📦' },
];
