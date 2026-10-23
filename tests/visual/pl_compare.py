#!/usr/bin/env python3
"""make pl-compare (ADR-0052): «Финансы» → «📊 P&L» of the target against legacy on the same data.

The rows legacy reads (realizations, orders, operations, advance reports, expense items, suppliers, ESF and their lines,
materials, the salary ledger, the catalog) are taken from the dev database in legacy's shapes (tests/visual/pl_dump.php), given
to the accepted reference (every network request blocked) and legacy counts buildPL and the six sub-reports of a year. The
server counts the same. Every figure is compared in whole tenge (legacy sums floats; 1 tenge of rounding is allowed).

Output: per table «совпадает» or the number of differing cells and the first row numbers — never a value or a name (the data
are real). The dump stays in .local-audit. Usage: PL_YEAR=2026 python3 tests/visual/pl_compare.py
"""
from __future__ import annotations

import datetime as dt
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
CFG = json.loads((ROOT / 'tools/harness/config.json').read_text())
ACCEPTED = CFG['source']['accepted_ref']['sha256']
BLOB = next(b for b in sorted((ROOT / '.local-audit/blobs').glob('*.html')) if hashlib.sha256(b.read_bytes()).hexdigest() == ACCEPTED)
YEAR = os.environ.get('PL_YEAR') or str(dt.date.today().year)
OUT = ROOT / '.local-audit' / 'pl-compare'

LEGACY_JS = r"""(d) => {
    realizationsData = d.realizations; ordersData = d.orders; _cacheLoaded.money = true;
    moneyTx = d.moneyTx; bankAccounts.length = 0; d.accounts.forEach(a => bankAccounts.push(a)); advanceReports = d.advances; _expenseCats = d.cats; _expenseCatsLoaded = true;
    suppliersData = d.suppliers; esfReconInvoices = d.invoices; _osvEsfLoaded = true; _esfData = {invoices: d.invoices, items: d.items};
    warehouseData = d.warehouse; _payrollData = {accruals: d.accruals, payments: []}; catalogData = d.catalog;
    // the demonstration lists of the page are not data (ADR-0002, item 126)
    wageEmployees = []; payrollStaff = []; fixedCostsData = []; fcPlanData = []; whDocuments = []; purchasesData = [];
}"""

YEAR_JS = r"""([year]) => {
    const y = +year;
    financePeriod = {from: new Date(Date.UTC(y, 0, 1)), to: new Date(Date.UTC(y, 11, 31)), mode: 'year'};
    const pl = buildPL();
    // the sub-reports read the year from their select: draw once (the options), choose the year, draw again
    const draw = (fn, id) => { fn(); const s = document.getElementById(id); if (s) { s.value = year; } fn(); };
    draw(renderMonthlyPL, 'pl-monthly-year');
    draw(renderRevenueByChannel, 'pl-rev-channel-year');
    draw(renderRevenueByCategory, 'pl-rev-category-year');
    draw(renderExpensesByMonth, 'pl-exp-year');
    draw(renderRevenueByChannelCategory, 'pl-rev-tree-year');
    draw(renderMaterialCostEstimate, 'pl-matcost-year');
    const osv = buildOSV();
    return {osv: {rows: osv.rows, totals: osv.totals, journal: osv.journal.length}, pl, monthly: window._monthlyPLRows, channel: window._plChannelRows, category: window._plCategoryRows,
        expenses: window._plExpenseCatRows, dividends: window._plExpenseDividends, tree: window._plRevTreeRows, cost: window._matCostRows,
        costHint: document.querySelector('#pl-matcost > div')?.textContent || ''};
}"""

PL_KEYS = ['revenue', 'materialCost', 'wageCost', 'oprCost', 'grossMargin', 'aupSalary', 'prodTaxes', 'commercialCost', 'otherFixed', 'ebit',
           'esfMaterialEstimate', 'prodToMaterials', 'prodUnclassified', 'advCommercialCost', 'advOtherExpense', 'advUndetermined', 'payrollLedgerSdelnaya', 'payrollLedgerAup']


def near(a, b) -> bool:
    return abs(round(a or 0) - round(b or 0)) <= 1


