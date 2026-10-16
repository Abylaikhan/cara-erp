#!/usr/bin/env python3
"""Browser acceptance of the windows (TEST-AC-06 «Окна, фон, Enter/Esc, фокус») — make e2e, throwaway database.

Legacy behaviour (accepted reference): a window closes when its dark backdrop is clicked — a global click handler, except
the money operation window (L16869); there is no keyboard handler for the windows, so Esc leaves them open; Enter acts
only in particular fields — on the login the user name moves to the password and the password signs in (L1958, L1963).
Every migrated window is opened here and must behave so; nothing is saved.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

from playwright.sync_api import Page, expect, sync_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))
from e2e_lib import BASE, PASSWORD, ROOT, check, holds, summary  # noqa: E402

SHOTS = ROOT / '.local-audit' / 'e2e'
OPEN = re.compile(r'\bopen\b')

# page, how to open, window id
WINDOWS = [
    ('/catalog', lambda p: p.get_by_role('button', name='+ Добавить товар').click(), 'productModal'),
    ('/customers', lambda p: p.get_by_role('button', name='+ Новый контрагент').click(), 'personModal'),
    ('/customers', lambda p: (p.click('#cust-tab-dealers'), p.get_by_role('button', name='+ Подробно').click()), 'addDealerModal'),
    ('/suppliers', lambda p: p.get_by_role('button', name='+ Добавить').click(), 'supplierCardModal'),
    ('/suppliers', lambda p: p.get_by_role('button', name='🔗 Объединить').click(), 'mergeSuppliersModal'),
    ('/expense_cats', lambda p: p.get_by_role('button', name='+ Добавить статью').click(), 'expCatModal'),
    ('/expense_cats', lambda p: p.get_by_role('button', name='🔀 Объединить две статьи').click(), 'expCatMergeModal'),
    ('/crm', lambda p: (p.click('#crm-tab-leads'), p.locator('#orders-leads').get_by_role('button', name='+ Новый лид').click()), 'leadModal'),
    ('/crm', lambda p: (p.click('text=🎯 Воронка лидов'), p.locator('#orders-funnel').get_by_role('button', name='+ Новый лид').click()), 'crmLeadModal'),
    ('/crm', lambda p: (p.click('text=✅ Задачи'), p.locator('#orders-tasks').get_by_role('button', name='+ Новая задача').click()), 'taskModal'),
]


def login_keyboard(page: Page) -> None:
    page.goto(BASE + '/login', wait_until='networkidle')
    page.fill('#login-username', 'director')
    page.press('#login-username', 'Enter')
    holds('вход: Enter в логине переводит фокус на пароль', lambda: expect(page.locator('#login-password')).to_be_focused())
    page.fill('#login-password', PASSWORD)
    page.press('#login-password', 'Enter')
    page.wait_for_url(lambda u: '/login' not in u, timeout=10000)
    check('вход: Enter в пароле выполняет вход', '/login' not in page.url)


def window(page: Page, url: str, opener, wid: str) -> None:
    page.goto(BASE + url, wait_until='networkidle')
    opener(page)
    overlay = page.locator(f'#{wid}')
    holds(f'{wid}: открывается', lambda: expect(overlay).to_have_class(OPEN))
    page.keyboard.press('Escape')
    page.wait_for_timeout(200)
    holds(f'{wid}: Esc не закрывает (как legacy)', lambda: expect(overlay).to_have_class(OPEN))
    overlay.locator('.modal').click(position={'x': 5, 'y': 5})
    holds(f'{wid}: щелчок внутри окна не закрывает', lambda: expect(overlay).to_have_class(OPEN))
    overlay.click(position={'x': 3, 'y': 3})  # the dark backdrop, outside the window
    holds(f'{wid}: щелчок по фону закрывает', lambda: expect(overlay).not_to_have_class(OPEN))


def main() -> int:
    SHOTS.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome', headless=True)
        page = browser.new_page(viewport={'width': 1440, 'height': 900}, locale='ru-RU')
        errors: list[str] = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        login_keyboard(page)
        for url, opener, wid in WINDOWS:
            try:
                window(page, url, opener, wid)
            except Exception as e:  # a step that could not even be performed
                check(f'{wid}: сценарий выполнен', False, f'{type(e).__name__}: {str(e).splitlines()[0][:200]}')
                page.screenshot(path=str(SHOTS / f'modal-{wid}.png'), full_page=True)
        check('нет необработанных ошибок в странице', not errors, repr(errors[:2]))
        browser.close()
    return summary()


if __name__ == '__main__':
    sys.exit(main())
