"""Filters, sorts and their totals legacy ↔ target (ТЗ AC-08) — a part of tests/visual/legacy_compare.py with FILTERS=1.

Both sides hold the same rows (legacy_compare gives legacy exactly what the target renders). For a container of the page (a
tab, the page itself) every filter and sortable column of the accepted legacy that the registry marks as migrated
(docs/coverage/registry.csv, ADR-0029) is applied the same way on both sides — the value set and the input/change events
fired, the header clicked — and the tables of the container (tbody, tfoot) and its counters and KPIs are read:
- a select: each of its options (at most 12: the first and the last six); the option lists themselves are compared too;
- a search or text field: a few probes («а», «о», «ТОО», «1», «zzqq»); a number: 1000 and 100000; a date: the start of
  the year and the end of June; a check box: toggled;
- a sortable column: one click and a second one.
Each case starts from the state the page had and is undone afterwards: values put back; after the sorts the columns are
clicked until the container reads as before (legacy does not always update the arrow of its header).

The text is compared as a list of lines. Lines that already differ before any filter (documented differences, ADR-0002)
are explained and left out; the rest must be equal and in the same order. A mismatch that is a documented difference is
listed with its item (KNOWN). Nothing of the data is printed: a mismatch names the control, the index of the value or
probe, the part of the container (tbody, tfoot, a counter) and the number of differing lines.
FILTER_CONTAINERS=a,b limits the run to those containers.
"""
from __future__ import annotations

import csv
import os
import re
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools' / 'harness'))
import registry  # noqa: E402

PROBES_TEXT = ['а', 'о', 'ТОО', '1', 'zzqq']
PROBES_NUMBER = ['1000', '100000']
MAX_OPTIONS = 12
ARROWS = r'[▲▼↑↓⇅⬍↕⇵⬆⬇\s]+'

# Documented differences (ADR-0002): (container, control id, value of the case, 'options' for the option list or '*' for
# any value) → item.
KNOWN = {
    ('orders-all-orders', 'ord-status-filter', 'production'): 'ADR-0002, п. 44',
    ('orders-sales-journal', 'sj-year', 'options'): 'ADR-0002, п. 48',
    ('orders-report', 'rep-date-from', '*'): 'ADR-0002, п. 50',  # legacy starts the range at 05:00 (UTC midnight)
    # Order boards: month and quarter through UTC, dates of «По производству» not applied, one period for both boards.
    **{('orders-orders-kanban-prod', c, '*'): 'ADR-0002, п. 51' for c in ('ok-period', 'ok-date-from', 'ok-date-to')},
    **{('orders-orders-kanban-pay', c, '*'): 'ADR-0002, п. 51' for c in ('ok-period-pay', 'ok-date-from-pay', 'ok-date-to-pay')},
    ('mrp-orders', 'mrp-f-month', 'options'): 'ADR-0002, п. 52',
    ('sr-tab-current', 'sr-month', '*'): 'ADR-0002, п. 81',  # legacy loses the last day of the month (UTC)
    ('sr-tab-current', 'sr-year', '*'): 'ADR-0002, п. 81',
    ('money-tab-cash', 'cash-cat-filter', ''): 'ADR-0002, п. 90',
}
# Controls this comparison cannot compare, with the reason (a limit of how legacy gets its data, not a difference).
SKIP = {
    ('page-dashboard', 'dash-month-sel'): 'legacy gets the breakdown of the expenses for the current month only',
}
# Parts of a container that are not migrated (the target shows a message): not read.
EXCLUDE_PARTS: dict = {}
# Legacy state put back after every case, where undoing the control re-runs legacy logic with a documented difference:
# the order boards — the month of the page in local dates, as legacy_compare gives it (ADR-0002, п. 51).
_BOARDS_RESET = ("() => { const now = new Date(); _okDateFrom = localISODate(now.getFullYear(), now.getMonth(), 1);"
                 " _okDateTo = localISODate(now.getFullYear(), now.getMonth() + 1, 0); renderOrdersKanban(); }")
