// Duplicate search of «Поставщики» — legacy L17885–18020 (normHomoglyphs, normSupNameMerge, levenshteinDist, supNameSimilarity,
// findSupplierDuplicateGroups, pickBestCanonicalId), kept as in legacy, including its quirks: the legal-form pattern uses JS `\b`
// and does not fire next to Cyrillic. The server reproduces normSupNameMerge for its own checks (App\Models\Supplier).

const LATIN_CYRILLIC_HOMOGLYPHS: Record<string, string> = { a: 'а', c: 'с', e: 'е', k: 'к', m: 'м', o: 'о', p: 'р', t: 'т', x: 'х', y: 'у', r: 'р' };

export function normHomoglyphs(s: string): string {
    return s.replace(/[acekmoptxyr]/g, (ch) => LATIN_CYRILLIC_HOMOGLYPHS[ch] || ch);
}

export function normSupNameMerge(name: string | null): string {
    return normHomoglyphs((name || '').replace(/\([^)]*\)/g, '').toLowerCase())
        .replace(/["'«»“”]/g, '')
        .replace(/\b(тоо|ип|ао|пк|чп|ооо|zhk|llp|ltd|kz)\b/gi, '')
        .replace(/[^a-zа-яё0-9]+/gi, ' ')
        .trim()
        .replace(/\s+/g, ' ');
}

export function levenshteinDist(a: string, b: string): number {
    const m = a.length;
    const n = b.length;
    if (!m) return n;
    if (!n) return m;
    const dp: number[][] = [];
    for (let i = 0; i <= m; i++) dp.push(new Array<number>(n + 1).fill(0));
    for (let i = 0; i <= m; i++) dp[i][0] = i;
    for (let j = 0; j <= n; j++) dp[0][j] = j;
    for (let i = 1; i <= m; i++) {
        for (let j = 1; j <= n; j++) {
            dp[i][j] = a[i - 1] === b[j - 1] ? dp[i - 1][j - 1] : 1 + Math.min(dp[i - 1][j], dp[i][j - 1], dp[i - 1][j - 1]);
        }
    }
    return dp[m][n];
}

export function supNameSimilarity(a: string, b: string): number {
    const na = normSupNameMerge(a);
    const nb = normSupNameMerge(b);
    if (!na || !nb) return 0;
    if (na === nb) return 1;
    return 1 - levenshteinDist(na, nb) / Math.max(na.length, nb.length);
}

export interface SupplierLike {
    id: number;
    name: string;
    bin: string | null;
    category: string | null;
    contact: string | null;
    phone: string | null;
    email: string | null;
    comment: string | null;
}

export interface DuplicateGroup<T extends SupplierLike> {
    key: string;
    suppliers: T[];
    reasons: string[];
}

/** Same BIN, the same name or a similar one (≥ 80 %), joined transitively; larger groups first. */
export function findSupplierDuplicateGroups<T extends SupplierLike>(suppliers: T[], dismissed: Set<string>): DuplicateGroup<T>[] {
    const n = suppliers.length;
    const parent = Array.from({ length: n }, (_, i) => i);
    const find = (x: number): number => {
        while (parent[x] !== x) {
            parent[x] = parent[parent[x]];
            x = parent[x];
        }
        return x;
    };
    const union = (a: number, b: number): void => {
        const ra = find(a);
        const rb = find(b);
        if (ra !== rb) parent[ra] = rb;
    };
    const pairReason = new Map<string, { text: string; score: number }>();

    for (let i = 0; i < n; i++) {
        for (let j = i + 1; j < n; j++) {
            const a = suppliers[i];
            const b = suppliers[j];
            let reason: { text: string; score: number } | null = null;
            if (a.bin && b.bin && a.bin.trim() === b.bin.trim()) {
                reason = { text: 'Совпадает БИН', score: 1 };
            } else {
                const sim = supNameSimilarity(a.name, b.name);
                if (sim >= 0.99) reason = { text: 'Название совпадает', score: sim };
                else if (sim >= 0.8) reason = { text: 'Похожее название', score: sim };
            }
            if (reason) {
                union(i, j);
                pairReason.set(i + '_' + j, reason);
            }
        }
    }

    // Legacy collects the groups in an object keyed by the root index: integer keys come out in ascending order.
    const groups = new Map<number, number[]>();
    for (let i = 0; i < n; i++) {
        const r = find(i);
        groups.set(r, [...(groups.get(r) ?? []), i]);
    }

    return [...groups.entries()]
        .sort(([a], [b]) => a - b)
        .map(([, idxs]) => idxs)
        .filter((idxs) => idxs.length > 1)
        .map((idxs) => {
            const reasons = new Set<string>();
            for (let a = 0; a < idxs.length; a++) {
                for (let b = a + 1; b < idxs.length; b++) {
                    const r = pairReason.get(idxs[a] + '_' + idxs[b]) || pairReason.get(idxs[b] + '_' + idxs[a]);
                    if (r) reasons.add(r.text);
                }
            }
            const key = idxs
                .map((i) => suppliers[i].id)
                .sort()
                .join('|');
            return { key, suppliers: idxs.map((i) => suppliers[i]), reasons: [...reasons] };
        })
        .filter((g) => !dismissed.has(g.key))
        .sort((a, b) => b.suppliers.length - a.suppliers.length);
}

/** The record with a BIN and the most filled fields. */
export function pickBestCanonicalId(group: SupplierLike[]): number {
    let best = group[0];
    let bestScore = -1;
    group.forEach((s) => {
        let score = 0;
        if (s.bin) score += 10;
        (['category', 'contact', 'phone', 'email', 'comment'] as const).forEach((f) => {
            if (s[f]) score += 1;
        });
        if (score > bestScore) {
            bestScore = score;
            best = s;
        }
    });
    return best.id;
}
