#!/usr/bin/env python3
"""Browser acceptance of the directories (make e2e: throwaway database, synthetic seed).

AC-16 Товар: create, edit, reopen — prices, category, unit and article are kept after a reload. Choosing it in an order
      comes with the orders (ERP-029, D-02/D-12).
AC-17 Покупатель и дилер: create and edit kept after a reload; a dealer added by hand and from CSV, a repeated CSV adds
      nothing. Salon sellers come with «Сотрудники» (D-13).
AC-58 Слияние поставщиков и статей расходов: the links move to the kept record (ESF to the supplier, the supplier's
      expense item), turnover is summed, the merged record leaves the lists, and the before/after report is stored.
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


def ac16(page: Page, run: str) -> None:
    name = f'Е2Е стол {run}'
    page.goto(BASE + '/catalog', wait_until='networkidle')
    page.get_by_role('button', name='+ Добавить товар').click()
    page.fill('#pm-name', name)
    page.fill('#pm-category', 'Е2Е столы')
    page.fill('#pm-price-retail', '100000')
    page.fill('#pm-price-wholesale', '85000')
    page.select_option('#pm-unit', 'компл')
    page.fill('#pm-sku', f'E2E-{run}')
    page.locator('#productModal button.btn-primary').click()
    expect(page.locator('#productModal')).to_be_hidden()

    page.reload(wait_until='networkidle')
    row = page.locator('#catalog-tbody tr', has_text=name)
    row.get_by_role('button', name='✏️').click()
    holds('AC-16: товар после перезагрузки — цены, категория, единица, артикул', lambda: (
        expect(page.locator('#pm-price-retail')).to_have_value('100000'), expect(page.locator('#pm-price-wholesale')).to_have_value('85000'),
        expect(page.locator('#pm-category')).to_have_value('Е2Е столы'), expect(page.locator('#pm-unit')).to_have_value('компл'),
        expect(page.locator('#pm-sku')).to_have_value(f'E2E-{run}')))
    page.fill('#pm-price-retail', '120000')
    page.locator('#productModal button.btn-primary').click()
    expect(page.locator('#productModal')).to_be_hidden()
    page.reload(wait_until='networkidle')
    page.locator('#catalog-tbody tr', has_text=name).get_by_role('button', name='✏️').click()
    holds('AC-16: правка цены сохранена после перезагрузки', lambda: expect(page.locator('#pm-price-retail')).to_have_value('120000'))
    check('AC-16: в БД одна запись товара с новой ценой', sql(f"SELECT count(*), max(price_retail) FROM products WHERE name = '{name}'") == [['1', '120000.00']])


def ac17(page: Page, run: str) -> None:
    client = f'ТОО «Е2Е Клиент {run}»'
    page.goto(BASE + '/customers', wait_until='networkidle')
    page.get_by_role('button', name='+ Новый контрагент').click()
    page.fill('#prs-name', client)
    page.select_option('#prs-type', 'Юр. лицо')
    page.fill('#prs-idnum', '990000000001')
    page.fill('#prs-phone', '+7 700 000 00 01')
    page.locator('#personModal button.btn-primary').click()
    expect(page.locator('#personModal')).to_be_hidden()
    page.reload(wait_until='networkidle')
    page.locator('#persons-tbody td', has_text=client).first.click()
    holds('AC-17: покупатель после перезагрузки — тип, БИН, телефон', lambda: (expect(page.locator('#prs-type')).to_have_value('Юр. лицо'),
          expect(page.locator('#prs-idnum')).to_have_value('990000000001'), expect(page.locator('#prs-phone')).to_have_value('+7 700 000 00 01')))
    page.fill('#prs-phone', '+7 700 000 00 02')
    page.locator('#personModal button.btn-primary').click()
    expect(page.locator('#personModal')).to_be_hidden()
    page.reload(wait_until='networkidle')
    page.locator('#persons-tbody td', has_text=client).first.click()
    holds('AC-17: правка покупателя сохранена', lambda: expect(page.locator('#prs-phone')).to_have_value('+7 700 000 00 02'))
    page.locator('#personModal .modal-close').click()

    dealer, imported = f'Дилер Е2Е {run}', f'Дилер Е2Е CSV {run}'
    page.click('#cust-tab-dealers')
    page.get_by_role('button', name='+ Подробно').click()
    page.fill('#dlr-name', dealer)
    page.fill('#dlr-region', 'Е2Е-город')
    page.locator('#addDealerModal button.btn-primary').click()
    expect(page.locator('#addDealerModal')).to_be_hidden(timeout=8000)
    holds('AC-17: дилер в списке', lambda: expect(page.locator('#dealers-tbody tr', has_text=dealer)).to_have_count(1))
    csv = Path(tempfile.mkdtemp()) / 'dealers.csv'
    csv.write_text(f'﻿Название дилера;Регион\n{dealer};Е2Е-город\n{imported};Е2Е-город 2\n', encoding='utf-8')
    for attempt, expected in (('первый', '✓ Импортировано 1 запись'), ('повторный', '✓ Импортировано 0 записей')):
        page.set_input_files('#dealer-file', str(csv))
        holds(f'AC-17: {attempt} импорт CSV — «{expected}»', lambda: expect(page.locator('#dealer-import-result')).to_contain_text(expected))
        page.wait_for_load_state('networkidle')
    page.reload(wait_until='networkidle')
    page.click('#cust-tab-dealers')
    holds('AC-17: после перезагрузки два дилера, без дублей', lambda: (expect(page.locator('#dealers-tbody tr', has_text=dealer)).to_have_count(1),
          expect(page.locator('#dealers-tbody tr', has_text=imported)).to_have_count(1)))
    check('AC-17: в БД каналы дилеров без дублей', sql(f"SELECT count(*) FROM sales_channels WHERE name IN ('{dealer}', '{imported}')") == [['2']])


def ac58_suppliers(page: Page, run: str) -> None:
    # A card with the same BIN or name is refused (legacy findSupplierForName), so a duplicate comes with a typo and without
    # the BIN — as after an import; the merge finds it by the similar name.
    kept = 'ТОО «Демо Плмта»'
    merged_id, turnover = sql("SELECT id, turnover FROM suppliers WHERE name = 'ТОО «Демо Плита»' AND active")[0]
    page.goto(BASE + '/suppliers', wait_until='networkidle')
    page.get_by_role('button', name='+ Добавить').click()
    page.fill('#sup-card-name', kept)
    page.locator('#supplierCardModal button.btn-primary').click()
    expect(page.locator('#supplierCardModal')).to_be_hidden(timeout=8000)
    kept_id = sql(f"SELECT id FROM suppliers WHERE name = '{kept}'")[0][0]

    page.get_by_role('button', name='🔗 Объединить').click()
    group = page.locator('#merge-groups-body [data-merge-group]', has_text=kept)
    holds('AC-58: похожие названия — одна группа', lambda: (expect(group).to_contain_text('ТОО «Демо Плита»'), expect(group).to_contain_text('Похожее название')))
    group.locator('label', has_text=kept).locator('input[type=radio]').check()
    group.get_by_role('button', name='🔗 Объединить в выбранного').click()
    page.wait_for_load_state('networkidle')
    page.reload(wait_until='networkidle')
    holds('AC-58: объединённый поставщик ушёл из списка, оставленный на месте', lambda: (
        expect(page.locator('#sup-list-tbody tr', has_text='ТОО «Демо Плита»').filter(has_not_text=kept)).to_have_count(0),
        expect(page.locator('#sup-list-tbody tr', has_text=kept)).to_have_count(1)))
    links = sql(f"SELECT count(*) FILTER (WHERE supplier_id = {kept_id}), count(*) FILTER (WHERE supplier_id = {merged_id}) FROM incoming_invoices WHERE supplier_bin = '000000000011'")
    check('AC-58: ЭСФ поставщика перешли к оставленному', links == [['2', '0']], repr(links))
    after = sql(f"SELECT turnover, bin, (SELECT merged_into_id FROM suppliers WHERE id = {merged_id}), (SELECT active FROM suppliers WHERE id = {merged_id}) FROM suppliers WHERE id = {kept_id}")
    check('AC-58: оборот сложен, пустой БИН дополнен, объединённый помечен и отключён', after == [[turnover, '000000000011', kept_id, 'f']], repr(after))
    report = sql(f"SELECT references_before IS NOT NULL AND references_after IS NOT NULL FROM supplier_merges WHERE supplier_id = {kept_id}")
    check('AC-58: отчёт до/после по поставщикам сохранён', report == [['t']], repr(report))


def ac58_expense_items(page: Page, run: str) -> None:
    a, b = f'Е2Е статья А {run}', f'Е2Е статья Б {run}'
    page.goto(BASE + '/expense_cats', wait_until='networkidle')
    for name, code in ((a, f'e2e_a_{run}'), (b, f'e2e_b_{run}')):
        page.get_by_role('button', name='+ Добавить', exact=True).first.click()
        page.fill('#ec-name', name)
        page.fill('#ec-code', code)
        page.locator('#expCatModal button.btn-primary').click()
        expect(page.locator('#expCatModal')).to_be_hidden()
    a_id, b_id, group = sql(f"SELECT a.id, b.id, a.parent_id FROM expense_categories a, expense_categories b WHERE a.code = 'e2e_a_{run}' AND b.code = 'e2e_b_{run}'")[0]

    page.goto(BASE + '/suppliers', wait_until='networkidle')
    page.locator('#sup-list-tbody tr', has_text='ТОО «Демо Аренда»').click()
    page.select_option('#sup-card-exp-cat', group)
    page.select_option('#sup-card-exp-subcat', b_id)
    page.locator('#supplierCardModal button.btn-primary').click()
    expect(page.locator('#supplierCardModal')).to_be_hidden(timeout=8000)
    check('AC-58: поставщику назначена статья Б', sql("SELECT expense_category_id FROM suppliers WHERE name = 'ТОО «Демо Аренда»'") == [[b_id]])

    alerts: list[str] = []
    page.on('dialog', lambda d: alerts.append(d.message))
    page.goto(BASE + '/expense_cats', wait_until='networkidle')
    page.get_by_role('button', name='🔀 Объединить две статьи').click()
    page.select_option('#ec-merge-from', b_id)
    page.select_option('#ec-merge-to', a_id)
    page.locator('#expCatMergeModal button.btn-primary').click()
    for _ in range(50):  # the message comes after the answer of the server
        if any('перенесены' in m for m in alerts):
            break
        page.wait_for_timeout(100)
    check('AC-58: «Готово — все транзакции перенесены»', any('перенесены' in m for m in alerts), repr(alerts[-2:]))
    page.reload(wait_until='networkidle')
    holds('AC-58: статья Б ушла из справочника', lambda: expect(page.get_by_text(b, exact=True)).to_have_count(0))
    check('AC-58: поставщик теперь на статье А', sql("SELECT expense_category_id FROM suppliers WHERE name = 'ТОО «Демо Аренда»'") == [[a_id]])
    page.goto(BASE + '/suppliers', wait_until='networkidle')
    page.locator('#sup-list-tbody tr', has_text='ТОО «Демо Аренда»').click()
    holds('AC-58: в карточке поставщика статья А', lambda: expect(page.locator('#sup-card-exp-subcat')).to_have_value(a_id))
    report = sql(f"SELECT references_before IS NOT NULL AND references_after IS NOT NULL FROM expense_category_merges WHERE from_id = {b_id} AND to_id = {a_id}")
    check('AC-58: отчёт до/после по статьям сохранён', report == [['t']], repr(report))


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
        for name, scenario in (('AC-16', ac16), ('AC-17', ac17), ('AC-58 поставщики', ac58_suppliers), ('AC-58 статьи', ac58_expense_items)):
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
