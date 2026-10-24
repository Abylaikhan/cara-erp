#!/usr/bin/env python3
"""make pl-compare (ADR-0052): «Финансы» → «📊 P&L» of the target against legacy on the same data.

The rows legacy reads (realizations, orders, operations, advance reports, expense items, suppliers, ESF and their lines,
materials, the salary ledger, the catalog) are taken from the dev database in legacy's shapes (tests/visual/pl_dump.php), given
to the accepted reference (every network request blocked) and legacy counts buildPL and the six sub-reports of a year. The
server counts the same. Every figure is compared in whole tenge (legacy sums floats; 1 tenge of rounding is allowed).

What both show is compared too, line by line (cells of a row joined, sums in tenge within 1), against the target signed in on
the dev app with the year as the period: the KPI and the statement of «📊 P&L» and its sub-reports, «Маржинальность по
категориям», the KPI of «Точка безубыточности», the ОСВ (ADR-0052…0055). PL_SHAPES=1 prints the shape of differing lines
(digits → #, letters → a).

Output: per table «совпадает» or the number of differing cells and the first row numbers — never a value or a name (the data
are real). The dump stays in .local-audit. Usage: PL_YEAR=2026 python3 tests/visual/pl_compare.py
"""
from __future__ import annotations

import datetime as dt
import hashlib
import json
import os
import re
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
ENV = (ROOT / '.env').read_text()
TARGET = os.environ.get('E2E_BASE') or 'http://localhost:' + (re.search(r'^APP_PORT=(\d+)', ENV, re.M) or [None, '8080'])[1]
DEV_PASSWORD = re.search(r'^DEV_ADMIN_PASSWORD=(.*)$', ENV, re.M).group(1).strip()

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
    draw(renderCategoryMargin, 'margin-category-year');
    const osv = buildOSV();
    renderBEPKpi(); renderPL(); renderOSV();
    return {shown: Object.fromEntries(SHOWN_IDS.map(id => [id, eval(SHOWN_JS)(id)])), osv: {rows: osv.rows, totals: osv.totals, journal: osv.journal.length}, pl, monthly: window._monthlyPLRows, channel: window._plChannelRows, category: window._plCategoryRows,
        expenses: window._plExpenseCatRows, dividends: window._plExpenseDividends, tree: window._plRevTreeRows, cost: window._matCostRows,
        costHint: document.querySelector('#pl-matcost > div')?.textContent || '', catMargin: window._categoryMarginRows};
}"""

# The text of a block as it is shown, the same for a hidden page: a line per table row (its cells joined) or per child.
SHOWN_JS = r"""(id) => {
    // the text nodes of an element, each trimmed, joined by a space: the markup's own line breaks do not count
    const t = n => { const w = document.createTreeWalker(n, NodeFilter.SHOW_TEXT), out = []; while (w.nextNode()) { const v = w.currentNode.nodeValue.replace(/\s+/g, ' ').trim(); if (v) out.push(v); } return out.join(' '); };
    const el = document.getElementById(id);
    if (!el) return '';
    const rows = el.querySelectorAll('tr');
    const lines = rows.length ? [...el.children].filter(c => c.tagName !== 'TABLE').map(t).concat([...rows].map(tr => [...tr.children].map(t).join(' | ')))
                              : [...el.children].map(t);
    return lines.join('\n');
}"""
# What is compared as shown: the statement and its sub-reports of the year, «Маржинальность», the KPI of ТБУ, the ОСВ.
SHOWN = {'pl-kpi-row': 'KPI P&L', 'pl-statement': 'отчёт P&L', 'pl-monthly': 'помесячно', 'pl-rev-by-channel': 'выручка по направлениям',
         'pl-rev-by-category': 'выручка по категориям', 'pl-exp-by-month': 'расходы по статьям', 'pl-matcost': 'оценка себестоимости',
         'margin-category-table': 'маржинальность по категориям', 'bep-kpi': 'KPI точки безубыточности', 'osv-body': 'ОСВ'}
YEAR_JS = YEAR_JS.replace('eval(SHOWN_JS)', '(' + SHOWN_JS + ')').replace('SHOWN_IDS', json.dumps(list(SHOWN)))

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


def target_shown(page) -> dict:
    """The target as the director sees it: the year as the period, «Маржинальность» of the year, the KPI of «Точка безубыточности»."""
    page.goto(TARGET + '/login', wait_until='networkidle')
    page.fill('#login-username', 'director'); page.fill('#login-password', DEV_PASSWORD); page.click('#login-btn')
    page.wait_for_url(lambda u: '/login' not in u)
    page.goto(TARGET + '/finance', wait_until='networkidle')
    page.wait_for_selector('#pl-statement table', timeout=120000); page.wait_for_load_state('networkidle')
    if YEAR != dt.date.today().strftime('%Y'):  # another year: the statement is asked again for it
        with page.expect_response(lambda r: '/finance' in r.url and f'pl_from={YEAR}-01-01' in r.url and f'pl_to={YEAR}-12-31' in r.url, timeout=120000):
            page.fill('#fp-date-from', f'{YEAR}-01-01'); page.locator('#fp-date-from').dispatch_event('change')
            page.fill('#fp-date-to', f'{YEAR}-12-31'); page.locator('#fp-date-to').dispatch_event('change')
        page.wait_for_load_state('networkidle'); page.wait_for_timeout(800)
    for sel in ('pl-monthly-year', 'pl-rev-channel-year', 'pl-rev-category-year', 'pl-exp-year', 'pl-matcost-year'):
        page.wait_for_selector(f'#{sel} option[value="{YEAR}"]', state='attached', timeout=120000)
        page.select_option(f'#{sel}', YEAR); page.wait_for_load_state('networkidle')
    page.wait_for_timeout(1500)
    shown = {id: page.evaluate(SHOWN_JS, id) for id in list(SHOWN)[:7]}
    page.locator('#finance-tabs .tab').nth(3).click()
    page.wait_for_selector('#margin-category-table table', timeout=120000)
    page.select_option('#margin-category-year', YEAR); page.wait_for_load_state('networkidle'); page.wait_for_timeout(800)
    shown['margin-category-table'] = page.evaluate(SHOWN_JS, 'margin-category-table')
    page.locator('#finance-tabs .tab').nth(4).click()
    page.wait_for_selector('#bep-kpi .bep-kpi-card', timeout=120000); page.wait_for_load_state('networkidle'); page.wait_for_timeout(800)
    shown['bep-kpi'] = page.evaluate(SHOWN_JS, 'bep-kpi')
    page.locator('#finance-tabs .tab').nth(6).click()
    page.wait_for_selector('#osv-body table', timeout=120000); page.wait_for_load_state('networkidle'); page.wait_for_timeout(800)
    shown['osv-body'] = page.evaluate(SHOWN_JS, 'osv-body')
    return shown


def text_report(name: str, legacy: str, target: str) -> bool:
    norm = lambda t: [' '.join(l.split()) for l in t.splitlines() if l.strip()]
    a, b = norm(legacy), norm(target)
    # the same text, the sums in tenge within 1 (legacy adds floats, the server counts exactly — as the figures above)
    money = re.compile(r'₸(-?\d[\d \u00a0]*)')
    def same(x, y):
        if x is None or y is None or money.sub('₸', x) != money.sub('₸', y):
            return x == y
        return all(abs(int(re.sub(r'\D', '', m) or 0) * (-1 if m.startswith('-') else 1) - int(re.sub(r'\D', '', n) or 0) * (-1 if n.startswith('-') else 1)) <= 1
                   for m, n in zip(money.findall(x), money.findall(y)))
    bad = [i + 1 for i in range(max(len(a), len(b))) if not same(a[i] if i < len(a) else None, b[i] if i < len(b) else None)]
    print(f'{name}: строк {len(b)} / {len(a)}, ' + ('совпадает' if not bad else f'РАСХОЖДЕНИЙ {len(bad)}, строки {bad[:5]}'))
    if bad and os.environ.get('PL_SHAPES'):  # the shape of the lines only: digits → #, letters → a
        shape = lambda l: re.sub(r'[^\W\d_]', 'a', re.sub(r'\d', '#', l))
        for i in range(min(14, max(len(a), len(b)))):
            print('   ', repr(shape(a[i]) if i < len(a) else None)[:70], '|', repr(shape(b[i]) if i < len(b) else None)[:70])
    return not bad


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
        shown = target_shown(browser.new_page(viewport={'width': 1440, 'height': 900}, locale='ru-RU'))
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
    ok &= rows_report('маржинальность по категориям', [(r['label'], r['margin'] + r['revenue']) for r in legacy['catMargin'] or []],
                      [(r['label'], r['margin'] + r['revenue']) for r in target['catMargin']])
    cols = ['openDt', 'openKt', 'dtOb', 'ktOb', 'closeDt', 'closeKt']
    ok &= rows_report('ОСВ', [(r['code'], [r[c] for c in cols]) for r in legacy['osv']['rows']], [(r['code'], [r[c] for c in cols]) for r in target['osv']['rows']])
    ok &= rows_report('ОСВ итого', [('итого', [legacy['osv']['totals'][c] for c in cols])], [('итого', [target['osv']['totals'][c] for c in cols])])
    jl, jt = legacy['osv']['journal'], len(target['osv']['journal'])
    print(f'ОСВ проводки: {jt} / {jl} — ' + ('совпадает' if jl == jt else 'РАСХОЖДЕНИЕ'))
    ok &= jl == jt
    for key, name in SHOWN.items():
        ok &= text_report(name + ' на экране', legacy['shown'][key], shown[key])
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