def rows_report(name: str, legacy: list, target: list) -> bool:
    """Rows of (label, monthly) compared label by label and month by month; prints positions only."""
    bad = []
    if len(legacy) != len(target):
        print(f'{name}: строк {len(target)} / {len(legacy)} — РАСХОЖДЕНИЕ')
        return False
    for i, (l, t) in enumerate(zip(legacy, target)):
        if l[0] != t[0]:
            bad.append(f'строка {i + 1}: подпись')
        elif any(not near(a, b) for a, b in zip(l[1], t[1])):
            bad.append(f'строка {i + 1}: ' + ', '.join(str(m + 1) for m, (a, b) in enumerate(zip(l[1], t[1])) if not near(a, b)))
    print(f'{name}: строк {len(target)}, ' + ('совпадает' if not bad else f'РАСХОЖДЕНИЕ ({len(bad)}): ' + '; '.join(bad[:6])))
    return not bad


def flatten(rows: list, children: tuple[str, ...], label='label', monthly='monthly') -> list:
    out = []
    for r in rows or []:
        out.append((r[label], r[monthly]))
        for key in children:
            if r.get(key):
                out += flatten(r[key], children[1:] if len(children) > 1 else (), label, monthly)
    return out


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    dump = subprocess.run(['docker', 'compose', 'exec', '-T', '-e', f'PL_YEAR={YEAR}', 'app', 'php', 'artisan', 'tinker', '--execute', "require 'tests/visual/pl_dump.php';"],
                          cwd=ROOT, capture_output=True, text=True, timeout=600, check=True).stdout
    data = json.loads(dump[dump.index('{'):])
    (OUT / f'dump-{YEAR}.json').write_text(json.dumps(data, ensure_ascii=False))
    target = data['target']
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome', headless=True)
        page = browser.new_page(viewport={'width': 1440, 'height': 900}, locale='ru-RU')
        page.route('**/*', lambda r: r.continue_() if r.request.url.startswith('file://') or 'fonts.g' in r.request.url else r.abort())
        page.on('dialog', lambda d: d.dismiss())
        page.goto(BLOB.as_uri(), wait_until='load', timeout=60000); page.wait_for_timeout(1500)
        page.evaluate(LEGACY_JS, data['legacy'])
        legacy = page.evaluate(YEAR_JS, [YEAR])
        browser.close()

    ok = True
    bad = [k for k in PL_KEYS if not near(legacy['pl'].get(k), target['pl'].get(k))]
    print(f'P&L {YEAR}: {len(PL_KEYS)} строк, ' + ('совпадает' if not bad else 'РАСХОЖДЕНИЕ: ' + ', '.join(bad)))
    ok &= not bad
    months = [(r['label'], [v or 0 for v in r['monthly']]) for r in legacy['monthly'] or []]
    keys = ['revenue', 'materialCost', 'wageCost', 'oprCost', 'grossMargin', 'aupSalary', 'prodTaxes', 'commercialCost', 'otherFixed', 'ebit']
    signs = [1, -1, -1, -1, 1, -1, -1, -1, -1, 1]
    tm = [(lab, [0 if m is None else s * (m.get(k) or 0) for m in target['monthly']]) for (lab, _), k, s in zip(months, keys, signs)]
    ok &= rows_report('помесячно', months, tm)
    ok &= rows_report('выручка по направлениям', [(r['label'], r['data']) for r in legacy['channel'] or []], [(r['label'], r['data']) for r in target['revChannel']['rows']])
    ok &= rows_report('выручка по категориям', [(r['label'], r['data']) for r in legacy['category'] or []], [(r['label'], r['data']) for r in target['revCategory']['rows']])
    ok &= rows_report('расходы по статьям', flatten(legacy['expenses'], ('subs',)), flatten(target['expenses']['rows'], ('subs',)))
    ok &= rows_report('дивиденды', [('div', legacy['dividends'] or [0] * 12)], [('div', target['expenses']['dividends'])])
    ok &= rows_report('дерево выручки', flatten(legacy['tree'], ('cats', 'items')), flatten(target['tree'], ('cats', 'items')))
    ok &= rows_report('оценка себестоимости', flatten(legacy['cost'], ('mats',)), flatten(target['cost']['rows'], ('mats',)))
    cols = ['openDt', 'openKt', 'dtOb', 'ktOb', 'closeDt', 'closeKt']
    ok &= rows_report('ОСВ', [(r['code'], [r[c] for c in cols]) for r in legacy['osv']['rows']], [(r['code'], [r[c] for c in cols]) for r in target['osv']['rows']])
    ok &= rows_report('ОСВ итого', [('итого', [legacy['osv']['totals'][c] for c in cols])], [('итого', [target['osv']['totals'][c] for c in cols])])
    jl, jt = legacy['osv']['journal'], len(target['osv']['journal'])
    print(f'ОСВ проводки: {jt} / {jl} — ' + ('совпадает' if jl == jt else 'РАСХОЖДЕНИЕ'))
    ok &= jl == jt
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
