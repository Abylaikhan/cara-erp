#!/usr/bin/env python3
"""Browser acceptance of the loading states (TEST-AC-07 «Пусто, загрузка, ошибка, много строк») — make e2e, throwaway database.

Seventeen tabs bring their data after the page: a partial reload when the tab is opened. Legacy shows its static markup until
its loader is done — empty frames, and «Загрузка…» where its markup or loader has it (the realization journal, the projects).
So, for every such tab:
- while its data are on the way it must not claim there are none («Нет данных», «Нет клиентов», «Пусто» …), and shows the
  placeholder of legacy where legacy has one;
- when the load fails, the user is told «Данные не загружены.», the tab still claims nothing, and opening the tab again
  loads it.
The other states: empty — make visual-empty; many rows — LargeRegistriesTest; failed saving — make reliability-check.
Nothing is saved.
"""
from __future__ import annotations

import sys
from pathlib import Path

from playwright.sync_api import Page, Route, sync_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))
from e2e_lib import BASE, check, login, summary  # noqa: E402

# What a tab says once its data have come and there are none — never while they are on the way.
EMPTY = ('Нет данных', 'Нет клиентов', 'Нет заказов', 'Нет операций', 'Нет оплат', 'Пусто', 'записей нет', 'Лист пуст', 'Нет проведённых работ',
         'Нет незавершённых этапов')

# page, the prop of the partial reload, how to open the tab, its container, the placeholder of legacy while loading
TABS = [
    ('/sales', 'realizations', lambda p: p.locator('#sales-tabs .tab').nth(4).click(), '#orders-sales-journal', 'Загрузка реализации'),
    ('/sales', 'report', lambda p: p.locator('#sales-tabs .tab').nth(7).click(), '#orders-report', None),
    ('/finance', 'purchases', lambda p: p.locator('#finance-tabs .tab').nth(2).click(), '#finance-purchases', None),
    ('/finance', 'transactions', lambda p: p.locator('#finance-tabs .tab').nth(5).click(), '#finance-cfs', None),
    ('/crm', 'clientOrders', lambda p: p.locator('#crm-tab-clients').click(), '#orders-clients', None),
    ('/crm', 'boardOrders', lambda p: p.locator('#page-crm .tabs .tab').nth(4).click(), '#orders-orders-kanban-prod', None),
    ('/crm', 'projects', lambda p: p.locator('#crm-tab-projects').click(), '#orders-projects', 'Загрузка'),
    ('/customers', 'reconcileOrders', lambda p: p.locator('#cust-tab-reconcile').click(), '#cust-reconcile-counterparties', None),
    ('/money', 'salonRows', lambda p: p.locator('#page-money > .tabs .tab').nth(2).click(), '#money-tab-salonwb', None),
    ('/money', 'bankRecon', lambda p: p.locator('#page-money > .tabs .tab').nth(3).click(), '#money-tab-bankrecon', None),
    ('/money', 'advances', lambda p: p.locator('#page-money > .tabs .tab').nth(4).click(), '#money-tab-advances', None),
    ('/money', 'calendar', lambda p: p.locator('#page-money > .tabs .tab').nth(6).click(), '#money-tab-calendar', None),
    # legacy swReload writes «⏳ Загрузка...» until the lines come; the production tabs keep their static frames
    ('/sales', 'worksheet', lambda p: p.locator('#sw-tab-btn').click(), '#orders-worksheet', 'Загрузка'),
    ('/mrp', 'worksheet', lambda p: p.locator('#page-mrp > .tabs .tab').nth(2).click(), '#mrp-worksheet', None),
    ('/mrp', 'work', lambda p: p.locator('#page-mrp > .tabs .tab').nth(4).click(), '#mrp-workload', None),
    ('/mrp', 'work', lambda p: p.locator('#page-mrp > .tabs .tab').nth(5).click(), '#mrp-worklog', None),
]


class Hold:
    """Holds the partial reload of one prop (a slow network) or answers it with a server error; the rest goes through."""

    def __init__(self, page: Page):
        self.prop, self.answer, self.held, self.seen = None, 'hold', [], 0
        page.route('**/*', self.handle)

    def handle(self, route: Route) -> None:
        if self.prop and self.prop in route.request.headers.get('x-inertia-partial-data', '').split(','):
            self.seen += 1
            if self.answer == 'hold':
                self.held.append(route)
            else:
                route.fulfill(status=500, content_type='text/html', body='<html><body>Server Error</body></html>')
            return
        route.continue_()

    def watch(self, prop: str, answer: str = 'hold') -> None:
        self.prop, self.answer, self.held, self.seen = prop, answer, [], 0

    def release(self) -> None:
        self.prop = None
        for route in self.held:
            route.continue_()
        self.held = []


def claims_empty(page: Page, container: str) -> list[str]:
    text = page.locator(container).inner_text()
    return [m for m in EMPTY if m in text]


