#!/usr/bin/env python3
"""ERP-072, AC-72 «Серверная ошибка, медленная сеть, два нажатия: нет ложного успеха/дублей, форма остаётся доступной,
повтор безопасен» — in a real browser against the running application (make up). Every request that would change data is
intercepted here and answered by this script: nothing reaches the server, the data are not touched.

Scenarios: a server error (500), a lost connection, an expired session (419) while saving a catalog item; a double click
on «Сохранить» and on «+ Добавить строку» of the salon worksheet while the first request is still on its way.
Usage: python3 tests/e2e/reliability_check.py   (host Playwright + Chrome, as make visual-compare). Non-zero exit on failure.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

from playwright.sync_api import Page, Route, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ENV = (ROOT / '.env').read_text()
TARGET = 'http://localhost:' + (re.search(r'^APP_PORT=(\d+)', ENV, re.M) or [None, '8080'])[1]
DEV_PASSWORD = re.search(r'^DEV_ADMIN_PASSWORD=(.*)$', ENV, re.M).group(1).strip()
SAVE = '#productModal button.btn-primary'

results: list[tuple[str, bool, str]] = []


def check(name: str, ok: bool, detail: str = '') -> None:
    results.append((name, ok, detail))
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f' — {detail}' if detail and not ok else ''))


class Server:
    """Answers the writes of one URL pattern instead of the server; GET requests go through (they change nothing)."""

    def __init__(self, page: Page, pattern: str, answer):
        self.page, self.pattern, self.answer, self.writes, self.pending = page, pattern, answer, 0, []
        page.route(pattern, self.handle)

    def handle(self, route: Route) -> None:
        if route.request.method == 'GET':
            route.continue_()
            return
        self.writes += 1
        if self.answer == 'hold':
            self.pending.append(route)  # a slow network: answered later
        elif self.answer == 'offline':
            route.abort('internetdisconnected')
        else:
            route.fulfill(status=self.answer, content_type='text/html', body='<html><body>Server Error</body></html>')

    def release(self) -> None:
        for route in self.pending:
            route.fulfill(status=500, content_type='text/html', body='<html><body>Server Error</body></html>')
        self.pending = []

    def stop(self) -> None:
        self.page.unroute(self.pattern, self.handle)


def open_product_form(page: Page) -> None:
    page.goto(TARGET + '/catalog', wait_until='networkidle')
    page.click('text=+ Добавить товар')
    page.fill('#pm-name', 'Проверка надёжности')
    page.fill('#pm-category', 'Проверка')


def form_kept(page: Page) -> bool:
    return page.is_visible('#productModal') and page.input_value('#pm-name') == 'Проверка надёжности'


def main() -> int:
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome', headless=True)
        page = browser.new_page(viewport={'width': 1440, 'height': 900})
        dialogs: list[str] = []
        errors: list[str] = []
        page.on('dialog', lambda d: (dialogs.append(d.message), d.accept()))
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.goto(TARGET + '/login', wait_until='networkidle')
        page.fill('#login-username', 'director')
        page.fill('#login-password', DEV_PASSWORD)
        page.click('#login-btn')
        page.wait_for_url(lambda u: '/login' not in u)

        for answer, expect in ((500, 'Ошибка на сервере. (HTTP 500)'), ('offline', 'Нет связи с сервером.'), (419, 'Сессия истекла')):
            open_product_form(page)
            dialogs.clear()
            server = Server(page, '**/catalog', answer)
            page.click(SAVE)
            page.wait_for_timeout(800)
            server.stop()
            told = bool(dialogs) and expect in dialogs[0] and 'Изменения не сохранены' in dialogs[0]
            check(f'{answer}: the user is told why and that nothing is saved', told, repr(dialogs[:1]))
            check(f'{answer}: the form stays open with what was typed', form_kept(page))
            check(f'{answer}: «Сохранить» can be pressed again', page.is_enabled(SAVE))
            check(f'{answer}: no success message', not page.is_visible('.toast, .erp-toast'))

        open_product_form(page)
        dialogs.clear()
        server = Server(page, '**/catalog', 'hold')
        page.click(SAVE)
        page.wait_for_timeout(200)
        check('slow network: «Сохранить» is disabled while the request is on its way', page.is_disabled(SAVE))
        page.dblclick(SAVE, force=True)
        page.keyboard.press('Enter')
        page.wait_for_timeout(300)
        writes = server.writes
        server.release()
        page.wait_for_timeout(800)
        server.stop()
        check('double click on «Сохранить»: one request', writes == 1, f'{writes} requests')
        check('after the late failure: told, form kept', bool(dialogs) and form_kept(page), repr(dialogs[:1]))

        page.goto(TARGET + '/money', wait_until='networkidle')
        page.click('text=🧾 Рабочий лист салонов')
        page.wait_for_selector('text=+ Добавить строку')
        dialogs.clear()
        server = Server(page, '**/money/salon-worksheet', 'hold')
        page.dblclick('text=+ Добавить строку')
        page.wait_for_timeout(300)
        page.click('text=+ Добавить строку', force=True)
        page.wait_for_timeout(300)
        writes = server.writes
        server.release()
        page.wait_for_timeout(800)
        server.stop()
        check('double click on «+ Добавить строку»: one row requested', writes == 1, f'{writes} requests')

        check('no uncaught error in the page', not errors, repr(errors[:2]))
        browser.close()
    failed = [r for r in results if not r[1]]
    print(f'\n{len(results) - len(failed)}/{len(results)} checks passed')
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
