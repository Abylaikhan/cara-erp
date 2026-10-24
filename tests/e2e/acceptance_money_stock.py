#!/usr/bin/env python3
"""Browser acceptance of the answers of the accountant 23.10 (make e2e: throwaway database, synthetic seed).

№ 37 Начальные остатки: a new cash desk with its opening balance, the balance counts it, the edit is kept after a reload
     (ADR-0047).
№ 52 Частичная оплата ЭСФ: a payment smaller than the invoices covers part of the larger one, the smaller one stays
     unlinked; the link survives a reload (ADR-0048).
№ 45 Приход на склад: a draft keeps the stock; posted from its window it adds the quantity once, with a movement
     (ADR-0049). A line of an ESF is taken in once with «📦 Принять».
№ 65 Выписка Excel: a synthetic Kaspi statement adds its rows to «Сверка с банком», a second upload adds nothing (ADR-0050).
ТБУ  Постоянные затраты: a new article of the month with its plan, «✓ Проведено» puts it in the structure; saved by itself
     and kept after a reload (ADR-0055).
"""
from __future__ import annotations

import datetime as dt
import sys
import tempfile
from pathlib import Path

from playwright.sync_api import Page, expect, sync_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))
from e2e_lib import BASE, ROOT, check, holds, login, sql, summary  # noqa: E402

SHOTS = ROOT / '.local-audit' / 'e2e'


def opening_balance(page: Page, run: str) -> None:
    name = f'Е2Е Касса {run}'
    page.goto(BASE + '/money', wait_until='networkidle')
    page.get_by_role('button', name='+ Касса').click()
    holds('№ 37: «+ Касса» открывает «Новая касса»', lambda: expect(page.locator('#bank-account-modal-title')).to_have_text('Новая касса'))
    page.fill('#ba-name', name)
    page.fill('#ba-balance', '1500.5')
    page.locator('#bankAccountModal button.btn-primary').click()
    expect(page.locator('#bankAccountModal')).to_be_hidden()
    page.reload(wait_until='networkidle')
    card = page.locator('#cash-summary-grid .card', has_text=name)
    holds('№ 37: остаток кассы — её начальный остаток', lambda: expect(card).to_contain_text('₸1 501'))
    card.get_by_role('button', name='✏️').click()
    holds('№ 37: окно показывает сохранённый остаток', lambda: expect(page.locator('#ba-balance')).to_have_value('1500.50'))
    page.fill('#ba-balance', '2000')
    page.locator('#bankAccountModal button.btn-primary').click()
    expect(page.locator('#bankAccountModal')).to_be_hidden()
    check('№ 37: в БД остаток 2000.00 и версия 2', sql(f"SELECT opening_balance, lock_version FROM money_accounts WHERE name = '{name}'") == [['2000.00', '2']])


def partial_payment(page: Page, run: str) -> None:
    acc = sql(f"INSERT INTO money_accounts (name, type, currency, opening_balance, sort_order, created_at, updated_at) VALUES ('Е2Е Банк {run}', 'bank', 'KZT', 0, 90, now(), now()) RETURNING id")[0][0]
    tx = sql(f"INSERT INTO money_transactions (account_id, tx_date, flow, category, amount, counterparty, created_at, updated_at) VALUES ({acc}, '2031-02-20', 'expense', 'supplier', 50000, 'ТОО «Демо Плита»', now(), now()) RETURNING id")[0][0]
    sql(f"INSERT INTO legacy_id_map (source_table, source_key, target_table, target_id, snapshot_id) VALUES ('money_tx', 'e2e-tx-{run}', 'money_transactions', {tx}, 'e2e')")
    page.goto(BASE + '/esf', wait_until='networkidle')
    page.click('#esf-tab-btn-recon')
    page.wait_for_selector('#esf-recon-payments-body tr')
    page.locator('#esf-recon-payments-body tr', has_text='ТОО «Демо Плита»').locator('input[type=checkbox]').check()
    for num in ('1', '2'):
        page.locator('#esf-recon-esf-body tr').filter(has=page.get_by_role('cell', name=num, exact=True)).locator('input[type=checkbox]').first.check()
    page.click('#esf-recon-save-btn')
    holds('№ 52: журнал вкладки — «Готово»', lambda: expect(page.locator('#esf-recon-log')).to_contain_text('▶ Готово'))
    links = sql(f"SELECT i.num, l.amount FROM esf_payment_links l JOIN incoming_invoices i ON i.id = l.invoice_id WHERE l.source_legacy_id = 'e2e-tx-{run}' ORDER BY i.num")
    check('№ 52: ЭСФ на 95 200 оплачена частично (50 000), ЭСФ на 5 600 не привязана', links == [['1', '50000.00']], repr(links))
    page.reload(wait_until='networkidle')
    page.click('#esf-tab-btn-recon')
    page.wait_for_selector('#esf-recon-payments-body tr')
    holds('№ 52: после перезагрузки платёж привязан полностью', lambda: expect(page.locator('#esf-recon-payments-body tr', has_text='ТОО «Демо Плита»')).to_contain_text('✓'))


