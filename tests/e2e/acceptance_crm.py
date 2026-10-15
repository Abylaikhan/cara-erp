#!/usr/bin/env python3
"""Browser acceptance of CRM (TEST-AC-18…20) in the part migrated so far. Runs on the throwaway database of make e2e
(E2E_BASE): synthetic seed, nothing real.

AC-18 Лид → стадии → выигрыш: both views of CRM (list and funnel) show the change, also after a reload; a repeated
      «→ Клиент» does not make a second customer. The order from a won lead comes with the orders (ERP-029, D-02/D-12).
AC-19 Отказ с причиной, сегмент, теги, коммуникация, фильтры: kept after a reload.
AC-20 Задачи по лиду и общие, дата и время, выполнить/вернуть/удалить: counters, overdue and list agree at a fixed time
      (the browser clock is set to today 12:00 in Asia/Almaty, today being the server's business date).
"""
from __future__ import annotations

import datetime as dt
import os
import re
import sys
from pathlib import Path
from zoneinfo import ZoneInfo

from playwright.sync_api import Page, expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ENV = (ROOT / '.env').read_text()
BASE = os.environ.get('E2E_BASE', 'http://127.0.0.1:8091')
PASSWORD = re.search(r'^DEV_ADMIN_PASSWORD=(.*)$', ENV, re.M).group(1).strip()
TZ = ZoneInfo('Asia/Almaty')
TODAY = dt.datetime.now(TZ).date()
NOON = dt.datetime.combine(TODAY, dt.time(12, 0), TZ)
SHOTS = ROOT / '.local-audit' / 'e2e'

results: list[tuple[str, bool, str]] = []
answers: list[str] = []  # what to type into the next prompt


def check(name: str, ok: bool, detail: str = '') -> None:
    results.append((name, ok, detail))
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f' — {detail}' if detail and not ok else ''))


def holds(name: str, fn) -> None:
    """A step that asserts with Playwright's expect (waits up to 5 s)."""
    try:
        fn()
        check(name, True)
    except AssertionError as e:
        lines = str(e).splitlines()
        actual = next((l.strip() for l in lines if l.strip().startswith('Actual value')), '')
        check(name, False, (lines[0][:160] + (' / ' + actual[:80] if actual else '')))


def login(page: Page) -> None:
    page.goto(BASE + '/login', wait_until='networkidle')
    page.fill('#login-username', 'director')
    page.fill('#login-password', PASSWORD)
    page.click('#login-btn')
    page.wait_for_url(lambda u: '/login' not in u)


def crm(page: Page, tab: str) -> None:
    page.goto(BASE + '/crm', wait_until='networkidle')
    page.click(tab)
    page.wait_for_load_state('networkidle')


def new_lead(page: Page, name: str, status: str) -> None:
    crm(page, '#crm-tab-leads')
    page.locator('#orders-leads').get_by_role('button', name='+ Новый лид').click()
    page.fill('#lead-name', name)
    page.fill('#lead-phone', '+7 700 000 00 00')
    page.fill('#lead-budget', '150000')
    page.select_option('#lead-status', status)
    page.locator('#leadModal button.btn-primary').click()
    expect(page.locator('#leadModal')).to_be_hidden()


def lead_row(page: Page, name: str):
    page.click('#leads-view-table')
    return page.locator('#leads-body tr', has_text=name)


def funnel_card(page: Page, stage: str, name: str):
    column = page.locator('.crm-col', has=page.locator('.crm-col-header', has_text=stage))
    return column.locator('.crm-card', has=page.locator('.crm-card-name', has_text=name))