LEGACY_RESET = {'orders-orders-kanban-prod': _BOARDS_RESET, 'orders-orders-kanban-pay': _BOARDS_RESET}
# Documented differences of one column of a table: (container, part, column index) → item.
KNOWN_COLUMNS = {
    ('orders-sales-journal', 'sj-tbody', 11): 'ADR-0002, п. 49',  # «Статус» of a line whose order is in work
}
# Containers whose content is not in tables: the blocks read besides tables, counters and KPIs.
EXTRA_PARTS = {
    'orders-funnel': ['#crm-kanban-board', '#crm-funnel-summary'],
    'orders-tasks': ['#tasks-list'],
    'orders-orders-kanban-prod': ['#ok-board-production'],
    'orders-orders-kanban-pay': ['#ok-board-payment'],
    'finance-purchases': ['#pur-kpi-grid', '#pur-top-suppliers', '#pur-payments-list', '#pur-structure'],
    'page-reconcile': ['#reconcile-balance-grid'],
    'page-dashboard': ['#dash-kpi-grid'],
}
# Documented renderings of legacy, put as the target renders them before comparing (legacy side only).
LEGACY_RENDERINGS = [
    ('^₸∞ — .*$', '—'),  # the price range of a catalog selection without prices (ADR-0002, п. 11)
    ('^null$', ''),  # a missing order number of a realization (ADR-0002, п. 91)
]

# The parts of a container that are read (its tables, counters and KPIs): each line with a short hash (FNV-1a) and the index
# of its part — nothing of the text leaves the browser.
READ_JS = r"""([sel, renderings, extra, excluded]) => {
    const root = document.querySelector(sel);
    if (!root) return null;
    const out = excluded.flatMap(x => [...root.querySelectorAll(x)]);
    const found = [...root.querySelectorAll(['tbody', 'tfoot', '[id*="count"]', '[id*="kpi"]', '[id*="total"]', ...extra].join(', '))]
        .filter(e => !e.closest('select') && !e.closest('thead') && e.offsetParent !== null && !out.some(o => o.contains(e)));
    const parts = found.filter(e => !found.some(o => o !== e && o.contains(e)));  // a part inside another is read once
    const fnv = s => { let h = 0x811c9dc5; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 0x01000193) >>> 0; } return h.toString(16); };
    const norm = s => { s = s.replace(/\s+/g, ' ').trim(); renderings.forEach(([re, to]) => { s = s.replace(new RegExp(re), to); }); return s; };
    const names = parts.map(e => e.id || (e.closest('[id]') ? e.closest('[id]').id + ' ' : '') + e.tagName.toLowerCase());
    const lines = [];
    parts.forEach((e, i) => (e.innerText || '').split('\n').forEach(raw => {
        const cells = raw.split('\t').map(norm);  // a documented rendering may be one cell of a row
        const l = norm(cells.join(' '));
        if (l) lines.push([fnv(l), i, cells.map(fnv)]);
    }));
    return {names, lines};
}"""
SET_JS = r"""([sel, value]) => {
    const e = document.querySelector(sel);
    if (!e) return false;
    if (e.type === 'checkbox') e.checked = value === 'on'; else e.value = value;
    e.dispatchEvent(new Event('input', {bubbles: true}));
    e.dispatchEvent(new Event('change', {bubbles: true}));
    return true;
}"""
GET_JS = r"""(sel) => { const e = document.querySelector(sel); if (!e) return null; return e.type === 'checkbox' ? (e.checked ? 'on' : 'off') : e.value; }"""
OPTIONS_JS = r"""(sel) => { const e = document.querySelector(sel); return e ? [...e.options].map(o => [o.value, o.text.replace(/\s+/g, ' ').trim()]) : null; }"""
SET_INDEX_JS = r"""([sel, index]) => {
    const e = document.querySelector(sel);
    if (!e || index >= e.options.length) return false;
    e.selectedIndex = index;
    e.dispatchEvent(new Event('input', {bubbles: true}));
    e.dispatchEvent(new Event('change', {bubbles: true}));
    return true;
}"""
CLICK_TH_JS = r"""([root, text, arrows]) => {
    const re = new RegExp(arrows, 'g');
    const r = document.querySelector(root);
    const th = r && [...r.querySelectorAll('th')].find(t => t.offsetParent !== null && t.textContent.replace(re, ' ').trim() === text);
    if (!th) return null;
    th.click();
    return true;
}"""
HEADERS_JS = r"""(root) => { const r = document.querySelector(root); return r ? [...r.querySelectorAll('th')].filter(t => t.offsetParent !== null).map(t => t.textContent.replace(/\s+/g, ' ').trim()) : []; }"""


