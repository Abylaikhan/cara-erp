#!/usr/bin/env python3
"""make perf — response time and size of every migrated page and every tab loaded after it (ТЗ OPS-01: «измерить и
закрепить»), on the running application (make up) with its data. Read only: GET requests of a signed-in director.

Each page is asked as Inertia does (X-Inertia), each lazily loaded tab as its partial reload; RUNS times each (5 by
default). Prints the median and the slowest time, the size of the answer and the number of rows of the largest list in
it — never the data. Non-zero exit when a median is over LIMIT_MS (3000 by default).
The dev application runs without the caches of production (config, routes, views): production is faster.
Usage: python3 scripts/perf.py   (RUNS=…, LIMIT_MS=…, PERF_BASE=http://…)
"""
from __future__ import annotations

import http.cookiejar
import json
import os
import re
import statistics
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENV = (ROOT / '.env').read_text(encoding='utf-8')
BASE = os.environ.get('PERF_BASE') or 'http://localhost:' + (re.search(r'^APP_PORT=(\d+)', ENV, re.M) or [None, '8080'])[1]
PASSWORD = re.search(r'^DEV_ADMIN_PASSWORD=(.*)$', ENV, re.M).group(1).strip().strip('"')
RUNS = int(os.environ.get('RUNS', '5'))
LIMIT_MS = int(os.environ.get('LIMIT_MS', '3000'))

# page → (Inertia component, lazily loaded props)
PAGES = {
    'dashboard': ('Dashboard', []), 'mrp': ('Mrp', []), 'warehouse': ('Warehouse', []), 'purchases': ('Purchases', []),
    'esf': ('Esf', []), 'suppliers': ('Suppliers', []), 'catalog': ('Catalog', []), 'sales': ('Sales', ['realizations', 'report']),
    'customers': ('Customers', ['reconcileOrders']), 'crm': ('Crm', ['clientOrders', 'boardOrders', 'projects']),
    'finance': ('Finance', ['transactions', 'purchases']), 'money': ('Money', ['advances', 'calendar', 'bankRecon', 'salonRows']),
    'expense_cats': ('ExpenseCats', []), 'money_salon': ('MoneySalon', []), 'reconcile': ('Reconcile', []),
    'salon_report': ('SalonReport', []),
}
# The director has no salon pages (legacy role template): those are asked as the head of the salons.
SALON_PAGES = {'money_salon', 'salon_report'}


class Client:
    def __init__(self, login: str):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        page = self.opener.open(BASE + '/login', timeout=60).read().decode('utf-8', 'replace')
        self.version = json.loads(re.search(r'<script data-page="app" type="application/json">(.*?)</script>', page, re.S).group(1))['version']
        body = urllib.parse.urlencode({'login': login, 'password': PASSWORD}).encode()
        req = urllib.request.Request(BASE + '/login', data=body, headers={'X-XSRF-TOKEN': self.xsrf(), 'Content-Type': 'application/x-www-form-urlencoded'})
        self.opener.open(req, timeout=60).read()

    def xsrf(self) -> str:
        return urllib.parse.unquote(next(c.value for c in self.jar if c.name == 'XSRF-TOKEN'))

    def get(self, path: str, component: str, partial: str | None = None) -> tuple[float, int, dict]:
        headers = {'X-Inertia': 'true', 'X-Inertia-Version': self.version or '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html, application/xhtml+xml'}
        if partial:
            headers.update({'X-Inertia-Partial-Component': component, 'X-Inertia-Partial-Data': partial})
        t0 = time.perf_counter()
        data = self.opener.open(urllib.request.Request(BASE + path, headers=headers), timeout=120).read()
        elapsed = (time.perf_counter() - t0) * 1000
        payload = json.loads(data)
        if payload.get('component') != component:
            raise RuntimeError(f'{path}: got {payload.get("component")}, not {component}')
        return elapsed, len(data), payload['props']


def largest_list(props: dict, only: str | None) -> int:
    def walk(o) -> int:
        if isinstance(o, list):
            return max([len(o)] + [walk(x) for x in o[:1]])
        if isinstance(o, dict):
            return max([0] + [walk(v) for v in o.values()])
        return 0
    return walk(props.get(only) if only else props)


def main() -> int:
    director, salons = Client('director'), Client('salon_armada_mgr')
    rows, slow = [], []
    for page, (component, lazy) in PAGES.items():
        client = salons if page in SALON_PAGES else director
        for partial in [None, *lazy]:
            times = []
            for _ in range(RUNS):
                ms, size, props = client.get('/' + page, component, partial)
                times.append(ms)
            name = page + (f' → {partial}' if partial else '')
            median = statistics.median(times)
            rows.append((name, median, max(times), size, largest_list(props, partial)))
            if median > LIMIT_MS:
                slow.append(name)
    print(f'{"page / tab":34} {"median ms":>10} {"max ms":>8} {"KB":>8} {"rows":>7}')
    for name, median, worst, size, n in rows:
        print(f'{name:34} {median:10.0f} {worst:8.0f} {size / 1024:8.0f} {n:7}')
    print(f'{len(rows)} requests × {RUNS}; median over {LIMIT_MS} ms: {", ".join(slow) if slow else "none"}')
    return 1 if slow else 0


if __name__ == '__main__':
    sys.exit(main())