def ac18(page: Page, run: str) -> None:
    a, b = f'Е2Е лид А {run}', f'Е2Е лид Б {run}'
    crm(page, '#crm-tab-leads')
    before = int(page.inner_text('#tab-count-leads'))
    new_lead(page, a, 'new')
    holds('AC-18: новый лид в списке, счётчик вкладки +1', lambda: (expect(lead_row(page, a)).to_have_count(1),
          expect(page.locator('#tab-count-leads')).to_have_text(str(before + 1))))
    lead_row(page, a).locator('select').select_option('contact')
    page.wait_for_load_state('networkidle')
    for attempt in ('сразу', 'после перезагрузки'):
        crm(page, 'text=🎯 Воронка лидов')
        holds(f'AC-18: «Контакт» в списке → «Квалификация» в воронке ({attempt})', lambda: expect(funnel_card(page, 'Квалификация', a)).to_have_count(1))
    funnel_card(page, 'Квалификация', a).get_by_text('✅ Выиграли').click()
    holds('AC-18: выигрыш предлагает заказ покупателя', lambda: expect(page.locator('#crm-convert-label')).to_contain_text('выиграли'))
    page.locator('#crmConvertModal').get_by_text('Не сейчас').click()
    page.reload(wait_until='networkidle')
    page.click('text=🎯 Воронка лидов')
    holds('AC-18: выигранный лид ушёл из воронки (после перезагрузки)', lambda: expect(page.locator('.crm-card-name', has_text=a)).to_have_count(0))
    page.click('#crm-tab-leads')
    page.select_option('#leads-status-filter', 'won')
    holds('AC-18: в списке он «Выиграли»', lambda: expect(lead_row(page, a)).to_contain_text('Выиграли'))

    new_lead(page, b, 'new')
    page.click('#leads-view-cards')
    sent: list[str] = []
    count = lambda r: sent.append(r.url) if r.method == 'POST' and r.url.endswith('/client') else None
    page.on('request', count)
    page.locator('#leads-body .card, #orders-leads .card', has_text=b).get_by_text('→ Клиент').dblclick()
    page.wait_for_load_state('networkidle')
    page.remove_listener('request', count)
    page.goto(BASE + '/customers', wait_until='networkidle')
    holds(f'AC-18: двойное «→ Клиент» ({len(sent)} запроса) — один покупатель', lambda: expect(page.get_by_text(b, exact=True)).to_have_count(1))
    check('AC-18: второй запрос дошёл до сервера и был отклонён (не создал дубль)', len(sent) == 2, f'{len(sent)} requests')


def ac19(page: Page, run: str) -> None:
    c, d = f'Е2Е лид В {run}', f'Е2Е лид Г {run}'
    new_lead(page, c, 'contact')
    answers.append('Дорого')
    lead_row(page, c).locator('select').select_option('lost')
    page.wait_for_load_state('networkidle')
    crm(page, '#crm-tab-leads')
    page.select_option('#leads-status-filter', 'lost')
    holds('AC-19: отказ — статус «Проиграли» после перезагрузки', lambda: expect(lead_row(page, c)).to_contain_text('Проиграли'))
    lead_row(page, c).get_by_text('✏️').click()
    holds('AC-19: причина отказа сохранена в комментарии', lambda: expect(page.locator('#lead-comment')).to_have_value(re.compile('Отказ: Дорого')))
    page.locator('#leadModal .modal-close').click()

    new_lead(page, d, 'contact')
    crm(page, 'text=🎯 Воронка лидов')
    page.locator('.crm-row-label-name', has_text=d).click()
    segment = page.locator('#cl-segment option').nth(1)
    segment_label = segment.inner_text().strip()
    page.select_option('#cl-segment', segment.get_attribute('value'))
    page.fill('#cl-tag-input', 'е2е-тег')
    page.press('#cl-tag-input', 'Enter')
    page.locator('#crmLeadModal button.btn-primary').click()
    expect(page.locator('#crmLeadModal')).to_be_hidden()
    crm(page, 'text=🎯 Воронка лидов')
    card = funnel_card(page, 'Квалификация', d)
    holds('AC-19: сегмент и тег на карточке после перезагрузки', lambda: (expect(card.locator('.segment-badge')).to_have_text(segment_label),
          expect(card.locator('.tag-chip')).to_contain_text('е2е-тег')))
    page.locator('#crm-segment-filter-bar button', has_text=segment_label).click()
    holds('AC-19: фильтр по сегменту оставляет лида', lambda: expect(page.locator('.crm-card-name', has_text=d)).to_have_count(1))
    page.locator('#crm-segment-filter-bar button', has_text='Все').click()
    page.locator('#crm-tag-filter-bar .tag-chip', has_text='е2е-тег').click()
    holds('AC-19: фильтр по тегу — только этот лид', lambda: expect(page.locator('.crm-card')).to_have_count(1))

    crm(page, 'text=🎯 Воронка лидов')
    funnel_card(page, 'Квалификация', d).locator('.crm-card-detail-btn').click()
    page.locator('#crm-popover').get_by_role('button', name='+ Добавить', exact=True).click()
    page.locator('#crm-comm-type-btns button').first.click()
    page.fill('#crm-comm-text', f'Позвонили {run}, ждут КП')
    page.locator('#crm-comm-add-form').get_by_text('Сохранить').click()
    holds('AC-19: коммуникация в истории', lambda: expect(page.locator('#crm-comm-timeline')).to_contain_text(f'Позвонили {run}'))
    crm(page, 'text=🎯 Воронка лидов')
    funnel_card(page, 'Квалификация', d).locator('.crm-card-detail-btn').click()
    holds('AC-19: история коммуникаций после перезагрузки', lambda: expect(page.locator('#crm-comm-timeline')).to_contain_text(f'Позвонили {run}'))

    crm(page, '#crm-tab-leads')
    page.fill('#leads-search', d)
    holds('AC-19: поиск в списке находит только лида', lambda: (expect(lead_row(page, d)).to_have_count(1), expect(page.locator('#leads-body tbody tr')).to_have_count(1)))