def stock_receipt(page: Page, run: str) -> None:
    mat_id, qty_before = sql("SELECT id, qty FROM materials WHERE active ORDER BY name, id LIMIT 1")[0]
    page.goto(BASE + '/warehouse', wait_until='networkidle')
    page.locator('#page-warehouse .tab', has_text='Документы').click()
    page.locator('#wh-tab-docs button', has_text='📥 Приход').click()
    num = page.locator('#whdoc-num').input_value()
    page.fill('#whdoc-supplier', 'ТОО «Демо Плита»')
    page.get_by_role('button', name='+ Добавить позицию').click()
    page.locator('#whdoc-items-tbody select').select_option(mat_id)
    page.locator('#whdoc-items-tbody input[type=number]').first.fill('2.5')
    page.get_by_role('button', name='Сохранить черновик').click()
    expect(page.locator('#whDocModal')).to_be_hidden()
    check('№ 45: черновик не меняет остаток', sql(f"SELECT qty FROM materials WHERE id = {mat_id}") == [[qty_before]])
    page.reload(wait_until='networkidle')
    page.locator('#page-warehouse .tab', has_text='Документы').click()
    page.locator('#wh-docs-tbody tr', has_text=num).click()
    page.locator('#whDocViewModal button', has_text='✅ Провести').click()
    expect(page.locator('#whDocViewModal')).to_be_hidden()
    rows = sql(f"SELECT m.qty - {qty_before}, count(s.id) FROM materials m LEFT JOIN stock_movements s ON s.material_id = m.id WHERE m.id = {mat_id} GROUP BY m.qty")
    check('№ 45: проведение прибавило 2,5 один раз и записало движение', rows == [['2.500', '1']], repr(rows))
    page.reload(wait_until='networkidle')
    page.locator('#page-warehouse .tab', has_text='Документы').click()
    holds('№ 45: документ в списке — «Проведён»', lambda: expect(page.locator('#wh-docs-tbody tr', has_text=num)).to_contain_text('Проведён'))


def esf_to_stock(page: Page, run: str) -> None:
    before = sql("SELECT qty FROM materials WHERE name = 'ЛДСП Демо 16мм белый'")[0][0]
    alerts: list[str] = []
    page.on('dialog', lambda d: alerts.append(d.message))
    page.goto(BASE + '/esf', wait_until='networkidle')
    page.locator('tbody tr', has_text='ТОО «Демо Плита»').filter(has=page.get_by_role('cell', name='1', exact=True)).first.click()
    line = page.locator('#esfDetailModal tbody tr', has_text='ЛДСП Демо 16мм белый')
    line.get_by_role('button', name='📦 Принять').click()
    holds('№ 45: у принятой строки — «📦 Принято: ПН-…»', lambda: expect(line).to_contain_text('📦 Принято: ПН-'))
    check('№ 45: legacy-сообщение «Остаток обновлён»', any('Остаток обновлён' in m for m in alerts), repr(alerts[-2:]))
    rows = sql(f"SELECT m.qty - {before}, count(l.id) FROM materials m JOIN stock_document_lines l ON l.material_id = m.id AND l.esf_item_id IS NOT NULL WHERE m.name = 'ЛДСП Демо 16мм белый' GROUP BY m.qty")
    check('№ 45: строка ЭСФ принята один раз (+10)', rows == [['10.000', '1']], repr(rows))