def bare(text: str) -> str:
    return re.sub(ARROWS, ' ', text or '').strip()


class Filters:
    """The cases of each container (from the inventory and the registry) and the readings of both sides."""

    def __init__(self, inventory: Dict[str, Any], blob: Path):
        self.inv = inventory
        self.ranges = registry.element_ranges(blob)
        with open(ROOT / 'docs/coverage/registry.csv', newline='', encoding='utf-8') as fh:
            self.migrated = {r['element'] for r in csv.DictReader(fh) if r['kind'] == 'control' and r['status'] == 'IMPLEMENTED'}
        self.only = {x for x in os.environ.get('FILTER_CONTAINERS', '').split(',') if x}
        self.readings: Dict[str, Dict[str, Any]] = {'target': {}, 'legacy': {}}
        self.failed = 0

    def controls(self, container: str, exclude: Tuple[str, ...] = ()) -> List[Dict[str, Any]]:
        a, b = self.ranges[container]
        skip = [self.ranges[x] for x in exclude if x in self.ranges]
        out = []
        for c in self.inv['controls']:
            if not a <= c['line'] <= b or any(x <= c['line'] <= y for x, y in skip):
                continue
            element = f"{c['group']}|{c['tag']}|{c['id']}" if c.get('id') else f"{c['group']}|L{c['line']}"
            if element not in self.migrated and element + '#1' not in self.migrated:
                continue
            if c['tag'] in ('select', 'input') and c.get('id') and c.get('calls') and c.get('type') not in ('file', 'radio'):
                out.append(c)
            elif c['tag'] == 'th' and any('sort' in x.lower() for x in c.get('calls', [])):
                out.append(c)
        return out

    # ---------------------------------------------------------------------------------------------- one side
    def run(self, page, side: str, container: str, exclude: Tuple[str, ...] = (), settle: int = 250) -> None:
        if self.only and container not in self.only:
            return
        sel = '#' + container

        renderings = [list(r) for r in LEGACY_RENDERINGS] if side == 'legacy' else []

        def read() -> Optional[Dict[str, Any]]:
            page.wait_for_load_state('networkidle')
            page.wait_for_timeout(settle)
            return page.evaluate(READ_JS, [sel, renderings, EXTRA_PARTS.get(container, []), EXCLUDE_PARTS.get(container, [])])


        base = read()
        out: Dict[str, Any] = {'base': base, 'cases': {}, 'values': {}, 'options': {}, 'notes': []}
        controls = self.controls(container, exclude)
        reset = LEGACY_RESET.get(container) if side == 'legacy' else None
        for c in [c for c in controls if c['tag'] != 'th']:
            if (container, c['id']) in SKIP:
                out['skipped'] = out.get('skipped', []) + [f"{c['id']}: {SKIP[(container, c['id'])]}"]
                continue
            css = '#' + c['id']
            original = page.evaluate(GET_JS, css)
            if original is None:
                out['cases'][f"{c['id']}: no such control"] = None
                continue
            if c['tag'] == 'select':
                options = page.evaluate(OPTIONS_JS, css) or []
                out['options'][c['id']] = [t for _, t in options]
                # Chosen by position: the two sides may keep a value in another form (the report names the case by the
                # target's value).
                chosen = [i for i, (v, _) in enumerate(options) if v != original]
                if len(chosen) > MAX_OPTIONS:
                    chosen = chosen[:MAX_OPTIONS // 2] + chosen[-MAX_OPTIONS // 2:]
                for i in chosen:
                    label = f"{c['id']}=option {i + 1}"
                    page.evaluate(SET_INDEX_JS, [css, i])
                    out['cases'][label] = read()
                    out['values'][label] = (c['id'], options[i][0])
                    page.evaluate(SET_JS, [css, original])
                    if reset:
                        page.evaluate(reset)
                read()
                continue
            elif c.get('type') == 'checkbox':
                cases = [(f"{c['id']}=toggled", 'off' if original == 'on' else 'on')]
            elif c.get('type') == 'number':
                cases = [(f"{c['id']}={v}", v) for v in PROBES_NUMBER]
            elif c.get('type') == 'date':
                cases = [(f"{c['id']}=year start", '2026-01-01'), (f"{c['id']}=end of June", '2026-06-30')]
            else:
                cases = [(f"{c['id']}=probe {i + 1}", v) for i, v in enumerate(PROBES_TEXT)]
            for label, value in cases:
                page.evaluate(SET_JS, [css, value])
                out['cases'][label] = read()
                out['values'][label] = (c['id'], value)
                page.evaluate(SET_JS, [css, original])
                if reset:
                    page.evaluate(reset)
            read()
        sorts = [bare(c.get('text', '')) for c in controls if c['tag'] == 'th']
        if sorts:
            headers = page.evaluate(HEADERS_JS, sel)
            first = [bare(h) for h in headers if re.search(r'[▲▼↑↓]', h)]
            for name in sorts:
                for click in (1, 2):
                    clicked = page.evaluate(CLICK_TH_JS, [sel, name, ARROWS])
                    out['cases'][f'sort «{name}» click {click}'] = read() if clicked else None
            # Back to the order the page had. The target clicks the column that had the arrow until the container reads
            # as before; legacy repeats the same clicks (it does not always update the arrow of its header), so both sides
            # go on from the same state.
            if side == 'target':
                column, clicks, restored = (first[0] if first else None), 0, False
                while column and clicks < 3 and not restored:
                    page.evaluate(CLICK_TH_JS, [sel, column, ARROWS])
                    clicks += 1
                    now = read()
                    restored = now is not None and [x[0] for x in now['lines']] == [x[0] for x in base['lines']]
                out['restore'] = (column, clicks)
                if not restored:
                    out['notes'].append('the order before the sorts is not restored (rows with equal keys keep the order of the last sort)')
            else:
                column, clicks = self.readings['target'].get(container, {}).get('restore', (None, 0))
                for _ in range(clicks):
                    page.evaluate(CLICK_TH_JS, [sel, column, ARROWS])
                    read()
        self.readings[side][container] = out

    # ---------------------------------------------------------------------------------------------- drill-down
    def run_drill(self, page, side: str, container: str, rows: str = 'tr[title]', modal: str = 'drilldownModal',
                  search: str = 'dd-search', settle: int = 400) -> None:
        """Every row of the container that opens a drill-down (legacy openDrilldown, the same window id on both sides): the
        window's title, KPIs, table and footer, and the probes of its search field. Rows are taken in their order."""
        key = container + ' drill-down'
        if self.only and key not in self.only and container not in self.only:
            return
        visible = "([c, r]) => [...document.querySelectorAll(c + ' ' + r)].filter(e => e.offsetParent !== null)"
        count = page.evaluate(f"(a) => ({visible})(a).length", ['#' + container, rows])
        extra = ['#dd-title', '#dd-subtitle', '#dd-kpi']

        def read() -> Optional[Dict[str, Any]]:
            page.wait_for_load_state('networkidle')
            page.wait_for_timeout(settle)
            return page.evaluate(READ_JS, ['#' + modal, [list(r) for r in LEGACY_RENDERINGS] if side == 'legacy' else [], extra, []])

        out: Dict[str, Any] = {'base': {'names': [], 'lines': []}, 'cases': {}, 'values': {}, 'options': {}, 'notes': []}
        for i in range(min(count, 40)):
            page.evaluate(f"(a) => {{ const e = ({visible})(a)[{i}]; if (e) e.click(); }}", ['#' + container, rows])
            out['cases'][f'row {i + 1}'] = read()
            for k, probe in enumerate(PROBES_TEXT):
                page.evaluate(SET_JS, ['#' + search, probe])
                out['cases'][f'row {i + 1}, search probe {k + 1}'] = read()
            page.evaluate(SET_JS, ['#' + search, ''])
            page.evaluate("(m) => { const c = document.querySelector('#' + m + ' .modal-close'); if (c) c.click(); }", modal)
            page.wait_for_timeout(200)
        out['notes'] += [] if count else ['no drill-down rows']
        self.readings[side][key] = out

    # ---------------------------------------------------------------------------------------------- the comparison
    @staticmethod
    def _diff(container: str, t: Dict[str, Any], g: Dict[str, Any], explained_t: set, explained_g: set) -> Tuple[Optional[str], Optional[str]]:
        """(message, documented item) — the message is None when the sides agree; the item when every difference is a
        documented column (KNOWN_COLUMNS)."""
        a = [x for x in t['lines'] if x[0] not in explained_t]
        b = [x for x in g['lines'] if x[0] not in explained_g]
        if [x[0] for x in a] == [x[0] for x in b]:
            return None, None
        only_a = {x[0] for x in a} - {x[0] for x in b}
        only_b = {x[0] for x in b} - {x[0] for x in a}
        if not only_a and not only_b:
            return 'same lines, other order', None
        # Pair the differing lines of each part in their order and name the columns that differ.
        parts: Dict[str, Tuple[list, list]] = {}
        for x in a:
            if x[0] in only_a:
                parts.setdefault(t['names'][x[1]], ([], []))[0].append(x[2])
        for x in b:
            if x[0] in only_b:
                parts.setdefault(g['names'][x[1]], ([], []))[1].append(x[2])
        where, refs, all_known = [], set(), True
        for name, (ta, gb) in sorted(parts.items()):
            cols = set()
            for ca, cb in zip(ta, gb):
                cols |= {k for k in range(max(len(ca), len(cb))) if (ca[k] if k < len(ca) else None) != (cb[k] if k < len(cb) else None)}
            unpaired = abs(len(ta) - len(gb))
            known = [KNOWN_COLUMNS.get((container, name, c)) for c in cols]
            if unpaired or not cols or not all(known):
                all_known = False
            refs |= {k for k in known if k}
            where.append(f'{name}: {max(len(ta), len(gb))}' + (f', columns {sorted(cols)}' if cols else '') + (f', unpaired {unpaired}' if unpaired else ''))
        message = f'{len(only_a) + len(only_b)} lines differ (' + '; '.join(where) + ')'
        return message, (', '.join(sorted(refs)) if all_known and refs else None)

    def report(self) -> List[str]:
        """The comparison per container; self.failed — the number of mismatches that are not documented."""
        lines = []
        self.failed = 0
        for container, t in self.readings['target'].items():
            g = self.readings['legacy'].get(container)
            if g is None:
                lines.append(f'filters {container}: legacy side not run')
                self.failed += 1
                continue
            if t['base'] is None or g['base'] is None:
                lines.append(f'filters {container}: container missing ({"target" if t["base"] is None else "legacy"})')
                self.failed += 1
                continue
            explained_t = {x[0] for x in t['base']['lines']} - {x[0] for x in g['base']['lines']}
            explained_g = {x[0] for x in g['base']['lines']} - {x[0] for x in t['base']['lines']}
            bad, known, compared = [], [], 0
            for opt, values in t['options'].items():
                if g['options'].get(opt) != values:
                    msg = f'{opt}: option lists differ ({len(values)} / {len(g["options"].get(opt) or [])})'
                    ref = KNOWN.get((container, opt, 'options'))
                    (known if ref else bad).append(msg + (f' — {ref}' if ref else ''))
            for label, tv in t['cases'].items():
                compared += 1
                gv = g['cases'].get(label, 'missing')
                control, value = t['values'].get(label, (None, None))
                ref = (KNOWN.get((container, control, value)) or KNOWN.get((container, control, '*'))
                       or KNOWN.get((container, control, 'options')))
                if tv is None or gv is None or gv == 'missing':
                    msg = f'{label}: not performed on {"target" if tv is None else "legacy"}'
                else:
                    msg, column_ref = self._diff(container, tv, gv, explained_t, explained_g)
                    ref = ref or column_ref
                    msg = f'{label}: {msg}' if msg else None
                if msg:
                    (known if ref else bad).append(msg + (f' — {ref}' if ref else ''))
            notes = t['notes'] + g['notes']
            base_diff = len(explained_t) + len(explained_g)
            head = f'filters {container}: {compared} cases, ' + ('совпадает' if not bad else f'РАСХОЖДЕНИЙ {len(bad)}')
            head += f'; записанных отличий {len(known)}' if known else ''
            lines.append(head + (f' (до фильтров отличаются {base_diff} строк — ADR-0002)' if base_diff else ''))
            lines += ['  ' + x for x in bad[:25]]
            lines += ['  записано: ' + x for x in known[:10]]
            lines += ['  не сравнивается: ' + x for x in t.get('skipped', [])]
            lines += ['  примечание: ' + x for x in notes]
            self.failed += len(bad)
        return lines
