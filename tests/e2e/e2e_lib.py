"""Shared helpers of the browser acceptance scenarios (make e2e)."""
from __future__ import annotations

import os
import re
import subprocess
from pathlib import Path

from playwright.sync_api import Page

ROOT = Path(__file__).resolve().parents[2]
ENV = (ROOT / '.env').read_text()
BASE = os.environ.get('E2E_BASE', 'http://127.0.0.1:8091')
DB = os.environ.get('E2E_DB', '')
PASSWORD = re.search(r'^DEV_ADMIN_PASSWORD=(.*)$', ENV, re.M).group(1).strip()

results: list[tuple[str, bool, str]] = []


def check(name: str, ok: bool, detail: str = '') -> None:
    results.append((name, ok, detail))
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f' — {detail}' if detail and not ok else ''))


def holds(name: str, fn) -> None:
    """A step asserted with Playwright's expect (it waits up to 5 s)."""
    try:
        fn()
        check(name, True)
    except AssertionError as e:
        lines = str(e).splitlines()
        actual = next((line.strip() for line in lines if line.strip().startswith('Actual value')), '')
        check(name, False, lines[0][:160] + (' / ' + actual[:80] if actual else ''))


def sql(query: str) -> list[list[str]]:
    """Rows of a query on the throwaway database of make e2e — and only on it."""
    if not re.fullmatch(r'promtech_e2e_\d{14}', DB):
        raise RuntimeError('E2E_DB is not a throwaway database of make e2e: refusing to query it')
    out = subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'sh', '-c', f'psql -X -q -U "$POSTGRES_USER" -d {DB} -At -F "\t"'],
                         cwd=ROOT, input=query, capture_output=True, text=True, timeout=60, check=True).stdout
    return [line.split('\t') for line in out.splitlines() if line]


def login(page: Page, login_name: str = 'director') -> None:
    page.goto(BASE + '/login', wait_until='networkidle')
    page.fill('#login-username', login_name)
    page.fill('#login-password', PASSWORD)
    page.click('#login-btn')
    page.wait_for_url(lambda u: '/login' not in u)


def summary() -> int:
    failed = [r for r in results if not r[1]]
    print(f'\n{len(results) - len(failed)}/{len(results)} checks passed')
    return 1 if failed else 0