def statement_upload(page: Page, run: str) -> None:
    from openpyxl import Workbook
    acc = sql(f"INSERT INTO money_accounts (name, type, currency, opening_balance, sort_order, created_at, updated_at) VALUES ('Е2Е Kaspi {run}', 'bank', 'KZT', 0, 91, now(), now()) RETURNING id")[0][0]
    sql(f"INSERT INTO legacy_id_map (source_table, source_key, target_table, target_id, snapshot_id) VALUES ('money_accounts', 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'money_accounts', {acc}, 'e2e') ON CONFLICT DO NOTHING")
    acc = sql("SELECT target_id FROM legacy_id_map WHERE source_table = 'money_accounts' AND source_key = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'")[0][0]
    wb = Workbook()
    for row in (['№ документа', 'Дата операции', 'Дебет', 'Кредит', 'Наименование бенефициара / отправителя денег', 'Назначение платежа', 'КНП'],
                [f'Е2Е-{run}-1', '07.08.2031', '1 500,25', '', 'ТОО Е2Е\nИИН/БИН 000000000099', 'Оплата е2е', '710'],
                [f'Е2Е-{run}-2', '08.08.2031', '', '2000', 'ИП Е2Е', 'Поступление е2е', '190']):
        wb.active.append(row)
    path = Path(tempfile.mkdtemp()) / 'kaspi.xlsx'
    wb.save(path)
    alerts: list[str] = []
    page.on('dialog', lambda d: alerts.append(d.message))
    page.goto(BASE + '/money', wait_until='networkidle')
    page.locator('#page-money > .tabs .tab', has_text='Сверка с банком').click()
    page.wait_for_selector('#recon-account-select')
    page.set_input_files('#recon-import-file', str(path))
    for _ in range(50):
        if any('Готово!' in m for m in alerts):
            break
        page.wait_for_timeout(100)
    check('№ 65: «Готово! Добавлено новых операций: 2»', any('Добавлено новых операций: 2' in m for m in alerts), repr(alerts[-2:]))
    rows = sql(f"SELECT flow, amount FROM bank_statement_rows WHERE account_id = {acc} AND doc_num LIKE 'Е2Е-{run}-%' ORDER BY doc_num")
    check('№ 65: строки выписки в сверке счёта Kaspi', rows == [['expense', '1500.25'], ['income', '2000.00']], repr(rows))
    page.set_input_files('#recon-import-file', str(path))
    for _ in range(50):
        if any('новых не найдено' in m for m in alerts):
            break
        page.wait_for_timeout(100)
    check('№ 65: повторная загрузка — «новых не найдено»', any('новых не найдено' in m for m in alerts), repr(alerts[-2:]))


def fixed_costs(page: Page, run: str) -> None:
    name = f'Е2Е Аренда {run}'
    page.goto(BASE + '/finance', wait_until='networkidle')
    page.get_by_text('Точка безубыточности', exact=False).first.click()
    expect(page.locator('#fc-month-select')).to_be_visible()
    page.wait_for_load_state('networkidle')
    page.get_by_role('button', name='+ Статья').click()
    row = page.locator('#fc-plan-tbody tr').last
    row.locator('.fc-name-input').fill(name)
    row.locator('.fc-name-input').press('Tab')
    row.locator('input[type=number]').first.fill('1500')
    row.locator('input[type=checkbox]').check()
    holds('ТБУ: план сохраняется сам — «✅ Сохранено в …»', lambda: expect(page.locator('#fc-period-hint')).to_contain_text('Сохранено в', timeout=10000))
    holds('ТБУ: проведённая статья — в «Структуре», ₸1 500 в месяц и ₸18 000 в год',
          lambda: expect(page.locator('#fixed-costs-tbody tr', has_text=name)).to_contain_text('₸18 000'))
    page.wait_for_timeout(2500)
    page.reload(wait_until='networkidle')
    page.get_by_text('Точка безубыточности', exact=False).first.click()
    page.wait_for_load_state('networkidle')
    holds('ТБУ: после перезагрузки статья на месте и проведена',
          lambda: expect(page.locator('#fixed-costs-tbody tr', has_text=name)).to_contain_text('₸1 500'))
    rows = sql(f"SELECT plan, approved FROM fixed_costs WHERE name = '{name}'")
    check('ТБУ: в БД одна строка, план 1500.00, проведена', rows == [['1500.00', 't']], repr(rows))


def main() -> int:
    run = dt.datetime.now().strftime('%H%M%S')
    SHOTS.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome', headless=True)
        page = browser.new_page(viewport={'width': 1440, 'height': 900}, locale='ru-RU')
        page.on('dialog', lambda d: d.accept())
        errors: list[str] = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        login(page)
        for name, scenario in (('№ 37', opening_balance), ('№ 52', partial_payment), ('№ 45', stock_receipt), ('№ 45 ЭСФ', esf_to_stock), ('№ 65 выписка', statement_upload),
                               ('ТБУ', fixed_costs)):
            try:
                scenario(page, run)
            except Exception as e:  # a step that could not even be performed
                check(f'{name}: сценарий выполнен', False, f'{type(e).__name__}: {str(e).splitlines()[0][:200]}')
                page.screenshot(path=str(SHOTS / f'{name.replace(" ", "-")}-{run}.png'), full_page=True)
        check('нет необработанных ошибок в странице', not errors, repr(errors[:2]))
        browser.close()
    return summary()


if __name__ == '__main__':
    sys.exit(main())