def new_task(page: Page, title: str, day: dt.date, time: str, lead: str | None = None) -> None:
    page.locator('#orders-tasks').get_by_role('button', name='+ Новая задача').click()
    page.fill('#task-title', title)
    page.fill('#task-date', day.isoformat())
    page.fill('#task-time', time)
    if lead:
        page.fill('#task-lead-search', lead)
        page.locator('.task-lead-option', has_text=lead).click()
    page.locator('#taskModal button.btn-primary').click()
    expect(page.locator('#taskModal')).to_be_hidden()


def ac20(page: Page, run: str) -> None:
    d = f'Е2Е лид Г {run}'
    yesterday, tomorrow = TODAY - dt.timedelta(days=1), TODAY + dt.timedelta(days=1)
    crm(page, 'text=✅ Задачи')
    new_task(page, f'Е2Е общая вчера {run}', yesterday, '09:00')
    new_task(page, f'Е2Е общая сегодня утром {run}', TODAY, '11:00')
    new_task(page, f'Е2Е общая сегодня вечером {run}', TODAY, '18:00')
    new_task(page, f'Е2Е по лиду завтра {run}', tomorrow, '10:00', d)
    crm(page, 'text=✅ Задачи')
    holds('AC-20: счётчики: по лидам 1, общие 3, открытых 4', lambda: (expect(page.locator('#tasks-count-lead')).to_have_text('1'),
          expect(page.locator('#tasks-count-general')).to_have_text('3'), expect(page.locator('#tasks-open-count')).to_have_text('4')))
    holds('AC-20: просрочены 2 (вчера и сегодня 11:00 при 12:00)', lambda: expect(page.locator('#tasks-overdue-banner')).to_contain_text('Просрочено задач: 2'))
    page.locator('#tasks-subtabs .tab', has_text='Общие').click()
    late = page.locator('.task-card', has_text=f'Е2Е общая вчера {run}')
    holds('AC-20: в списке просроченная помечена', lambda: expect(late).to_contain_text('просрочена'))
    late.locator('.task-checkbox').click()
    page.wait_for_load_state('networkidle')
    crm(page, 'text=✅ Задачи')
    holds('AC-20: выполнена — просрочено 1, открытых 3 (после перезагрузки)', lambda: (expect(page.locator('#tasks-overdue-banner')).to_contain_text('Просрочено задач: 1'),
          expect(page.locator('#tasks-open-count')).to_have_text('3')))
    page.select_option('#tasks-status-filter', 'all')
    page.locator('#tasks-subtabs .tab', has_text='Общие').click()
    page.locator('.task-card', has_text=f'Е2Е общая вчера {run}').locator('.task-checkbox').click()
    page.wait_for_load_state('networkidle')
    holds('AC-20: возвращена в работу — снова просрочено 2, открытых 4', lambda: (expect(page.locator('#tasks-overdue-banner')).to_contain_text('Просрочено задач: 2'),
          expect(page.locator('#tasks-open-count')).to_have_text('4')))
    page.locator('.task-card', has_text=f'Е2Е общая сегодня утром {run}').get_by_text('✏️').click()
    page.click('#task-delete-btn')
    expect(page.locator('#taskModal')).to_be_hidden()
    crm(page, 'text=✅ Задачи')
    holds('AC-20: удалена — общие 2, открытых 3, просрочено 1 (после перезагрузки)', lambda: (expect(page.locator('#tasks-count-general')).to_have_text('2'),
          expect(page.locator('#tasks-open-count')).to_have_text('3'), expect(page.locator('#tasks-overdue-banner')).to_contain_text('Просрочено задач: 1')))


def main() -> int:
    run = dt.datetime.now().strftime('%H%M%S')
    SHOTS.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome', headless=True)
        context = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ru-RU', timezone_id='Asia/Almaty')
        context.clock.set_fixed_time(NOON)
        page = context.new_page()
        page.on('dialog', lambda d: d.accept(answers.pop(0)) if d.type == 'prompt' and answers else d.accept())
        errors: list[str] = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        login(page)
        for name, scenario in (('AC-18', ac18), ('AC-19', ac19), ('AC-20', ac20)):
            try:
                scenario(page, run)
            except Exception as e:  # a step that could not even be performed
                check(f'{name}: сценарий выполнен', False, f'{type(e).__name__}: {str(e).splitlines()[0][:200]}')
                page.screenshot(path=str(SHOTS / f'{name}-{run}.png'), full_page=True)
        check('нет необработанных ошибок в странице', not errors, repr(errors[:2]))
        browser.close()
    failed = [r for r in results if not r[1]]
    print(f'\n{len(results) - len(failed)}/{len(results)} checks passed')
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