def main() -> int:
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome', headless=True)
        page = browser.new_page(viewport={'width': 1440, 'height': 900}, locale='ru-RU')
        errors: list[str] = []
        dialogs: list[str] = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.on('dialog', lambda d: (dialogs.append(d.message), d.dismiss()))
        login(page)
        hold = Hold(page)
        for url, prop, opener, container, placeholder in TABS:
            try:
                page.goto(BASE + url, wait_until='networkidle')
                hold.watch(prop)
                opener(page)
                page.wait_for_timeout(1000)
                check(f'{prop}: the tab requests its data', hold.seen == 1, f'{hold.seen} requests')
                check(f'{prop}: while loading, no claim that there are no data', not claims_empty(page, container), repr(claims_empty(page, container)))
                if placeholder:
                    check(f'{prop}: «{placeholder}» while loading, as legacy', placeholder in page.locator(container).inner_text())
                hold.release()
                page.wait_for_load_state('networkidle')
                page.wait_for_timeout(500)
                if placeholder:
                    check(f'{prop}: the placeholder goes when the data come', placeholder not in page.locator(container).inner_text())
            except Exception as e:  # a step that could not even be performed
                hold.release()
                check(f'{prop}: scenario performed', False, f'{type(e).__name__}: {str(e).splitlines()[0][:200]}')

        # A failed load: told, nothing claimed, and the tab loads when it is opened again.
        page.goto(BASE + '/money', wait_until='networkidle')
        dialogs.clear()
        hold.watch('advances', answer=500)
        page.locator('#page-money > .tabs .tab').nth(4).click()
        page.wait_for_timeout(1500)
        check('failed load: the user is told the data are not loaded', any('Данные не загружены' in d and '500' in d for d in dialogs), repr(dialogs[:1]))
        check('failed load: no claim that there are no data', not claims_empty(page, '#money-tab-advances'), repr(claims_empty(page, '#money-tab-advances')))
        hold.release()
        page.locator('#page-money > .tabs .tab').nth(0).click()
        page.wait_for_timeout(300)
        with page.expect_request(lambda r: 'advances' in r.headers.get('x-inertia-partial-data', '').split(','), timeout=5000) as again:
            page.locator('#page-money > .tabs .tab').nth(4).click()
        check('failed load: opening the tab again loads it', again.value is not None)
        page.wait_for_load_state('networkidle')
        # «Отчёт салонов» → «Платёжная ведомость» / «История ЗП» (ADR-0034): the head of the salons; the payroll comes with the
        # first of the two tabs, and comes again when its load failed.
        salon = browser.new_page(viewport={'width': 1440, 'height': 900}, locale='ru-RU')
        salon.on('pageerror', lambda e: errors.append(str(e)))
        salon.on('dialog', lambda d: (dialogs.append(d.message), d.dismiss()))
        login(salon, 'salon_armada_mgr')
        sh = Hold(salon)
        tabs = salon.locator('#page-salon_report .tabs .tab')
        try:
            salon.goto(BASE + '/salon_report', wait_until='networkidle')
            sh.watch('payroll')
            tabs.nth(1).click()
            salon.wait_for_timeout(1000)
            check('payroll: the tab requests its data', sh.seen == 1, f'{sh.seen} requests')
            check('payroll: while loading, no claim that there are no data', not claims_empty(salon, '#sr-tab-paysheet'), repr(claims_empty(salon, '#sr-tab-paysheet')))
            sh.release()
            salon.wait_for_load_state('networkidle')
            salon.wait_for_timeout(500)
            sh.watch('payroll')
            tabs.nth(2).click()
            salon.wait_for_timeout(500)
            check('payroll: «История ЗП» uses what came, no second request', sh.seen == 0, f'{sh.seen} requests')
            sh.release()
            salon.goto(BASE + '/salon_report', wait_until='networkidle')
            dialogs.clear()
            sh.watch('payroll', answer=500)
            tabs.nth(2).click()
            salon.wait_for_timeout(1500)
            check('payroll, failed load: the user is told', any('Данные не загружены' in d for d in dialogs), repr(dialogs[:1]))
            check('payroll, failed load: no claim that there are no data', not claims_empty(salon, '#sr-tab-history'), repr(claims_empty(salon, '#sr-tab-history')))
            sh.release()
            tabs.nth(0).click()
            salon.wait_for_timeout(300)
            with salon.expect_request(lambda r: 'payroll' in r.headers.get('x-inertia-partial-data', '').split(','), timeout=5000) as again:
                tabs.nth(1).click()
            check('payroll, failed load: opening the tab again loads it', again.value is not None)
            salon.wait_for_load_state('networkidle')
        except Exception as e:
            sh.release()
            check('payroll: scenario performed', False, f'{type(e).__name__}: {str(e).splitlines()[0][:200]}')
        check('no uncaught error in the page', not errors, repr(errors[:2]))
        browser.close()
    return summary()


if __name__ == '__main__':
    sys.exit(main())
