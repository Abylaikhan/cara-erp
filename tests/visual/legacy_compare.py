"""Visual comparison legacy ↔ target (ТЗ UI-03): login, M14 «Статьи расходов», M07 «Каталог», M08 «Покупатели» with
the dealers tab, M06 «Поставщики», M03 «Склад», M05 «Входящие ЭСФ», M10 CRM «Лиды», «Воронка лидов», «Задачи», M04 «Закупки», M09 «Продажи», M02 «Производство», M12 «Деньги» (and their modals),
dark and light themes, 1440×900 (another size: VIEWPORT=<width>x<height>).

Legacy runs from the accepted reference in .local-audit/blobs with every network request blocked except Google Fonts —
it never reaches the legacy backend. Both sides show the same rows: legacy gets exactly the data the target renders.
Requires host python3 with `pip install playwright==1.55.0 pillow` and Google Chrome; the app must be up (make up)
and seeded. Interim tool until the Dockerised TGT-VISUAL suite exists. Nothing is saved (the plans it asks for are
not applied), so it runs on the dev application with the copy of the real data.

    python3 tests/visual/legacy_compare.py            # → .local-audit/visual/<time>/, prints % of differing pixels
    make visual-empty                                 # AC-07: the empty states — a throwaway database with the users only
"""
import collections, datetime, hashlib, json, os, re, sys, time
from pathlib import Path
from PIL import Image, ImageChops
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
CFG = json.loads((ROOT / 'tools/harness/config.json').read_text())
# The accepted reference (config: source.accepted_ref), not the last observed legacy: the port follows the reference until a
# separate decision moves it.
ACCEPTED = CFG['source']['accepted_ref']['sha256']
BLOB = next(b for b in sorted((ROOT / '.local-audit/blobs').glob('*.html')) if hashlib.sha256(b.read_bytes()).hexdigest() == ACCEPTED)
OUT = ROOT / '.local-audit/visual' / (datetime.datetime.now().strftime('%Y%m%dT%H%M%S') + '-' + os.environ.get('VIEWPORT', '1440x900')
                                       + ('-e2e' if os.environ.get('E2E_BASE') else ''))
OUT.mkdir(parents=True, exist_ok=True)
LEGACY = BLOB.as_uri()
ENV = (ROOT / '.env').read_text()
# AC-04/05: the same comparison at another size of the window (VIEWPORT=1920x1080, 900x900, 899x900, 390x844).
_vw, _vh = (int(x) for x in os.environ.get('VIEWPORT', '1440x900').lower().split('x'))
VIEWPORT = {'width': _vw, 'height': _vh}
# make e2e passes its instance (E2E_BASE): a throwaway database instead of the dev one — make visual-empty.
TARGET = os.environ.get('E2E_BASE') or 'http://localhost:' + (re.search(r'^APP_PORT=(\d+)', ENV, re.M) or [None, '8080'])[1]
DEV_PASSWORD = re.search(r'^DEV_ADMIN_PASSWORD=(.*)$', ENV, re.M).group(1).strip()
# Both sides must show the same rows: the legacy page receives exactly the directory the target renders.
def target_categories(page):
    data = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")
    return [dict(code=c['code'], name=c['name'], parent_code=c['parent_code'], is_fixed=c['is_fixed'], sort_order=c['sort_order'])
            for c in data['props']['categories']]

# make visual-empty (E2E_SEED=users): the target has no business data, and legacy reads an empty database — its requests for
# rows get an empty list, as from an empty Supabase (a blocked request would leave its loaders failing instead).
EMPTY = os.environ.get('E2E_SEED') == 'users'
# The empty states legacy never reaches (its loaders take an empty answer for «not loaded yet» and ask again forever).
EMPTY_DEVIATIONS = {'sales-journal': 'ADR-0002, item 87', 'finance-cfs': 'ADR-0002, item 88'}


# Legacy asks again without end for some empty tables (ADR-0002, items 87, 88). Answered at once, such a loop floods the
# routing and holds up the other requests of the page. A loop is told from the loads every page makes of what is still
# empty by its rate: more than LOOP requests of one table within LOOP_S seconds. Its next request is left pending — the
# screen stays as legacy keeps it (loading) and the loop stops; the other requests are answered.
LOOP, LOOP_S = 20, 3.0
_asked: dict = collections.defaultdict(collections.deque)

# AC-08 (FILTERS=1): filters, sorts and their totals of the migrated pages, legacy ↔ target, first theme only
# (tests/visual/filters.py). Not on the empty database: there is nothing to filter.
FILTERS = None
if os.environ.get('FILTERS') == '1' and not EMPTY:
    sys.path.insert(0, str(ROOT / 'tests/visual'))
    import filters as _filters
    from hlib import load_config
    _inventory, _, _blob = _filters.registry.accepted_inventory(ROOT, load_config(local=False))
    FILTERS = _filters.Filters(_inventory, _blob)


def run_filters(page, side, theme, container, exclude=()):
    if FILTERS is not None and theme == 'dark':
        FILTERS.run(page, side, container, exclude)


def run_drill(page, side, theme, container):
    if FILTERS is not None and theme == 'dark':
        FILTERS.run_drill(page, side, container)


def guard(route):
    url = route.request.url
    if url.startswith('file://') or 'fonts.googleapis.com' in url or 'fonts.gstatic.com' in url:
        return route.continue_()
    if EMPTY and route.request.method == 'GET' and '/rest/v1/' in url:
        recent, now = _asked[url.split('/rest/v1/', 1)[1]], time.monotonic()
        recent.append(now)
        while now - recent[0] > LOOP_S:
            recent.popleft()
        if len(recent) > LOOP:
            print(f"legacy asks again without end: {url.split('/rest/v1/', 1)[1][:60]} (left pending)", file=sys.stderr)
            recent.clear()
            return None
        return route.fulfill(json=[])
    return route.abort()

def unscroll(page):
    """Legacy keeps one scroll position for all its pages: a long page leaves the next one scrolled. Back to the top and the
    left edge, as a user opening the page sees it (modal windows keep theirs). Both sides: a click of the target scrolls the
    clicked tab or button into view — on a narrow window sideways — where legacy is driven by calls that do not scroll."""
    page.evaluate("document.querySelectorAll('*').forEach(e => { if ((e.scrollTop || e.scrollLeft) && !e.closest('.modal-overlay')) { e.scrollTop = 0; e.scrollLeft = 0; } }); window.scrollTo(0, 0)")


def rest_mouse(page):
    """Legacy is driven by calls and its pointer never moves; the clicks of the target leave the pointer over a button (hover
    colours). Before a screenshot of the target the pointer leaves the window, and the hover transition (.15s) ends."""
    page.mouse.move(-10, -10)
    page.wait_for_timeout(300)


def target_shot(page, path):
    """A screenshot of the target after the same reset of the scroll positions as legacy (unscroll), the pointer away."""
    unscroll(page)
    rest_mouse(page)
    page.screenshot(path=path)

def diff(a, b, name):
    ia, ib = Image.open(a).convert('RGB'), Image.open(b).convert('RGB')
    w, h = min(ia.width, ib.width), min(ia.height, ib.height)
    ia, ib = ia.crop((0, 0, w, h)), ib.crop((0, 0, w, h))
    d = ImageChops.difference(ia, ib).convert('L').point(lambda v: 255 if v > 24 else 0)
    changed = sum(1 for v in d.getdata() if v)
    canvas = Image.new('RGB', (w * 3, h)); canvas.paste(ia, (0, 0)); canvas.paste(ib, (w, 0)); canvas.paste(Image.merge('RGB', (d, d.point(lambda v: 0), d.point(lambda v: 0))), (2 * w, 0))
    canvas.save(OUT / f'{name}-side-by-side.png')
    return round(100 * changed / (w * h), 2)

# Labels of the money expense categories (config/erp.php) → their codes, for the dashboard of legacy.
EXPENSE_CODES = {icon + ' ' + label: code for code, icon, label in re.findall(r"'([a-z_]+)' => \['([^']*)', '([^']+)'\]", (ROOT / 'config/erp.php').read_text().split("'money_expense_categories'")[1].split('\n    ],')[0])}
results = {}
with sync_playwright() as p:
    browser = p.chromium.launch(channel='chrome', headless=True)
    for theme in ('dark', 'light'):
        # ---------------- target
        ctx = browser.new_context(viewport=VIEWPORT, locale='ru-RU')
        page = ctx.new_page()
        page.add_init_script(f"localStorage.setItem('erp_theme', '{theme}')")
        page.goto(TARGET + '/login', wait_until='networkidle'); page.evaluate("document.fonts.ready"); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-login-{theme}.png'))
        page.fill('#login-username', 'director'); page.fill('#login-password', DEV_PASSWORD); page.click('#login-btn')
        page.wait_for_url('**/dashboard'); page.goto(TARGET + '/expense_cats', wait_until='networkidle'); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-m14-{theme}.png'))
        cats = target_categories(page)
        first = next(iter(sorted((c for c in cats if c['parent_code']), key=lambda c: (c['sort_order'], c['code']))), {}).get('code')
        if first:  # an empty directory has no item to open
            page.locator('#ec-table-wrap').get_by_text(first, exact=True).locator('xpath=ancestor::tr').locator('button').dispatch_event('click')  # no scroll: legacy opens it in place; page.wait_for_timeout(300)
            target_shot(page, str(OUT / f'target-m14-modal-{theme}.png'))
            page.locator('#ec-modal .modal-close, .modal-overlay.open .modal-close').first.click(); page.wait_for_timeout(200)
        # Filters of «Статьи расходов» (search, category, «Только постоянные»): the table text for the same combinations.
        first_top = page.evaluate("document.querySelector('#ec-filter-parent option:nth-child(2)')?.value || ''")
        target_ec = []
        for q, parent, fixed in [('', '', False), ('эл', '', False), ('', 'FIRSTTOP', False), ('', '', True), ('а', 'FIRSTTOP', True)]:
            page.fill('#ec-search', q); page.select_option('#ec-filter-parent', first_top if parent else '')
            if page.is_checked('#ec-show-fixed') != fixed: page.click('#ec-show-fixed')
            page.wait_for_timeout(200); target_ec.append(page.locator('#ec-table-wrap').inner_text())
        page.goto(TARGET + '/catalog', wait_until='networkidle'); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-catalog-{theme}.png'))
        run_filters(page, 'target', theme, 'page-catalog')
        products = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']['products']
        product_id = products[0]['id'] if products else None
        if product_id is not None:
            page.locator('#catalog-tbody tr').first.locator('button').first.dispatch_event('click'); page.wait_for_timeout(300)
            target_shot(page, str(OUT / f'target-catalog-modal-{theme}.png'))
        page.goto(TARGET + '/customers', wait_until='networkidle'); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-customers-{theme}.png'))
        run_filters(page, 'target', theme, 'cust-persons')
        props = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        persons, dealers = props['persons'], props['dealers']
        # Dealer counts as the target makes them for «Текущий год» (DealersTab: orders of the channel; an order without
        # «Срок» counts in every period; prepayment weighted by the totals).
        year = props['today'][:4]
        dealer_stats = {}
        for d in dealers:
            matched = [o for o in props['dealerOrders'] if o['channel'] == d['code'] and (not o['date'] or o['date'][:4] == year)]
            turnover = sum(o['total'] for o in matched)
            weighted = sum((o['payment'] or 0) * o['total'] for o in matched)
            dealer_stats[d['code']] = {'orders': len(matched), 'turnover': turnover, 'prepay': round(weighted / turnover + 1e-9) if turnover > 0 else 0}
        if persons:
            page.locator('#persons-tbody tr').first.locator('button').dispatch_event('click'); page.wait_for_timeout(300)
            target_shot(page, str(OUT / f'target-customers-modal-{theme}.png'))
            page.locator('#personModal .modal-close').click(); page.wait_for_timeout(200)
        page.locator('#cust-tab-dealers').click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-dealers-{theme}.png'))
        page.locator('#cust-dealers button', has_text='+ Подробно').click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-dealer-modal-{theme}.png'))
        # «Сверки»: the empty state as a picture; the act with a dealer as text — KPIs, footer and rows without the running
        # balance, in any order (legacy sorts «дд.мм.гггг» as text, the target by date — ADR-0002).
        page.locator('#cust-dealers .modal-close, #addDealerModal .modal-close').first.click(); page.wait_for_timeout(200)
        page.locator('#cust-tab-reconcile').click(); page.wait_for_timeout(2000)
        target_shot(page, str(OUT / f'target-cust-reconcile-{theme}.png'))
        rec_orders = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/customers', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Customers',
                'X-Inertia-Partial-Data': 'reconcileOrders', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.reconcileOrders;
        }""")
        page.select_option('#rec-type', 'dealer'); page.wait_for_timeout(200); page.select_option('#rec-party', 'dealer_astana'); page.wait_for_timeout(800)
        target_rec_act = (page.locator('#rec-kpi').inner_text(), page.locator('#rec-tfoot').inner_text(), page.evaluate("""() => [...document.querySelectorAll('#rec-tbody tr')].map(tr => [...tr.children].slice(0, 5).map(td => td.textContent.replace(/\\s+/g, ' ').trim()).join('|')).sort()"""))
        page.goto(TARGET + '/suppliers', wait_until='networkidle'); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-suppliers-{theme}.png'))
        run_filters(page, 'target', theme, 'sup-tab-list')
        suppliers = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']['suppliers']
        plain = next((i for i, x in enumerate(suppliers) if not x['expense_category_id']), None)  # the card without an expense item
        if plain is not None:
            page.locator('#sup-list-tbody tr').nth(plain).dispatch_event('click'); page.wait_for_timeout(300)
            target_shot(page, str(OUT / f'target-supplier-card-{theme}.png'))
            page.locator('#supplierCardModal .modal-close').click(); page.wait_for_timeout(200)
        page.locator('#page-suppliers .tab').nth(1).click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-suppliers-categories-{theme}.png'))
        page.locator('#page-suppliers .tab').nth(2).click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-suppliers-rating-{theme}.png'))
        page.locator('#page-suppliers button', has_text='🔗 Объединить').click(); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-supplier-merge-{theme}.png'))
        page.goto(TARGET + '/warehouse', wait_until='networkidle'); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-warehouse-{theme}.png'))
        wh_props = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        materials, aliases = wh_props['materials'], wh_props['aliases']
        for i, shot in ((1, 'categories'), (2, 'workshops'), (3, 'docs'), (4, 'remains'), (7, 'aliases')):
            page.locator('#page-warehouse .tab').nth(i).click(); page.wait_for_timeout(800 if shot == 'remains' else 300)
            target_shot(page, str(OUT / f'target-warehouse-{shot}-{theme}.png'))
            if shot in ('remains', 'aliases'):
                run_filters(page, 'target', theme, 'wh-tab-' + shot)
        page.locator('#page-warehouse button', has_text='+ Материал').click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-warehouse-add-{theme}.png'))
        page.locator('#mat-category-grid button').first.click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-warehouse-add-step2-{theme}.png'))  # not compared: documented deviation (ADR-0002, item 27)
        page.locator('#addMaterialModal .modal-close').click()
        page.goto(TARGET + '/esf', wait_until='networkidle'); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-esf-{theme}.png'))
        run_filters(page, 'target', theme, 'esf-tab-list')
        invoices = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']['invoices']
        esf_detail = page.evaluate(f"fetch('/esf/{invoices[0]['id']}/detail', {{headers: {{Accept: 'application/json'}}}}).then(r => r.json())") if invoices else None
        if invoices:
            page.locator('#esf-list tbody tr').first.locator('td').nth(1).click(); page.wait_for_timeout(500)
            target_shot(page, str(OUT / f'target-esf-detail-{theme}.png'))
            page.locator('#esfDetailModal .modal-close').click(); page.wait_for_timeout(200)
        page.set_input_files('#esf-xml-input', str(ROOT / 'tests/Fixtures/esf/two-invoices.xml')); page.wait_for_timeout(1000)
        target_shot(page, str(OUT / f'target-esf-import-{theme}.png'))
        page.goto(TARGET + '/crm', wait_until='networkidle'); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-crm-{theme}.png'))
        crm = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        first_lead = page.evaluate("(leads) => (leads.find(l => l.status !== 'won' && l.status !== 'lost' && l.created_at && new Date(l.created_at).getFullYear() === new Date().getFullYear()) || {}).id ?? null", crm['leads'])
        page.locator('#crm-tab-leads').click(); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-crm-leads-{theme}.png'))
        page.locator('#leads-view-table').click(); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-crm-leads-table-{theme}.png'))
        run_filters(page, 'target', theme, 'orders-leads')
        page.locator('#leads-view-cards').click(); page.wait_for_timeout(500)
        if first_lead is not None:
            page.locator('#leads-body .card').first.locator('button', has_text='✏️ Изменить').click(); page.wait_for_timeout(300)
            target_shot(page, str(OUT / f'target-crm-lead-modal-{theme}.png'))
            page.locator('#leadModal .modal-close').click(); page.wait_for_timeout(200)
        page.locator('#page-crm .tab', has_text='Воронка лидов').click(); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-crm-funnel-{theme}.png'))
        run_filters(page, 'target', theme, 'orders-funnel')
        page.locator('#orders-funnel button', has_text='+ Новый лид').click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-crm-funnel-modal-{theme}.png'))
        page.locator('#crmLeadModal .modal-close').click(); page.wait_for_timeout(200)
        funnel = [l for l in crm['leads'] if l['status'] in ('qualify', 'proposal', 'negotiation', 'contact', 'meeting')]
        stage_of = lambda l: next(s['key'] for s in crm['funnelStages'] if l['status'] in s['statuses'])
        first_card = next((l['id'] for s in crm['funnelStages'] for l in funnel if stage_of(l) == s['key']), None)  # DOM order: column by column
        if first_card is not None:
            # The popover is placed by its button when it opens: reset the scroll first and open it without scrolling (a DOM
            # click; Playwright's click would scroll the board sideways on a narrow window), as legacy opens it by a call.
            unscroll(page); page.evaluate("document.querySelector('#crm-kanban-board .crm-card-detail-btn').click()"); page.wait_for_timeout(600)
            rest_mouse(page); page.screenshot(path=str(OUT / f'target-crm-popover-{theme}.png'))
            page.evaluate("document.querySelector('#crm-popover .crm-popover-close').click()"); page.wait_for_timeout(200)  # off-screen on a narrow window
        page.locator('#page-crm .tab', has_text='Задачи').click(); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-crm-tasks-{theme}.png'))
        run_filters(page, 'target', theme, 'orders-tasks')
        page.locator('#orders-tasks button', has_text='+ Новая задача').click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-crm-task-modal-{theme}.png'))
        page.locator('#taskModal .modal-close').click(); page.wait_for_timeout(200)
        page.locator('#crm-tab-clients').click(); page.wait_for_timeout(2500)
        target_shot(page, str(OUT / f'target-crm-clients-{theme}.png'))
        run_filters(page, 'target', theme, 'orders-clients')
        # A client row has its card button; an empty list has a row with the message only.
        first_client = page.evaluate("document.querySelector('#clients-tbody tr button')?.closest('tr').querySelector('td').textContent.trim() || null")
        if first_client:
            page.locator('#clients-tbody tr').first.locator('button').click(); page.wait_for_timeout(300)
            target_shot(page, str(OUT / f'target-crm-client-card-{theme}.png'))
            page.locator('#client-card-modal .modal-close').click(); page.wait_for_timeout(200)
        for i, shot in ((4, 'prod'), (5, 'pay')):
            page.locator('#page-crm .tabs .tab').nth(i).click(); page.wait_for_timeout(2500)
            target_shot(page, str(OUT / f'target-crm-board-{shot}-{theme}.png'))
            run_filters(page, 'target', theme, 'orders-orders-kanban-' + shot)
        # «Проекты» (c917d37): the list (no projects in legacy) and the new project window.
        page.locator('#crm-tab-projects').click(); page.wait_for_timeout(1500)
        target_shot(page, str(OUT / f'target-crm-projects-{theme}.png'))
        page.locator('#orders-projects button', has_text='+ Новый проект').click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-crm-project-new-{theme}.png'))
        page.locator('#prjModal .modal-close').click(); page.wait_for_timeout(200)
        board_orders = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/crm', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Crm',
                'X-Inertia-Partial-Data': 'boardOrders', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.boardOrders;
        }""")
        client_orders = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/crm', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Crm',
                'X-Inertia-Partial-Data': 'clientOrders', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.clientOrders;
        }""")
        page.goto(TARGET + '/purchases', wait_until='networkidle'); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-purchases-{theme}.png'))
        run_filters(page, 'target', theme, 'purch-extra-requests')
        purchases = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']['requests']
        page.locator('#mreq-view-kanban-btn').click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-purchases-kanban-{theme}.png'))
        page.locator('#mreq-view-table-btn').click()
        page.locator('#page-purchases > .tabs .tab').nth(2).click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-purchases-planfact-{theme}.png'))
        run_filters(page, 'target', theme, 'purch-extra-planfact')
        page.locator('#page-purchases > .tabs .tab').nth(0).click()
        page.locator('#purch-extra-requests button', has_text='+ Заявка вручную').click(); page.wait_for_timeout(300)
        target_shot(page, str(OUT / f'target-purchases-modal-{theme}.png'))
        page.goto(TARGET + '/sales', wait_until='networkidle'); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-sales-{theme}.png'))
        run_filters(page, 'target', theme, 'orders-all-orders')
        sales = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']['orders']
        breakdown = page.evaluate("document.querySelector('#ord-summary-stats span:nth-child(3)')?.textContent || ''")
        for i, shot in ((1, 'queue'), (3, 'done')):
            page.locator('#sales-tabs .tab').nth(i).click(); page.wait_for_timeout(800)
            target_shot(page, str(OUT / f'target-sales-{shot}-{theme}.png'))
        page.locator('#sales-tabs .tab').nth(4).click(); page.wait_for_timeout(1500)
        unscroll(page); page.evaluate("document.getElementById('sj-totals').scrollIntoView()"); page.wait_for_timeout(300)  # the filters fill the first screen
        rest_mouse(page); page.screenshot(path=str(OUT / f'target-sales-journal-{theme}.png'))  # scrolled to the totals on purpose, as legacy
        run_filters(page, 'target', theme, 'orders-sales-journal')
        realizations = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/sales', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Sales',
                'X-Inertia-Partial-Data': 'realizations', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.realizations;
        }""")
        page.locator('#sales-tabs .tab').nth(7).click(); page.wait_for_timeout(2000)
        target_shot(page, str(OUT / f'target-sales-report-{theme}.png'))
        run_filters(page, 'target', theme, 'orders-report')
        report = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/sales', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Sales',
                'X-Inertia-Partial-Data': 'report', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.report;
        }""")
        page.goto(TARGET + '/mrp', wait_until='networkidle'); page.wait_for_timeout(1500)
        target_shot(page, str(OUT / f'target-mrp-{theme}.png'))
        run_filters(page, 'target', theme, 'mrp-orders')
        mrp = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']['positions']
        page.locator('#mrp-orders > .tabs .tab').nth(1).click(); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-mrp-queue-{theme}.png'))
        page.goto(TARGET + '/money', wait_until='networkidle'); page.wait_for_timeout(1500)
        target_shot(page, str(OUT / f'target-money-{theme}.png'))
        run_filters(page, 'target', theme, 'money-tab-cash')
        money = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        # Reference c54cf25: the running balance over the whole history and «👥» only on salary expenses of the salary cash
        # (the table as text, every cash desk), and «🔁 Сверка переводов» of the salary cash.
        target_cash = []
        for v in page.locator('#cash-account-select option').evaluate_all('os => os.map(o => o.value)'):
            page.select_option('#cash-account-select', v); page.wait_for_timeout(400)
            target_cash.append(page.locator('#cash-tbody').inner_text())
        if page.locator('#cash-account-select option').count():  # an empty database has no cash desk
            page.select_option('#cash-account-select', index=0); page.wait_for_timeout(300)
        # Period «День» (today) and «Месяц» (the list, the current month, the latest month with operations), then «Всё время».
        def period_states(side_set):
            out = []
            for step in ('day', 'month', 'month-2'):
                side_set(step); page.wait_for_timeout(500)
                out.append([page.locator('#mp-period-label').inner_text(), page.locator('#cash-tbody').inner_text(),
                            '' if step == 'day' else page.locator('#mp-month').evaluate('s => [...s.options].map(o => o.value + "|" + o.text).join(";")')])
            side_set('all'); page.wait_for_timeout(400)
            return out
        def target_set(step):
            if step == 'day': page.locator('#mp-btn-day').click()
            elif step == 'month': page.locator('#mp-btn-month').click()
            elif step == 'month-2':
                opts = page.locator('#mp-month option').evaluate_all('os => os.map(o => o.value)')
                page.select_option('#mp-month', opts[min(1, len(opts) - 1)])
            else: page.locator('#mp-btn-all').click()
        target_period = period_states(target_set)
        page.get_by_role('button', name='🔁 Сверка переводов').click(); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-money-zprecon-{theme}.png'))
        target_zr = page.locator('#zr-body').inner_text()
        page.locator('#zpReconModal .modal-close').click(); page.wait_for_timeout(300)
        for i, shot in ((1, 'bank'), (5, 'all'), (4, 'advances')):
            page.locator('#page-money > .tabs .tab').nth(i).click(); page.wait_for_timeout(1500)
            target_shot(page, str(OUT / f'target-money-{shot}-{theme}.png'))
            run_filters(page, 'target', theme, 'money-tab-' + shot)
        advances = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/money', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Money',
                'X-Inertia-Partial-Data': 'advances', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.advances;
        }""")
        # Payment calendar (the chart is drawn differently — legacy needs Chart.js from the internet — and is hidden on both sides).
        page.locator('#page-money > .tabs .tab').nth(3).click(); page.wait_for_timeout(3000)
        target_shot(page, str(OUT / f'target-money-bankrecon-{theme}.png'))
        recon = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/money', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Money',
                'X-Inertia-Partial-Data': 'bankRecon', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.bankRecon;
        }""")
        target_recon = []
        for i in range(len(recon['accounts'])):  # none in an empty database
            page.select_option('#recon-account-select', index=i); page.wait_for_timeout(800)
            target_recon.append(page.locator('#recon-tbody').inner_text())
        if recon['accounts']:
            page.select_option('#recon-account-select', index=0); page.wait_for_timeout(300)
        page.locator('#page-money > .tabs .tab').nth(6).click(); page.wait_for_timeout(2500)
        page.evaluate("document.getElementById('pc-chart').style.visibility = 'hidden'")
        target_shot(page, str(OUT / f'target-money-calendar-{theme}.png'))
        run_filters(page, 'target', theme, 'money-tab-calendar')
        target_calendar = page.locator('#pc-tbody').inner_text()
        page.locator('#page-money > .tabs .tab').nth(2).click(); page.wait_for_timeout(3000)
        target_shot(page, str(OUT / f'target-money-salonwb-{theme}.png'))
        target_swb = {}
        for sal in ('armada', 'nazarbayeva'):
            page.locator(f'#swb-tab-{sal}').click(); page.wait_for_timeout(1500)
            page.select_option('#swb-year-filter', 'all'); page.wait_for_timeout(2500)
            target_swb[sal] = (page.evaluate("""() => [...document.querySelectorAll('#swb-tbody tr')].map(tr => [...tr.children].slice(0, 15).map(td => { const i = td.querySelector('input'); return i ? i.value : td.textContent.trim(); }).join('|'))"""), page.locator('#swb-current-balance').inner_text())
        page.locator('#swb-tab-armada').click(); page.wait_for_timeout(500)
        salon_rows = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/money', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Money',
                'X-Inertia-Partial-Data': 'salonRows', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.salonRows;
        }""")
        calendar = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/money', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Money',
                'X-Inertia-Partial-Data': 'calendar', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.calendar;
        }""")
        page.goto(TARGET + '/dashboard', wait_until='networkidle'); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-dashboard-{theme}.png'))
        run_filters(page, 'target', theme, 'page-dashboard')
        dash = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        page.goto(TARGET + '/finance', wait_until='networkidle'); page.wait_for_timeout(500)
        fin = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        page.locator('#finance-tabs .tab').nth(1).click(); page.wait_for_timeout(1200)
        target_shot(page, str(OUT / f'target-finance-ar-{theme}.png'))
        run_filters(page, 'target', theme, 'finance-ar')
        page.locator('#finance-tabs .tab').nth(5).click(); page.wait_for_timeout(2500)
        target_shot(page, str(OUT / f'target-finance-cfs-{theme}.png'))
        run_drill(page, 'target', theme, 'finance-cfs')
        page.locator('#finance-tabs .tab').nth(2).click(); page.wait_for_timeout(3000)
        target_shot(page, str(OUT / f'target-finance-purchases-{theme}.png'))
        run_filters(page, 'target', theme, 'finance-purchases')
        target_debts = page.locator('#pur-debts-tbody').inner_text()
        # Supplier tools: the server's orphans, spellings and the plan of «Импорт из ЭСФ» (apply=0 changes nothing).
        sup_tools = page.evaluate("""async () => {
            const cp = await (await fetch('/suppliers/counterparties', {headers: {Accept: 'application/json'}})).json();
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
            const r = await fetch('/suppliers/import-esf', {method: 'POST', headers: {'X-XSRF-TOKEN': xsrf, 'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '',
                'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', Accept: 'text/html, application/xhtml+xml'}, body: JSON.stringify({apply: 0})});
            const plan = (await r.json()).props.flash.dialog;
            return {orphans: cp.orphans.map(o => [o.name, o.count, Math.round(+o.sum), o.lastDate].join('|')),
                    spellings: cp.spellings.map(g => [g.canonical || '', ...g.variants.map(v => v.text + ':' + v.count)].join('|')), plan: plan ? plan.text : ''};
        }""")
        fin_pur = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/finance', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Finance',
                'X-Inertia-Partial-Data': 'purchases', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.purchases;
        }""")
        fin_tx = page.evaluate("""async () => {
            const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
            const r = await fetch('/finance', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'Finance',
                'X-Inertia-Partial-Data': 'transactions', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
            return (await r.json()).props.transactions;
        }""")
        # M20 «Начисления ЗП»: the journal in its default state, and a month with a name search.
        page.goto(TARGET + '/accruals', wait_until='networkidle'); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-accruals-{theme}.png'))
        run_filters(page, 'target', theme, 'page-accruals')
        accruals = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        target_acc = page.locator('#accruals-tbody').inner_text()
        # M19 «Зарплатная ведомость»: the ledger of the latest month with records and its totals, the journal of payouts.
        page.goto(TARGET + '/payroll', wait_until='networkidle'); page.wait_for_timeout(1000)
        target_shot(page, str(OUT / f'target-payroll-{theme}.png'))
        run_filters(page, 'target', theme, 'page-payroll')
        payroll = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        target_pr = [page.locator(x).inner_text() for x in ('#payroll-kpi', '#payroll-tbody', '#payroll-tfoot')]
        page.get_by_role('button', name='💵 Журнал выдач').click(); page.wait_for_timeout(600)
        target_shot(page, str(OUT / f'target-payroll-payouts-{theme}.png'))
        target_po = page.locator('#po-tbody').inner_text()
        page.locator('#payoutsModal .modal-close').click(); page.wait_for_timeout(300)
        # M17 «Сотрудники»: the staffing table, the cards, the table, the card of the first employee.
        page.goto(TARGET + '/hr', wait_until='networkidle'); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-hr-{theme}.png'))
        hr = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        target_org = page.locator('#hr-orgchart-body').inner_text()
        page.locator('#page-hr .tabs .tab').nth(1).click(); page.wait_for_timeout(600)
        target_shot(page, str(OUT / f'target-hr-employees-{theme}.png'))
        run_filters(page, 'target', theme, 'hr-tab-employees')
        page.locator('#hr-view-table').click(); page.wait_for_timeout(500)
        target_shot(page, str(OUT / f'target-hr-table-{theme}.png'))
        target_hr_table = page.locator('#hr-staff-tbody').inner_text()
        if hr['employees']:
            page.locator('#hr-staff-tbody tr').first.click(); page.wait_for_timeout(500)
            target_shot(page, str(OUT / f'target-hr-card-{theme}.png'))
        ctx.close()
        # ---------------- legacy
        ctx = browser.new_context(viewport=VIEWPORT, locale='ru-RU')
        ctx.route('**/*', guard)
        page = ctx.new_page()
        page.add_init_script(f"localStorage.setItem('erp_theme', '{theme}')")
        page.goto(LEGACY, wait_until='load', timeout=60000); page.wait_for_timeout(1500)
        page.evaluate("document.getElementById('diag-panel')?.remove(); document.getElementById('login-hints-block')?.remove()")
        page.evaluate("document.fonts.ready"); page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-login-{theme}.png'))
        page.evaluate("""(cats) => {
            document.getElementById('login-screen').style.display = 'none';
            const pages = ['dashboard','mrp','warehouse','purchases','esf','suppliers','catalog','sales','customers','crm','finance','money','channels','expense_cats','reconcile','hr','wages','payroll','accruals','techcards','mycabinet','ai'];
            document.querySelectorAll('.nav-item[onclick]').forEach(it => {
                const m = (it.getAttribute('onclick') || '').match(/showPage\\('(\\w+)'/);
                if (m) it.style.display = pages.indexOf(m[1]) >= 0 ? '' : 'none';
            });
            document.querySelectorAll('.nav-badge').forEach(b => b.remove());
            const av = document.getElementById('sidebar-avatar'); av.textContent = 'ДД'; av.style.background = '#4f8ef733'; av.style.color = '#4f8ef7';
            document.getElementById('sidebar-username').textContent = 'Демо Директор';
            document.getElementById('sidebar-role').textContent = 'Директор';
            _expenseCats = cats; _expenseCatsLoaded = true;
            showPage('expense_cats', document.querySelector(".nav-item[onclick*=\\"showPage('expense_cats'\\"]"));
            document.getElementById('topbar-title').textContent = 'Статьи расходов';
            renderExpCats();
        }""", cats)
        page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-m14-{theme}.png'))
        if first:
            page.evaluate(f"openExpCatModal('{first}', null, false)"); page.wait_for_timeout(300)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-m14-modal-{theme}.png'))
        legacy_ec = []
        for q, parent, fixed in [('', '', False), ('эл', '', False), ('', 'FIRSTTOP', False), ('', '', True), ('а', 'FIRSTTOP', True)]:
            legacy_ec.append(page.evaluate("""([q, parent, fixed]) => {
                document.getElementById('ec-search').value = q; document.getElementById('ec-filter-parent').value = parent; document.getElementById('ec-show-fixed').checked = fixed;
                renderExpCats(); return document.getElementById('ec-table-wrap').innerText; }""", [q, first_top if parent else '', fixed]))
        # An empty directory: legacy adds «Проверьте таблицу expense_categories в Supabase.» (ADR-0002, item 8).
        legacy_ec = [y.replace('Нет данных. Проверьте таблицу expense_categories в Supabase.', 'Нет данных.') for y in legacy_ec]
        bad = [i for i, (x, y) in enumerate(zip(target_ec, legacy_ec)) if x.split() != y.split()]
        print(f'expense categories filters ({theme}): {len(target_ec)} combinations, ' + ('совпадает' if not bad else f'РАСХОЖДЕНИЕ в {bad}'))
        page.evaluate("""(products) => {
            window._currentUser = 'director';
            catalogData = products.map(p => ({...p, active: true}));
            catalogLoaded = true;
            const sel = document.getElementById('catalog-cat-filter');
            sel.innerHTML = '<option value="">Все категории</option>' + [...new Set(catalogData.map(p => p.category))].sort().map(c => `<option value="${c}">${c}</option>`).join('');
            showPage('catalog', document.querySelector(".nav-item[onclick*=\\"showPage('catalog'\\"]"));
            renderCatalog();
        }""", products)
        page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-catalog-{theme}.png'))
        run_filters(page, 'legacy', theme, 'page-catalog')
        if product_id is not None:
            page.evaluate(f"openProductModal('{product_id}')"); page.wait_for_timeout(300)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-catalog-modal-{theme}.png'))
        # Legacy gets the same persons (all of the office channel). The type is passed as the target classifies it: the
        # legal-form fix is a documented deviation (docs/adr/0002-ui-deviations.md, item 13), not a layout difference.
        # Their office orders are given as orders of that customer: the count and the turnover the target shows.
        page.evaluate("""(persons) => {
            closeModal('productModal');
            personsData = persons.map((p, i) => ({id: i + 1, sbId: String(p.id), name: p.name, type: p.is_legal ? 'Юр. лицо' : 'Физ. лицо',
                idNum: p.inn || '', phone: p.phone || '', email: '', address: '', source: 'office', comment: ''}));
            ordersData = persons.flatMap(p => Array.from({length: p.orders}, (_, k) => ({num: p.name + k, channel: 'office', customer: p.name, status: 'done', payment: 100,
                createdDate: '', date: '', items: [{qty: 1, price: k === 0 ? p.turnover : 0, progress: 0}]})));
            _cacheLoaded.customers = true;
            showPage('customers', document.querySelector(".nav-item[onclick*=\\"showPage('customers'\\"]"));
            renderPersons();
        }""", persons)
        page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-customers-{theme}.png'))
        run_filters(page, 'legacy', theme, 'cust-persons')
        if persons:
            page.evaluate("openPersonModal(1)"); page.wait_for_timeout(300)  # a number: the row onclick passes a string and fails (ADR-0006)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-customers-modal-{theme}.png'))
        # Dealers: legacy keeps them in dealersData; it gets the target's list with the counts of «Текущий год» (legacy
        # recalculates them only when the period or the orders change, ADR-0002, item 17).
        page.evaluate("""([dealers, stats]) => {
            closeModal('personModal');
            dealersData = dealers.map((d, i) => ({id: i + 1, name: d.name, region: d.region || '—', channel: d.code, orders: 0, turnover: 0, status: 'Активен',
                _orders: stats[d.code].orders, _turnover: stats[d.code].turnover, _prepayPct: stats[d.code].prepay}));
            showCustTab('dealers', document.getElementById('cust-tab-dealers'));
        }""", [dealers, dealer_stats])
        page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-dealers-{theme}.png'))
        page.evaluate("showModal('addDealerModal')"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-dealer-modal-{theme}.png'))
        page.evaluate("""(orders) => {
            closeModal('addDealerModal');
            const dmy = d => d ? d.split('-').reverse().join('.') : '';
            ordersData = orders.map(o => ({num: o.num, channel: o.channel, customer: o.customer || '', date: dmy(o.date), createdDate: dmy(o.created), payment: o.payment, items: [{qty: 1, price: +o.total}]}));
            showCustTab('reconcile-counterparties', document.getElementById('cust-tab-reconcile'));
        }""", rec_orders)
        page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-cust-reconcile-{theme}.png'))
        page.evaluate("document.getElementById('rec-type').value = 'dealer'; renderCounterpartyReconcile(); document.getElementById('rec-party').value = 'dealer_astana'; renderCounterpartyReconcile()")
        page.wait_for_timeout(500)
        legacy_rec_act = (page.locator('#rec-kpi').inner_text(), page.locator('#rec-tfoot').inner_text(), page.evaluate("""() => [...document.querySelectorAll('#rec-tbody tr')].map(tr => [...tr.children].slice(0, 5).map(td => td.textContent.replace(/\\s+/g, ' ').trim()).join('|')).sort()"""))
        print(f'customers reconcile act ({theme}): KPI ' + ('совпадает' if target_rec_act[0] == legacy_rec_act[0] else 'РАСХОЖДЕНИЕ') + ', итог ' + ('совпадает' if target_rec_act[1] == legacy_rec_act[1] else 'РАСХОЖДЕНИЕ')
              + f', строк {len(target_rec_act[2])} / {len(legacy_rec_act[2])} ' + ('совпадает' if target_rec_act[2] == legacy_rec_act[2] else 'РАСХОЖДЕНИЕ'))
        # Suppliers: legacy suppliersData gets the target's list. Its card loads the supplier's ESF from the (blocked) legacy
        # backend; the target shows that ESF come with the «Входящие ЭСФ» module (ADR-0002, item 20).
        page.evaluate("""(suppliers) => {
            closeModal('addDealerModal');
            suppliersData = suppliers.map(s => ({id: String(s.id), name: s.name, category: s.category || '', contact: s.contact || '', phone: s.phone || '',
                email: s.email || '', inn: '', bin: s.bin || '', comment: s.comment || '', status: s.status, leadtime: s.lead_time_days || 0,
                turnover: s.turnover || 0, defect: s.defect_pct || 0, addr: s.address || '', expense_category: '', expense_subcategory: '',
                expense_source: s.expense_source}));
            _cacheLoaded.suppliers = true; _expenseCats = []; _expenseCatsLoaded = true;
            showPage('suppliers', document.querySelector(".nav-item[onclick*=\\"showPage('suppliers'\\"]"));
            renderSupplierList();
        }""", suppliers)
        page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-suppliers-{theme}.png'))
        run_filters(page, 'legacy', theme, 'sup-tab-list')
        if plain is not None:
            page.evaluate(f"openSupplierCard('{suppliers[plain]['id']}')"); page.wait_for_timeout(500)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-supplier-card-{theme}.png'))
            page.evaluate("closeModal('supplierCardModal')")
        page.evaluate("showSupTab('categories', document.querySelectorAll('#page-suppliers .tab')[1])"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-suppliers-categories-{theme}.png'))
        page.evaluate("showSupTab('rating', document.querySelectorAll('#page-suppliers .tab')[2])"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-suppliers-rating-{theme}.png'))
        page.evaluate("openMergeSuppliersModal()"); page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-supplier-merge-{theme}.png'))
        # Warehouse: legacy gets the target's materials and aliases. Its built-in demo documents are cleared: they are not
        # migrated data (ADR-0002, item 22), and without them «Цехи» and «Документы» show what the target shows.
        page.evaluate("""([materials, aliases]) => {
            closeModal('mergeSuppliersModal');
            warehouseData = materials.map(m => ({id: String(m.id), sbId: String(m.id), name: m.name, sku: m.sku || '', category: m.category, unit: m.unit,
                qty: m.qty, minQty: m.min_qty, price: m.price, supplier: '', warehouse_id: m.warehouse || ''}));
            _cacheLoaded.warehouse = true; whDocuments = [];
            materialAliases = aliases.map(a => ({id: String(a.id), materialId: null, name: a.alias_name, materialName: a.material_name, category: a.category || ''}));
            showPage('warehouse', document.querySelector(".nav-item[onclick*=\\"showPage('warehouse'\\"]"));
        }""", [materials, aliases])
        page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-warehouse-{theme}.png'))
        for i, (tab, shot) in enumerate((('stocks', 'categories'), ('workshops', 'workshops'), ('docs', 'docs'), ('remains', 'remains'), ('aliases', 'aliases'))):
            idx = {'stocks': 1, 'workshops': 2, 'docs': 3, 'remains': 4, 'aliases': 7}[tab]
            page.evaluate(f"showWhTab('{tab}', document.querySelectorAll('#page-warehouse .tab')[{idx}])"); page.wait_for_timeout(1500 if tab in ('remains', 'aliases') else 300)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-warehouse-{shot}-{theme}.png'))
            if shot in ('remains', 'aliases'):
                run_filters(page, 'legacy', theme, 'wh-tab-' + shot)
        page.evaluate("openAddMaterialModal()"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-warehouse-add-{theme}.png'))
        page.evaluate("selectMatCategory('lumber')"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-warehouse-add-step2-{theme}.png'))
        # ESF: legacy gets the target's invoices and payment links; for the detail, the same lines and the materials they match.
        page.evaluate("""([invoices, detail]) => {
            closeModal('addMaterialModal');
            const items = detail ? detail.items.map(it => ({...it, invoice_id: detail.id})) : [];
            _esfData = {invoices: invoices.map(i => detail && i.id === detail.id ? {...i, buyer_name: detail.buyer_name} : {...i}), items};
            esfLinksData = invoices.filter(i => i.linked > 0).map(i => ({esf_invoice_id: i.id, amount: i.linked})); esfLinksLoaded = true;
            if (detail) {
                // Legacy reads window.warehouseData, which a `let` global never is: its matching never works (ADR-0002, item 29).
                window.warehouseData = detail.items.filter(it => it.match).map((it, k) => ({id: 'm' + k, name: it.match.material}));
                window._materialAliases = detail.items.filter(it => it.match && it.match.via === 'alias').map(it => ({alias_name: it.description, material_name: it.match.material}));
            }
            showPage('esf', document.querySelector(".nav-item[onclick*=\\"showPage('esf'\\"]"));
        }""", [invoices, esf_detail])
        page.wait_for_timeout(800)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-esf-{theme}.png'))
        run_filters(page, 'legacy', theme, 'esf-tab-list')
        if invoices:
            page.evaluate(f"openEsfDetail({invoices[0]['id']})"); page.wait_for_timeout(500)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-esf-detail-{theme}.png'))
            page.evaluate("closeModal('esfDetailModal')")
        fixture = (ROOT / 'tests/Fixtures/esf/two-invoices.xml').read_text(encoding='utf-8')
        page.evaluate("(x) => { _esfXmlParsed = parseEsfXmlText(x, 'two-invoices.xml'); renderEsfXmlImportPreview(); document.getElementById('esfXmlImportModal').classList.add('open'); }", fixture)
        page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-esf-import-{theme}.png'))
        # CRM: legacy leadsData gets the target's open leads, mapped as its loader maps them (it loads won and lost ones only
        # for those filters). The funnel request is answered inside the browser with the target's funnel leads. CRM_STAGES,
        # which legacy never defines, gets the target's columns, and the segment legacy forces to «Новый» is set back to the
        # lead's own (ADR-0002, items 31, 32).
        # Orders with an end client (CRM «Клиенты»): each carries its total on the first product line.
        page.evaluate("""([leads, orders]) => {
            closeModal('esfXmlImportModal');
            ordersData = orders.map(o => ({num: o.num, channel: o.channel, customer: '', seller: o.seller || '', resp: o.seller || '', createdDate: o.created || '',
                date: o.date || '', payment: o.payment, status: o.status, salonClientName: o.end_client, comment: '', paymentHistory: [],
                items: (o.products.length ? o.products : ['']).map((name, k) => ({name, qty: 1, price: k === 0 ? o.total : 0, progress: 0, status: o.status}))}));
            _cacheLoaded.orders = true; _ordersLoadedForChannel = '';
            leadsData = leads.filter(l => l.status !== 'won' && l.status !== 'lost').map(l => ({id: String(l.id), name: l.name, phone: l.phone || '',
                status: l.status || 'new', interest: l.interest || '', budget: l.budget || 0, source: l.source || '', comment: l.comment || '',
                channel: 'office', manager: l.manager || '', created: l.created_at ? new Date(l.created_at).toLocaleDateString('ru-RU') : '', fromLeads: true}));
            _cacheLoaded.leads = true; _leadsLoadedForChannel = '';
            showPage('crm', document.querySelector(".nav-item[onclick*=\\"showPage('crm'\\"]"));
        }""", [crm['leads'], client_orders])
        page.wait_for_timeout(800)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-{theme}.png'))
        page.evaluate("showOrdersTab('leads', document.getElementById('crm-tab-leads'))"); page.wait_for_timeout(800)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-leads-{theme}.png'))
        page.evaluate("setLeadsView('table')"); page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-leads-table-{theme}.png'))
        if FILTERS is not None and theme == 'dark':
            # «Все статусы», «Выиграли» and «Проиграли» make legacy load the leads again with the closed ones: its request
            # is answered with the leads the target holds (the open ones when it asks for the active leads only), with the
            # ids as strings like the rest of this comparison.
            lead_rows = [{**{k: l.get(k) for k in ('name', 'phone', 'status', 'interest', 'budget', 'source', 'comment', 'manager', 'created_at')},
                          'id': str(l['id']), 'channel': 'office'} for l in crm['leads']]

            def serve_leads(route):
                if route.request.method != 'GET':
                    return route.abort()
                url = route.request.url
                rows = [r for r in lead_rows if r['status'] not in ('won', 'lost')] if 'status=neq.won' in url else lead_rows
                off = int((re.search(r'offset=(\d+)', url) or [0, 0])[1])
                size = int((re.search(r'limit=(\d+)', url) or [0, len(rows)])[1])
                route.fulfill(json=rows[off:off + size])

            leads_url = re.compile(r'/rest/v1/leads\?')
            page.route(leads_url, serve_leads)
            run_filters(page, 'legacy', theme, 'orders-leads')
            page.unroute(leads_url, serve_leads)
        page.evaluate("setLeadsView('cards')"); page.wait_for_timeout(500)
        if first_lead is not None:
            page.evaluate(f"openLeadModal('{first_lead}')"); page.wait_for_timeout(300)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-lead-modal-{theme}.png'))
            page.evaluate("closeModal('leadModal')")
        funnel = [l for l in crm['leads'] if l['status'] in ('qualify', 'proposal', 'negotiation', 'contact', 'meeting')]
        rows = [{k: l[k] for k in ('id', 'name', 'phone', 'status', 'interest', 'budget', 'source', 'manager')} for l in funnel]
        page.route(re.compile(r'/rest/v1/leads\?'), lambda route: route.fulfill(json=rows) if route.request.method == 'GET' else route.abort())
        page.evaluate("""(stages) => {
            window.CRM_STAGES = stages.map(s => ({key: s.key, label: s.label, cls: ''}));
            showOrdersTab('funnel', document.querySelectorAll('#page-crm .tab')[3]);
        }""", crm['funnelStages'])
        page.wait_for_timeout(1000)
        page.evaluate("(own) => { crmLeads.forEach(l => { l.segment = own[String(l.id)] ?? null; }); renderCrmKanban(); }", {str(l['id']): l['segment'] for l in funnel})
        page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-funnel-{theme}.png'))
        run_filters(page, 'legacy', theme, 'orders-funnel')
        page.evaluate("openCrmLeadModal(null)"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-funnel-modal-{theme}.png'))
        page.evaluate("closeModal('crmLeadModal')")
        # Lead card: the built-in demo history of legacy is not data (ADR-0002, item 22) and is cleared.
        if first_card is not None:
            unscroll(page)
            page.evaluate(f"""() => {{ Object.keys(commHistory).forEach(k => delete commHistory[k]);
                openCrmPopover({first_card}, document.querySelector(".crm-card[data-lead-id='{first_card}'] .crm-card-detail-btn")); }}""")
            page.wait_for_timeout(600)
            page.screenshot(path=str(OUT / f'legacy-crm-popover-{theme}.png'))
            page.evaluate("closeCrmPopover()")
        # Tasks: legacy tasksData gets the target's tasks. A task about a closed lead shows the lead in the target; legacy
        # finds it only when closed leads are loaded, so they are added to leadsData (ADR-0002, item 38).
        page.evaluate("""([tasks, leads]) => {
            const have = new Set(leadsData.map(l => l.id));
            leads.filter(l => tasks.some(t => t.lead_id === l.id) && !have.has(String(l.id))).forEach(l => leadsData.push({id: String(l.id), name: l.name,
                phone: l.phone || '', status: l.status, interest: l.interest || '', budget: l.budget || 0, source: l.source || '', comment: l.comment || '',
                channel: 'office', manager: l.manager || '', created: '', fromLeads: true}));
            tasksData = tasks.map(t => ({id: String(t.id), title: t.title, date: t.due_date || '', time: t.due_time || '', manager: t.manager || '',
                priority: t.priority, status: t.status, leadId: t.lead_id ? String(t.lead_id) : null}));
            showOrdersTab('tasks', [...document.querySelectorAll('#page-crm .tab')].find(t => t.textContent.includes('Задачи')));
        }""", [crm['tasks'], crm['leads']])
        page.wait_for_timeout(800)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-tasks-{theme}.png'))
        run_filters(page, 'legacy', theme, 'orders-tasks')
        page.evaluate("openTaskModal(null)"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-task-modal-{theme}.png'))
        page.evaluate("closeModal('taskModal'); showOrdersTab('clients', document.getElementById('crm-tab-clients'))"); page.wait_for_timeout(2500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-clients-{theme}.png'))
        run_filters(page, 'legacy', theme, 'orders-clients')
        if first_client:
            page.evaluate("(name) => openClientCard(name)", first_client); page.wait_for_timeout(300)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-client-card-{theme}.png'))
            page.evaluate("closeModal('client-card-modal')")
        # Order boards: legacy gets the same orders and the month in local dates. For «По производству» the orders in work
        # and the completed unpaid ones are given the statuses its columns look for (ADR-0002, item 51).
        for tab, idx in (('orders-kanban-prod', 4), ('orders-kanban-pay', 5)):
            page.evaluate("""([orders, tab, idx, prod]) => {
                ordersData = orders.map(o => ({num: o.num, channel: o.channel, customer: o.customer || '', salonClientName: o.end_client || '', createdDate: o.created || '',
                    date: o.date || '', payment: o.payment, status: prod && o.status === 'progress' ? 'production' : prod && o.status === 'done' ? 'delivered' : o.status,
                    items: [{qty: 1, price: o.total, progress: o.progress}]}));
                _cacheLoaded.money = true;
                showOrdersTab(tab, document.querySelectorAll('#page-crm .tabs .tab')[idx]);
                const now = new Date(); const iso = (y, m, d) => localISODate(y, m, d);
                _okDateFrom = iso(now.getFullYear(), now.getMonth(), 1); _okDateTo = iso(now.getFullYear(), now.getMonth() + 1, 0);
                renderOrdersKanban();
            }""", [board_orders, tab, idx, tab == 'orders-kanban-prod'])
            page.wait_for_timeout(1200)
            page.screenshot(path=str(OUT / f"legacy-crm-board-{'prod' if tab == 'orders-kanban-prod' else 'pay'}-{theme}.png"))
            run_filters(page, 'legacy', theme, 'orders-' + tab)
        # «Проекты»: legacy loads them from its database (blocked here) — it gets the same empty list.
        page.evaluate("""() => {
            loadProjects = async () => { prjData = []; prjCalcs = []; prjFiles = []; prjComments = []; prjErr = '';
                document.getElementById('tab-count-projects').textContent = 0; };
            showOrdersTab('projects', document.getElementById('crm-tab-projects'));
        }""")
        page.wait_for_timeout(800)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-projects-{theme}.png'))
        page.evaluate("prjOpenNew()"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-crm-project-new-{theme}.png'))
        page.evaluate("prjClose()")
        # Purchases: legacy gets the target's requests; the date is the business date the target shows (ADR-0002, item 40).
        # warehouseData (for «План-факт») is the target's directory given at the warehouse step.
        page.evaluate("""(requests) => {
            closeModal('taskModal');
            materialRequestsData = requests.map(r => ({id: r.id, created_at: r.date ? r.date.split('.').reverse().join('-') : '', material_name: r.material_name,
                qty: r.qty, unit: r.unit || '', order_num: r.order_num || '', item_name: r.item_name || '', workshop: r.workshop || '', requested_by: r.requested_by || '',
                status: r.status}));
            goodsReceiptsData = []; techCardsData = []; _purchExtraLoaded = true;
            showPage('purchases', document.querySelector(".nav-item[onclick*=\\"showPage('purchases'\\"]"));
        }""", purchases)
        page.wait_for_timeout(800)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-purchases-{theme}.png'))
        run_filters(page, 'legacy', theme, 'purch-extra-requests')
        page.evaluate("setMreqView('kanban')"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-purchases-kanban-{theme}.png'))
        page.evaluate("setMreqView('table'); showPurchExtraTab('planfact', document.querySelectorAll('#page-purchases > .tabs .tab')[2])"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-purchases-planfact-{theme}.png'))
        run_filters(page, 'legacy', theme, 'purch-extra-planfact')
        page.evaluate("showPurchExtraTab('requests', document.querySelectorAll('#page-purchases > .tabs .tab')[0]); openNewMaterialRequestModal()"); page.wait_for_timeout(300)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-purchases-modal-{theme}.png'))
        # Sales: legacy ordersData gets the target's orders; one line per order carries the target's total and mean progress,
        # and realizationsData is what makes «Завершить» appear where the target shows it. The status breakdown of the summary,
        # empty in legacy, is the documented fix (ADR-0002, item 43).
        page.evaluate("""(orders) => {
            closeModal('newMaterialRequestModal');
            ordersData = orders.map(o => ({num: o.num, channel: o.channel, customer: o.customer || '', seller: o.seller || '', resp: o.seller || '',
                createdDate: o.created || '', date: o.date || '', deliveryDate: '', payment: o.payment, status: o.status, comment: '', paymentDue: '',
                salonClientName: o.end_client || '', paymentHistory: [],
                items: [{name: 'x', itemType: '', category: '', qty: 1, price: o.total, progress: o.progress, status: o.status, resp: '', date: '', attributes: {}}]}));
            realizationsData = orders.filter(o => o.can_complete).map(o => ({order_num: o.num, revenue: o.total}));
            _cacheLoaded.orders = true; _ordersLoadedForChannel = '';
            showPage('sales', document.querySelector(".nav-item[onclick*=\\"showPage('sales'\\"]"));
        }""", sales)
        page.wait_for_timeout(1500)
        page.evaluate("(text) => { const el = document.querySelector('#ord-summary-stats span:nth-child(3)'); if (el) el.textContent = text; }", breakdown)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-sales-{theme}.png'))
        run_filters(page, 'legacy', theme, 'orders-all-orders')
        for i, (tab, shot) in enumerate((('queue', 'queue'), ('done', 'done'))):
            idx = {'queue': 1, 'done': 3}[tab]
            page.evaluate(f"showSalesTab('{tab}', document.querySelectorAll('#sales-tabs .tab')[{idx}])"); page.wait_for_timeout(1200)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-sales-{shot}-{theme}.png'))
        # Realization journal: legacy gets the same lines (as loadRealizationsFromDB keeps them), already loaded.
        page.evaluate("""(rows) => {
            realizationsData = rows.map(r => ({id: String(r.id), doc_date: r.date, doc_num: r.doc_num, order_num: r.order_num, customer: r.customer,
                name: r.name, qty: r.qty, revenue: r.revenue}));
            _cacheLoaded_realiz = true;
            showSalesTab('sales-journal', document.querySelectorAll('#sales-tabs .tab')[4]);
        }""", realizations)
        page.wait_for_timeout(1500)
        unscroll(page); page.evaluate("document.getElementById('sj-totals').scrollIntoView()"); page.wait_for_timeout(300)
        page.screenshot(path=str(OUT / f'legacy-sales-journal-{theme}.png'))
        run_filters(page, 'legacy', theme, 'orders-sales-journal')
        # Report: the orders now carry their real lines, characteristics, comment and delivery date.
        page.evaluate("""([orders, report]) => {
            const lines = {};
            report.lines.forEach(l => (lines[l.order_id] = lines[l.order_id] || []).push(l));
            ordersData = orders.map(o => ({num: o.num, channel: o.channel, customer: o.customer || '', seller: o.seller || '', resp: o.seller || '',
                createdDate: o.created || '', date: o.date || '', deliveryDate: (report.notes[o.id] || {}).delivery || '', payment: o.payment, status: o.status,
                comment: (report.notes[o.id] || {}).comment || '', paymentDue: '', salonClientName: o.end_client || '', paymentHistory: [],
                items: (lines[o.id] || []).map(l => ({name: l.name, itemType: '', category: '', qty: l.qty, price: 0, progress: 0, status: o.status, resp: '', date: '',
                    attributes: l.attrs}))}));
            showSalesTab('report', document.querySelectorAll('#sales-tabs .tab')[7]);
        }""", [sales, report])
        page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-sales-report-{theme}.png'))
        run_filters(page, 'legacy', theme, 'orders-report')
        # Production journal: legacy gets the order lines with the effective status the server counted (its own rule, applied
        # to the same data) and the stages recorded on the worksheet; nothing is left for it to load.
        page.evaluate("""(positions) => {
            const byNum = {};
            positions.forEach(p => (byNum[p.num] = byNum[p.num] || []).push(p));
            ordersData = Object.entries(byNum).map(([num, list]) => ({num, channel: 'office', customer: '', status: 'queue', payment: 0, createdDate: '', date: '',
                items: list.map(p => ({name: p.name, category: p.category || '', qty: p.qty, progress: p.progress, status: p.status, resp: p.resp || '', date: p.date || '', attributes: {}}))}));
            prodProgress = {};
            Object.entries(byNum).forEach(([num, list]) => list.forEach((p, i) => {
                if (p.stages !== null) prodProgress[num + '|' + i] = {id: 0, version: 1, stages: p.stages.map(s => ({stage: s === '(без названия)' ? '' : s})), materials: [], completed: p.completed};
            }));
            realizationsData = [{order_num: '—', name: '—', revenue: 0}]; _cacheLoaded_realiz = true;
            employeesData = [{name: '—'}]; _prodWbLoaded = true;
            showPage('mrp', document.querySelector(".nav-item[onclick*=\\"showPage('mrp'\\"]"));
        }""", mrp)
        page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-mrp-{theme}.png'))
        run_filters(page, 'legacy', theme, 'mrp-orders')
        page.evaluate("showMrpOrdersFilter('queue', document.querySelectorAll('#mrp-orders > .tabs .tab')[1])"); page.wait_for_timeout(800)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-mrp-queue-{theme}.png'))
        # Money: legacy gets the target's accounts (without account numbers, which are not stored — ADR-0002, item 54), the
        # operations in the same order, the expense items, the ESF links and order payments of each operation, and one open
        # advance carrying the advance debt.
        page.evaluate("""(m) => {
            bankAccounts = m.accounts.map(a => ({id: String(a.id), name: a.name, bank: a.bank, iin: '', bik: '', balance: 0, openingBalance: a.opening, currency: a.currency, type: a.type, icon: a.icon || ''}));
            moneyTx = m.transactions.map(t => ({id: t.id, sbId: String(t.id), date: t.date, accountId: String(t.account), flow: t.flow, category: t.category,
                subcategory: t.item || '', endClient: '', amount: t.amount, desc: t.desc, counterparty: t.ctr, bin: '', orderNum: t.order, amountFx: t.fx, fxCurrency: ''}));
            _expenseCats = [...new Set(m.transactions.map(t => t.item).filter(Boolean))].map(n => ({code: n, name: n})); _expenseCatsLoaded = true;
            esfLinksData = m.transactions.filter(t => t.esf > 0).map(t => ({source_type: 'money_tx', source_id: String(t.id), amount: t.esf})); esfLinksLoaded = true;
            window._orderPaymentsData = m.transactions.flatMap(t => t.orders.filter(n => n !== t.order).map(n => ({tx_id: String(t.id), order_num: n, amount: 0})));
            advanceReports = m.advanceDebt > 0 ? [{id: 1, issued: m.advanceDebt, spent: 0, status: 'open', lines: []}] : [];
            _cacheLoaded.money = true; if (!ordersData.length) ordersData = [{num: '—', items: []}];
            showPage('money', document.querySelector(".nav-item[onclick*=\\"showPage('money'\\"]"));
        }""", money)
        page.wait_for_timeout(2000)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-money-{theme}.png'))
        run_filters(page, 'legacy', theme, 'money-tab-cash')
        legacy_cash = []
        for v in page.locator('#cash-account-select option').evaluate_all('os => os.map(o => o.value)'):
            page.select_option('#cash-account-select', v); page.wait_for_timeout(400)
            legacy_cash.append(page.locator('#cash-tbody').inner_text())
        if page.locator('#cash-account-select option').count():  # an empty database has no cash desk
            page.select_option('#cash-account-select', index=0); page.wait_for_timeout(300)
        bad = [i for i, (x, y) in enumerate(zip(target_cash, legacy_cash)) if x != y]
        print(f'cash tables ({theme}): {len(target_cash)} / {len(legacy_cash)} касс, ' + ('совпадает' if len(target_cash) == len(legacy_cash) and not bad else f'РАСХОЖДЕНИЕ в {bad}'))
        def legacy_set(step):
            if step == 'day': page.evaluate("setMoneyPeriod('day')")
            elif step == 'month': page.evaluate("setMoneyPeriod('month')")
            elif step == 'month-2':
                page.evaluate("(() => { const s = document.getElementById('mp-month'); const v = s.options[Math.min(1, s.options.length - 1)].value; setMoneyPeriod('month', v); })()")
            else: page.evaluate("setMoneyPeriod('all')")
        legacy_period = period_states(legacy_set)
        for name, x, y in zip(('день', 'месяц', 'другой месяц'), target_period, legacy_period):
            print(f'money period {name} ({theme}): ' + ', '.join(f'{n} ' + ('совпадает' if a == b else 'РАСХОЖДЕНИЕ') for n, a, b in zip(('подпись', 'касса', 'список месяцев'), x, y)))
        page.evaluate("zrOpen()"); page.wait_for_timeout(800)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-money-zprecon-{theme}.png'))
        print(f'zp transfer recon ({theme}): ' + ('совпадает' if target_zr.split() == page.locator('#zr-body').inner_text().split() else 'РАСХОЖДЕНИЕ'))
        page.evaluate("closeModal('zpReconModal')"); page.wait_for_timeout(300)
        for idx, tab in ((1, 'bank'), (5, 'all')):
            page.evaluate(f"showMoneyTab('{tab}', document.querySelectorAll('#page-money > .tabs .tab')[{idx}])"); page.wait_for_timeout(1500)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-money-{tab}-{theme}.png'))
            run_filters(page, 'legacy', theme, 'money-tab-' + tab)
        # Advances: the same reports, with the issuing operation the target links (added without an account, so the totals
        # stay) and «Потрачено» as its lines give it.
        page.evaluate("""(rows) => {
            moneyTx = moneyTx.concat(rows.filter(a => a.issue_doc).map(a => ({id: 'adv-tx-' + a.id, sbId: 'adv-tx-' + a.id, date: a.issue_doc.date, accountId: '', flow: 'expense', category: '', amount: a.issue_doc.amount, desc: '', counterparty: ''})));
            advanceReports = rows.map(a => ({id: String(a.id), sbId: String(a.id), date: a.date, empName: a.employee, purpose: a.purpose || '', issued: a.issued, spent: a.spent,
                returned: a.returned, status: a.status, accountId: '', txIds: [], txId: a.issue_doc ? 'adv-tx-' + a.id : '', docNum: a.doc_num || '', reportDate: '',
                lines: Array.from({length: a.esf}, () => ({amount: 0, esfInvoiceId: 1}))}));
            _advancesLoaded = true;
            showMoneyTab('advances', document.querySelectorAll('#page-money > .tabs .tab')[4]);
        }""", advances)
        page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-money-advances-{theme}.png'))
        run_filters(page, 'legacy', theme, 'money-tab-advances')
        # Bank reconciliation: the same statement rows (by legacy account id) and the journal of the day as one income and one expense.
        page.evaluate("""([r, accounts]) => {
            const keys = ['a1b2c3d4-e5f6-7890-abcd-ef1234567890', '0265fb65-7812-4bee-b794-71306246edb7'];
            const key = id => keys[r.accounts.indexOf(id)];
            // The select names the accounts by legacy id; the KPIs of the page stay as they were (the journal below is per day).
            r.accounts.forEach((id, i) => { const a = accounts.find(x => x.id === id); if (a && !bankAccounts.some(b => b.id === keys[i])) bankAccounts.push({id: keys[i], name: a.name, icon: a.icon, type: 'bank_recon_only'}); });
            window._kpiSaved = ['money-kpi-cash', 'money-kpi-bank', 'money-kpi-total', 'money-kpi-advances'].map(id => [id, document.getElementById(id)?.textContent]);
            keys.forEach(k => { bankReconData[k] = []; bankReconLoaded[k] = true; });
            r.rows.forEach(x => bankReconData[key(x.account)].push({id: 'r' + x.id, date: x.date, flow: x.flow, amount: +x.amount, counterparty: x.counterparty || '', bin: x.bin || '',
                description: x.description || '', transferred_to_db: x.transferred}));
            const extra = [];
            r.journal.forEach((j, i) => { if (+j.in) extra.push({id: 'ji' + i, date: j.date, accountId: key(j.account), flow: 'income', amount: +j.in, desc: '', category: ''});
                if (+j.out) extra.push({id: 'jo' + i, date: j.date, accountId: key(j.account), flow: 'expense', amount: +j.out, desc: '', category: ''}); });
            window._savedMoneyTx = moneyTx; moneyTx = extra;
            bankReconActiveAccount = keys[0];
            showMoneyTab('bankrecon', document.querySelectorAll('#page-money > .tabs .tab')[3]);
            window._kpiSaved.forEach(([id, t]) => { const e = document.getElementById(id); if (e && t != null) e.textContent = t; });
        }""", [recon, money['accounts']])
        page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-money-bankrecon-{theme}.png'))
        legacy_recon = []
        for k in ('a1b2c3d4-e5f6-7890-abcd-ef1234567890', '0265fb65-7812-4bee-b794-71306246edb7')[:len(recon['accounts'])]:
            page.evaluate(f"showBankReconAccount('{k}')"); page.wait_for_timeout(800)
            legacy_recon.append(page.locator('#recon-tbody').inner_text())
        for n, a2, b2 in zip(('Kaspi', 'Halyk'), target_recon, legacy_recon):
            print(f'bank recon {n} ({theme}): lines {len(a2.splitlines())} / {len(b2.splitlines())}, ' + ('совпадает' if a2 == b2 else 'РАСХОЖДЕНИЕ'))
        page.evaluate("moneyTx = window._savedMoneyTx; for (let i = bankAccounts.length - 1; i >= 0; i--) if (bankAccounts[i].type === 'bank_recon_only') bankAccounts.splice(i, 1)")
        # Dashboard: legacy gets data that sum to the same monthly totals (one operation per month and category, the advance
        # lines as operations named like them), the period of the target (legacy shifts it by a day through UTC — ADR-0002)
        # and the expense labels instead of the codes it would print.
        page.evaluate("""([d, codes]) => {
            const cur = d.today.slice(0, 7), last = new Date(+cur.slice(0, 4), +cur.slice(5), 0).getDate();
            const tx = [], add = (date, flow, category, amount, categoryName) => tx.push({date, flow, category, amount: +amount, categoryName, accountId: 'x'});
            Object.entries(d.cashIn).forEach(([m, v]) => add(m + '-15', 'income', 'order_payment', v));
            const ZP = 'b7e2a1c3-4d5f-6e7a-8b9c-0d1e2f3a4b5c';
            const months = new Set([...Object.keys(d.cashOut), ...Object.keys(d.supplierPaid), ...Object.keys(d.fotPaid), ...Object.keys(d.expenses)]);
            months.forEach(m => {
                let rest = +(d.cashOut[m] || 0);
                if (m === cur) Object.entries(d.expenses[m] || {}).forEach(([label, v]) => {
                    const code = codes[label] || 'adv_line';
                    add(m + '-15', 'expense', code, v, label);
                    if (!['transfer_in', 'transfer_out', 'transfer'].includes(code)) rest -= +v;
                });
                else {
                    add(m + '-15', 'expense', 'supplier', d.supplierPaid[m] || 0);
                    rest -= +(d.supplierPaid[m] || 0);
                }
                // «Расход денег» of the FOT chart: paid out of «Касса ЗП» (c54cf25) — as an issue of an advance, which no
                // other figure of the page splits out.
                if (+(d.fotPaid[m] || 0)) { tx.push({date: m + '-15', flow: 'expense', category: 'advance_out', amount: +d.fotPaid[m], accountId: ZP}); rest -= +d.fotPaid[m]; }
                add(m + '-15', 'expense', 'advance_out', rest);
            });
            moneyTx = tx; advanceReports = [];
            realizationsData = Object.entries(d.revenue).map(([m, v]) => ({doc_date: m + '-15', revenue: +v}));
            ordersData = [...Array(d.ordersInWork).fill('production'), ...Array(d.ordersQueue).fill('queue')].map(status => ({status, items: []}));
            warehouseData = [...d.critical.map(m => ({name: m.name, qty: +m.qty, unit: m.unit, minQty: +m.qty * 2 + 1})),
                ...Array(d.stockCount - d.critical.length).fill(0).map((_, i) => ({name: 'x' + i, qty: 1, minQty: 0}))];
            const paid = Object.values(d.supplierPaid).reduce((s, v) => s + +v, 0);
            _esfData = {invoices: [{total_with_nds: paid + +d.supplierDebt}]};
            _payrollData = {accruals: Object.entries(d.fotAccrued).map(([m, v]) => ({type: 'salary', amount: +v, date: m + '-15'})), payments: []};
            _dashPeriodFrom = cur + '-01'; _dashPeriodTo = cur + '-' + last;
            showPage('dashboard', document.querySelector(".nav-item[onclick*=\\"showPage('dashboard'\\"]"));
        }""", [dash, EXPENSE_CODES])
        page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-dashboard-{theme}.png'))
        run_filters(page, 'legacy', theme, 'page-dashboard')
        # Finance: the same orders and operations (in tenge), the opening balances of the accounts as legacy balance (its CFS ignores
        # openingBalance — ADR-0002), the current year.
        page.evaluate("""([f, tx]) => {
            const dmy = d => d ? d.split('-').reverse().join('.') : '';
            ordersData = f.orders.map(o => ({num: o.num, channel: o.channel, customer: o.customer || '', seller: o.seller || '', resp: o.seller || '', date: dmy(o.date),
                createdDate: dmy(o.created), payment: o.payment, status: o.status, paymentDue: o.payment_due || '', items: [{qty: 1, price: +o.total}]}));
            moneyTx = tx.map(t => ({id: t.id, sbId: String(t.id), date: t.date, accountId: String(t.account), flow: t.flow, category: t.category, amount: +t.amount, desc: t.desc || ''}));
            bankAccounts.length = 0; f.accounts.forEach(a => bankAccounts.push({id: String(a.id), name: a.name, icon: a.icon, balance: +a.opening}));
            _expenseCats = Object.entries(f.categoryNames).map(([code, name]) => ({code, name})); _expenseCatsLoaded = true;
            _cacheLoaded.money = true;
            showPage('finance', document.querySelector(".nav-item[onclick*=\\"showPage('finance'\\"]"));
            setFinancePeriod('year');
            showFinanceTab('ar', document.querySelectorAll('#finance-tabs .tab')[1]);
        }""", [fin, fin_tx])
        page.wait_for_timeout(1200)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-finance-ar-{theme}.png'))
        run_filters(page, 'legacy', theme, 'finance-ar')
        page.evaluate("showFinanceTab('cfs', document.querySelectorAll('#finance-tabs .tab')[5])"); page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-finance-cfs-{theme}.png'))
        run_drill(page, 'legacy', theme, 'finance-cfs')
        # Purchases: the same invoices, payments with a counterparty (in tenge), suppliers and advance lines; the calendar month
        # the target shows (legacy shifts «Текущий месяц» through UTC — ADR-0002). The whole debts table is compared as text.
        page.evaluate("""([d, today]) => {
            _esfData = {invoices: d.invoices.map(i => ({num: i.num, date: i.date, status: i.status, supplier_name: i.supplier, supplier_bin: i.bin, total_with_nds: +i.total}))};
            moneyTx = d.payments.map((t, i) => ({id: i, date: t.date, flow: 'expense', counterparty: t.counterparty, bin: t.bin, amount: +t.amount, desc: t.desc || ''}));
            suppliersData = d.suppliers.map(s => ({name: s.name, bin: s.bin || ''}));
            advanceReports = [{date: '', empName: '', lines: []}]; advanceReports = d.advanceLines.map(l => ({date: l.date, empName: l.employee || '', lines: [{type: 'supplier', supplier: l.supplier, amount: +l.amount, desc: l.desc || ''}]}));
            _advancesLoaded = true;
            // Legacy's own periods in local dates (it takes their ends through UTC, a day early — ADR-0002, item 73);
            // «Произвольный» as legacy reads it.
            const own = getPurDateRange, y = +today.slice(0, 4), m = +today.slice(5, 7) - 1, pad = n => String(n).padStart(2, '0');
            const last = (yy, mm) => new Date(Date.UTC(yy, mm + 1, 0)).getUTCDate();
            getPurDateRange = () => {
                const period = document.getElementById('pur-period')?.value || 'month';
                if (period === 'month') return {from: `${y}-${pad(m + 1)}-01`, to: `${y}-${pad(m + 1)}-${pad(last(y, m))}`};
                if (period === 'quarter') { const q = Math.floor(m / 3); return {from: `${y}-${pad(q * 3 + 1)}-01`, to: `${y}-${pad(q * 3 + 3)}-${pad(last(y, q * 3 + 2))}`}; }
                if (period === 'year') return {from: `${y}-01-01`, to: `${y}-12-31`};
                return own();
            };
            showFinanceTab('purchases', document.querySelectorAll('#finance-tabs .tab')[2]);
        }""", [fin_pur, dash['today']])
        page.wait_for_timeout(2500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-finance-purchases-{theme}.png'))
        run_filters(page, 'legacy', theme, 'finance-purchases')
        legacy_debts = page.locator('#pur-debts-tbody').inner_text()
        tl, ll = target_debts.splitlines(), legacy_debts.splitlines()
        print(f'purchases debts table ({theme}): rows {len(tl)} / {len(ll)}, ' + ('совпадает' if tl == ll else f'РАСХОЖДЕНИЙ {sum(1 for a, b in zip(tl, ll) if a != b) + abs(len(tl) - len(ll))}'))
        # «Начисления ЗП»: legacy gets the accruals the target renders (as its own loader keeps them) and fills its month list itself.
        page.evaluate("""(r) => {
            _payrollData = {accruals: r.accruals.map(a => ({...a, type: 'salary'})), payments: []};
            fillPayrollMonthSelects();
            showPage('accruals', document.querySelector(".nav-item[onclick*=\\"showPage('accruals'\\"]"));
            renderAccrualsList();
        }""", accruals)
        page.wait_for_timeout(1200)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-accruals-{theme}.png'))
        run_filters(page, 'legacy', theme, 'page-accruals')
        tl, ll = target_acc.splitlines(), page.locator('#accruals-tbody').inner_text().splitlines()
        print(f'accruals table ({theme}): rows {len(tl)} / {len(ll)}, ' + ('совпадает' if tl == ll else f'РАСХОЖДЕНИЙ {sum(1 for a, b in zip(tl, ll) if a != b) + abs(len(tl) - len(ll))}'))
        # «Зарплатная ведомость»: the same accruals and payments as its loader keeps them; legacy draws the ledger only after a
        # filter is changed — it is asked to draw it (ADR-0002).
        page.evaluate("""(r) => {
            _payrollData = {accruals: r.accruals.map(a => ({...a, type: 'salary'})), payments: r.payments.map(p => ({...p, type: 'payment'}))};
            fillPayrollMonthSelects();
            showPage('payroll', document.querySelector(".nav-item[onclick*=\\"showPage('payroll'\\"]"));
            renderSalaryLedger();
        }""", payroll)
        page.wait_for_timeout(1200)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-payroll-{theme}.png'))
        run_filters(page, 'legacy', theme, 'page-payroll')
        legacy_pr = [page.locator(x).inner_text() for x in ('#payroll-kpi', '#payroll-tbody', '#payroll-tfoot')]
        page.evaluate("showPayouts()"); page.wait_for_timeout(600)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-payroll-payouts-{theme}.png'))
        legacy_po = page.locator('#po-tbody').inner_text()
        page.evaluate("closeModal('payoutsModal')")
        print(f'payroll ({theme}): ' + ', '.join(f'{n} ' + ('совпадает' if a.split() == b.split() else 'РАСХОЖДЕНИЕ') for n, a, b in zip(('KPI', 'строки', 'итоги', 'выдачи'), target_pr + [target_po], legacy_pr + [legacy_po])))
        # «Сотрудники»: legacy gets the staff the target renders (as loadStaffFromDB keeps it); its demonstration lists of piece
        # work and of the payroll (wageEmployees, payrollStaff) are emptied — the target does not show them (ADR-0036). Legacy
        # draws the staffing table only when its tab is clicked: it is clicked (ADR-0002).
        page.evaluate("""(r) => {
            employeesData = r.employees.map(e => ({...e, initials: e.name.split(' ').map(x => x[0]).join('').slice(0, 2).toUpperCase(), color: 'rgba(79,142,247,.15)', tc: 'var(--accent)'}));
            wageEmployees = []; payrollStaff = [];
            showPage('hr', document.querySelector(".nav-item[onclick*=\\"showPage('hr'\\"]"));
        }""", hr)
        page.wait_for_timeout(1200)
        page.evaluate("showHRTab('orgchart', document.querySelectorAll('#page-hr .tab')[0])"); page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-hr-{theme}.png'))
        legacy_org = page.locator('#hr-orgchart-body').inner_text()
        page.evaluate("showHRTab('employees', document.querySelectorAll('#page-hr .tab')[1])"); page.wait_for_timeout(600)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-hr-employees-{theme}.png'))
        run_filters(page, 'legacy', theme, 'hr-tab-employees')
        page.evaluate("setHRView('table')"); page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-hr-table-{theme}.png'))
        legacy_hr_table = page.locator('#hr-staff-tbody').inner_text()
        if hr['employees']:
            page.evaluate("openEmployeeCard(employeesData[0].id)"); page.wait_for_timeout(500)
            unscroll(page); page.screenshot(path=str(OUT / f'legacy-hr-card-{theme}.png'))
            page.evaluate("closeModal('employeeCardModal')")
        print(f'hr ({theme}): штатное расписание ' + ('совпадает' if target_org.split() == legacy_org.split() else 'РАСХОЖДЕНИЕ')
              + ', таблица ' + ('совпадает' if target_hr_table.splitlines() == legacy_hr_table.splitlines() else 'РАСХОЖДЕНИЕ'))
        ctx.close()
        # Payment calendar of legacy: its own page, clock at noon of the target's business date in UTC (legacy takes «today»
        # through UTC — in Almaty a day early, ADR-0002); the same balances, unpaid orders (status «В работе» as the
        # «production» it looks for) and dated operations.
        ctx = browser.new_context(viewport=VIEWPORT, locale='ru-RU', timezone_id='UTC')
        ctx.route('**/*', guard)
        page = ctx.new_page()
        page.clock.set_fixed_time(calendar['today'] + 'T12:00:00Z')
        page.add_init_script(f"localStorage.setItem('erp_theme', '{theme}')")
        page.goto(LEGACY, wait_until='load', timeout=60000); page.wait_for_timeout(1500)
        page.evaluate("""([c, accounts, cats]) => {
            document.getElementById('diag-panel')?.remove();
            document.getElementById('login-screen').style.display = 'none';
            const pages = ['dashboard','mrp','warehouse','purchases','esf','suppliers','catalog','sales','customers','crm','finance','money','channels','expense_cats','reconcile','hr','wages','payroll','accruals','techcards','mycabinet','ai'];
            document.querySelectorAll('.nav-item[onclick]').forEach(it => { const m = (it.getAttribute('onclick') || '').match(/showPage\\('(\\w+)'/); if (m) it.style.display = pages.indexOf(m[1]) >= 0 ? '' : 'none'; });
            document.querySelectorAll('.nav-badge').forEach(b => b.remove());
            const av = document.getElementById('sidebar-avatar'); av.textContent = 'ДД'; av.style.background = '#4f8ef733'; av.style.color = '#4f8ef7';
            document.getElementById('sidebar-username').textContent = 'Демо Директор'; document.getElementById('sidebar-role').textContent = 'Директор';
            const dmy = d => d ? d.split('-').reverse().join('.') : '';
            bankAccounts.length = 0; accounts.forEach(a => bankAccounts.push({id: String(a.id), name: a.name, icon: a.icon, balance: 0, currency: a.currency, type: a.type}));
            moneyTx = accounts.map(a => { const b = +c.balances[a.id]; return {id: 'b' + a.id, date: '2000-01-01', accountId: String(a.id), flow: b >= 0 ? 'income' : 'expense', category: '', amount: Math.abs(b)}; })
                .concat(c.future.map((t, i) => ({id: 'f' + i, date: t.date, accountId: String(t.account), flow: t.flow, category: t.category, counterparty: t.ctr, desc: t.desc, amount: +t.amount})));
            ordersData = c.orders.map(o => ({num: o.num, channel: o.channel, customer: o.customer || '', status: o.status === 'progress' ? 'production' : o.status, payment: o.payment,
                deliveryDate: dmy(o.delivery), date: dmy(o.date), items: [{qty: 1, price: +o.total}]}));
            fcPlanData = []; _cacheLoaded.money = true;
            showPage('money', document.querySelector(".nav-item[onclick*=\\"showPage('money'\\"]"));
            showMoneyTab('calendar', document.querySelectorAll('#page-money > .tabs .tab')[6]);
            document.getElementById('pc-chart').style.visibility = 'hidden';
        }""", [calendar, money['accounts'], money['categories']])
        page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-money-calendar-{theme}.png'))
        run_filters(page, 'legacy', theme, 'money-tab-calendar')
        tl, ll = target_calendar.splitlines(), page.locator('#pc-tbody').inner_text().splitlines()
        (OUT / f'calendar-target-{theme}.txt').write_text(target_calendar); (OUT / f'calendar-legacy-{theme}.txt').write_text('\n'.join(ll))
        print(f'payment calendar table ({theme}): lines {len(tl)} / {len(ll)}, ' + ('совпадает' if tl == ll else f'РАСХОЖДЕНИЙ {sum(1 for a, b in zip(tl, ll) if a != b) + abs(len(tl) - len(ll))}'))
        # Salon worksheet: the same rows (legacy keeps the uuid as id and orders rows of one date by it); every cell and the
        # running balance of both salons over all years are compared as text.
        page.evaluate("""(rows) => {
            const n = v => v == null ? null : Number(v);
            ['armada', 'nazarbayeva'].forEach(s => {
                salonWbData[s] = rows.filter(r => r.salon === s).map(r => ({id: r.uuid, salon: s, order_date: r.order_date, order_num: r.order_num, manager: r.manager,
                    balance_before: n(r.balance_before), payment_date: r.payment_date, cash_amount: n(r.cash_amount), card_amount: n(r.card_amount), balance_after: n(r.balance_after),
                    note: r.note, delivery_date: r.delivery_date, cod_amount: n(r.cod_amount), expense_amount: n(r.expense_amount), transferred_to_office: n(r.transferred_to_office),
                    recipient_desc: r.recipient_desc, transferred_to_db: r.transferred, transferred_at: r.transferred_at}));
                salonWbLoaded[s] = true;
            });
            salonWbYearFilter = 'current';
            showMoneyTab('salonwb', document.querySelectorAll('#page-money > .tabs .tab')[2]);
        }""", salon_rows)
        page.wait_for_timeout(2500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-money-salonwb-{theme}.png'))
        for sal in ('armada', 'nazarbayeva'):
            page.evaluate(f"showSalonWbSalon('{sal}')"); page.wait_for_timeout(800)
            page.evaluate("setSalonWbYearFilter('all')"); page.wait_for_timeout(2500)
            legacy_cells = page.evaluate("""() => [...document.querySelectorAll('#swb-tbody tr')].map(tr => [...tr.children].slice(0, 15).map(td => { const i = td.querySelector('input'); return i ? i.value : td.textContent.trim(); }).join('|'))""")
            tc, tb = target_swb[sal]
            bad, quoted = abs(len(tc) - len(legacy_cells)), 0
            for i, (x, y) in enumerate(zip(tc, legacy_cells)):
                if x != y:
                    pairs = [(k, p_, q_) for k, (p_, q_) in enumerate(zip(x.split('|'), y.split('|'))) if p_ != q_]
                    # legacy puts the value into the markup unescaped and cuts it at a double quote (ADR-0002, item 75)
                    if all('"' in p_ and q_ == p_.split('"')[0] for _, p_, q_ in pairs):
                        quoted += 1
                        continue
                    bad += 1
                    print(f'  row {i}: columns {[k for k, _, _ in pairs]} differ (values not printed)')
            print(f'salon worksheet {sal} ({theme}): rows {len(tc)} / {len(legacy_cells)}, cells ' + ('совпадает' if not bad else f'РАСХОЖДЕНИЙ {bad}')
                  + (f' (записанное отличие — текст до кавычки, ADR-0002 п. 75: {quoted})' if quoted else '')
                  + ', остаток ' + ('совпадает' if tb == page.locator('#swb-current-balance').inner_text() else 'РАСХОЖДЕНИЕ'))
        ctx.close()
        # «Сверка с офисом»: a dealer on both sides; legacy gets the channel's orders, a payment per paid order and the transfers.
        ctx = browser.new_context(viewport=VIEWPORT, locale='ru-RU')
        page = ctx.new_page()
        page.add_init_script(f"localStorage.setItem('erp_theme', '{theme}')")
        page.goto(TARGET + '/login', wait_until='networkidle'); page.fill('#login-username', 'dealer_astana'); page.fill('#login-password', DEV_PASSWORD); page.click('#login-btn')
        page.wait_for_url(lambda u: '/login' not in u); page.goto(TARGET + '/reconcile', wait_until='networkidle'); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-reconcile-{theme}.png'))
        run_filters(page, 'target', theme, 'page-reconcile')
        rec = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        sidebar = page.evaluate("({a: document.getElementById('sidebar-avatar').textContent.trim(), n: document.getElementById('sidebar-username').textContent.trim(), r: document.getElementById('sidebar-role').textContent.trim()})")
        target_rec = page.locator('#page-reconcile').inner_text()
        ctx.close()
        ctx = browser.new_context(viewport=VIEWPORT, locale='ru-RU')
        ctx.route('**/*', guard)
        page = ctx.new_page()
        page.add_init_script(f"localStorage.setItem('erp_theme', '{theme}')")
        page.goto(LEGACY, wait_until='load', timeout=60000); page.wait_for_timeout(1500)
        inject = """([p, sb]) => {
            document.getElementById('diag-panel')?.remove();
            document.getElementById('login-screen').style.display = 'none';
            window._currentUser = 'dealer_astana'; _USERS.dealer_astana.name = sb.n;
            const pages = _USERS.dealer_astana.pages;
            document.querySelectorAll('.nav-item[onclick]').forEach(it => { const m = (it.getAttribute('onclick') || '').match(/showPage\\('(\\w+)'/); if (m) it.style.display = pages.indexOf(m[1]) >= 0 ? '' : 'none'; });
            document.querySelectorAll('.nav-badge').forEach(b => b.remove());
            const av = document.getElementById('sidebar-avatar'); av.textContent = sb.a; av.style.background = '#06b6d433'; av.style.color = '#06b6d4';
            document.getElementById('sidebar-username').textContent = sb.n; document.getElementById('sidebar-role').textContent = sb.r;
            const dmy = d => d ? d.split('-').reverse().join('.') : '';
            ordersData = p.orders.map(o => ({num: o.num, channel: 'dealer_astana', customer: o.customer || '', status: o.status, date: dmy(o.date), items: [{qty: 1, price: +o.total}]}));
            moneyTx = p.orders.filter(o => +o.paid).map((o, i) => ({id: 'p' + i, date: '2026-01-01', flow: 'income', category: 'order_payment', amount: +o.paid, desc: '', counterparty: '', orderNum: o.num, accountId: 'x'}))
                .concat(p.transfers.map((t, i) => ({id: 't' + i, date: t.date, flow: 'income', category: 'advance_return', amount: +t.amount, desc: t.desc, counterparty: sb.n, orderNum: '', accountId: 'x'})));
            _cacheLoaded.money = true;
        }"""
        page.evaluate(inject, [rec, sidebar])
        page.evaluate("showPage('reconcile', [...document.querySelectorAll('.nav-item')].find(n => (n.getAttribute('onclick') || '').includes(\"'reconcile'\")))"); page.wait_for_timeout(1500)
        page.evaluate(inject, [rec, sidebar]); page.evaluate("renderReconcile()"); page.wait_for_timeout(800)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-reconcile-{theme}.png'))
        run_filters(page, 'legacy', theme, 'page-reconcile')
        tl, ll = target_rec.splitlines(), page.locator('#page-reconcile').inner_text().splitlines()
        print(f'reconcile page ({theme}): lines {len(tl)} / {len(ll)}, ' + ('совпадает' if tl == ll else f'РАСХОЖДЕНИЙ {sum(1 for a, b in zip(tl, ll) if a != b) + abs(len(tl) - len(ll))}'))
        ctx.close()
        # «Касса салона»: the head of the salons (both cashes), on both sides; legacy gets the same operations per cash.
        ctx = browser.new_context(viewport=VIEWPORT, locale='ru-RU')
        page = ctx.new_page()
        page.add_init_script(f"localStorage.setItem('erp_theme', '{theme}')")
        page.goto(TARGET + '/login', wait_until='networkidle'); page.fill('#login-username', 'salon_armada_mgr'); page.fill('#login-password', DEV_PASSWORD); page.click('#login-btn')
        page.wait_for_url(lambda u: '/login' not in u); page.goto(TARGET + '/money_salon', wait_until='networkidle'); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-money-salon-{theme}.png'))
        run_filters(page, 'target', theme, 'page-money_salon')
        salon = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        sidebar = page.evaluate("({a: document.getElementById('sidebar-avatar').textContent.trim(), n: document.getElementById('sidebar-username').textContent.trim(), r: document.getElementById('sidebar-role').textContent.trim()})")
        target_salon = page.locator('#salon-cash-tbody').inner_text()
        page.goto(TARGET + '/salon_report', wait_until='networkidle'); page.wait_for_timeout(800)
        target_shot(page, str(OUT / f'target-salon-report-{theme}.png'))
        run_filters(page, 'target', theme, 'sr-tab-current')
        sreport = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props']
        target_sr = [page.locator(x).inner_text() for x in ('#sr-kpi', '#sr-sellers-tbody', '#sr-orders-tbody')]
        # The tabs of the salon payroll: the lines load with the first of them.
        page.locator('#page-salon_report .tabs .tab').nth(1).click(); page.wait_for_timeout(1500)
        target_shot(page, str(OUT / f'target-salon-paysheet-{theme}.png'))
        run_filters(page, 'target', theme, 'sr-tab-paysheet')
        target_ps = page.locator('#sr-tab-paysheet').inner_text()
        page.locator('#page-salon_report .tabs .tab').nth(2).click(); page.wait_for_timeout(1000)
        target_shot(page, str(OUT / f'target-salon-history-{theme}.png'))
        run_filters(page, 'target', theme, 'sr-tab-history')
        target_hist = page.locator('#sr-tab-history').inner_text()
        payroll = page.evaluate("JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page)")['props'].get('payroll')
        if payroll is None:  # the page was not re-rendered with the lazy prop: ask for it as the tab does
            payroll = page.evaluate("""async () => {
                const initial = JSON.parse(document.querySelector('script[data-page]')?.textContent || document.getElementById('app').dataset.page);
                const r = await fetch('/salon_report', {headers: {'X-Inertia': 'true', 'X-Inertia-Version': initial.version || '', 'X-Inertia-Partial-Component': 'SalonReport',
                    'X-Inertia-Partial-Data': 'payroll', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml'}});
                return (await r.json()).props.payroll;
            }""")
        ctx.close()
        ctx = browser.new_context(viewport=VIEWPORT, locale='ru-RU')
        ctx.route('**/*', guard)
        page = ctx.new_page()
        page.add_init_script(f"localStorage.setItem('erp_theme', '{theme}')")
        page.goto(LEGACY, wait_until='load', timeout=60000); page.wait_for_timeout(1500)
        page.evaluate("""([p, sb]) => {
            document.getElementById('diag-panel')?.remove();
            document.getElementById('login-screen').style.display = 'none';
            window._currentUser = 'salon_armada_mgr';
            const pages = _USERS.salon_armada_mgr.pages;
            document.querySelectorAll('.nav-item[onclick]').forEach(it => { const m = (it.getAttribute('onclick') || '').match(/showPage\\('(\\w+)'/); if (m) it.style.display = pages.indexOf(m[1]) >= 0 ? '' : 'none'; });
            document.querySelectorAll('.nav-badge').forEach(b => b.remove());
            const av = document.getElementById('sidebar-avatar'); av.textContent = sb.a; av.style.background = '#8b5cf633'; av.style.color = '#8b5cf6';
            document.getElementById('sidebar-username').textContent = sb.n; document.getElementById('sidebar-role').textContent = sb.r;
            // The operations of each cash in the order the target lists them (Армада first), keyed by the legacy account ids.
            const ids = ['c854b1e4-c0f7-436b-a274-5e8a59c4ef78', '701d7d6d-3dda-4403-ac3c-e4a3987836ea'];
            ids.forEach(id => _smTxCache[id] = []);
            p.transactions.forEach(t => _smTxCache[ids[t.cash]].push({id: 'tx' + t.id, date: t.date, amount: +t.amount, flow: t.flow, category: t.category, desc: t.desc,
                accountId: ids[t.cash], orderNum: t.orders[0] || ''}));
            showPage('money_salon', document.querySelector(".nav-item[onclick*=\\"showPage('money_salon'\\"]"));
        }""", [salon, sidebar])
        page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-money-salon-{theme}.png'))
        run_filters(page, 'legacy', theme, 'page-money_salon')
        tl, ll = target_salon.splitlines(), page.locator('#salon-cash-tbody').inner_text().splitlines()
        print(f'salon cash table ({theme}): lines {len(tl)} / {len(ll)}, ' + ('совпадает' if tl == ll else f'РАСХОЖДЕНИЙ {sum(1 for a, b in zip(tl, ll) if a != b) + abs(len(tl) - len(ll))}'))
        # «Отчёт салонов»: the same salon orders; legacy cannot load the staff (blocked), the target says the salary comes with them.
        page.evaluate("""(r) => {
            const dmy = d => d ? d.split('-').reverse().join('.') : '';
            ordersData = r.orders.map(o => ({num: o.num, channel: o.channel, customer: o.customer || '', salonClientName: o.end_client || '', seller: o.seller || '',
                createdDate: dmy(o.created), date: dmy(o.date), payment: o.payment, status: o.status === 'progress' ? 'production' : o.status, items: [{qty: 1, price: +o.total}]}));
            employeesData = [];
            showPage('salon_report', [...document.querySelectorAll('.nav-item')].find(n => (n.getAttribute('onclick') || '').includes("'salon_report'")));
            ordersData.length || 0;
        }""", sreport)
        page.wait_for_timeout(1500)
        page.evaluate("renderSalonReport()"); page.wait_for_timeout(500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-salon-report-{theme}.png'))
        run_filters(page, 'legacy', theme, 'sr-tab-current')
        legacy_sr = [page.locator(x).inner_text() for x in ('#sr-kpi', '#sr-sellers-tbody', '#sr-orders-tbody')]
        print(f'salon report ({theme}): ' + ', '.join(f'{n} ' + ('совпадает' if a == b else 'РАСХОЖДЕНИЕ') for n, a, b in zip(('KPI', 'продавцы', 'заказы'), target_sr, legacy_sr)))
        # Legacy gets the payroll lines the target received, in the same order (its loader asks year.desc,month.desc).
        page.evaluate("""(rows) => { _salonPayrollData = rows; showSalonReportTab('paysheet', document.querySelectorAll('#page-salon_report .tabs .tab')[1]); }""", payroll)
        page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-salon-paysheet-{theme}.png'))
        run_filters(page, 'legacy', theme, 'sr-tab-paysheet')
        legacy_ps = page.locator('#sr-tab-paysheet').inner_text()
        page.evaluate("showSalonReportTab('history', document.querySelectorAll('#page-salon_report .tabs .tab')[2])"); page.wait_for_timeout(1500)
        unscroll(page); page.screenshot(path=str(OUT / f'legacy-salon-history-{theme}.png'))
        run_filters(page, 'legacy', theme, 'sr-tab-history')
        legacy_hist = page.locator('#sr-tab-history').inner_text()
        print(f'salon payroll tabs ({theme}): ' + ', '.join(f'{n} ' + ('совпадает' if a.split() == b.split() else 'РАСХОЖДЕНИЕ') for n, a, b in zip(('ведомость', 'история'), (target_ps, target_hist), (legacy_ps, legacy_hist))))
        ctx.close()
        # Supplier tools in legacy: the same suppliers, supplier payments and invoices through its own functions.
        ctx = browser.new_context(viewport=VIEWPORT, locale='ru-RU')
        ctx.route('**/*', guard)
        page = ctx.new_page()
        page.goto(LEGACY, wait_until='load', timeout=60000); page.wait_for_timeout(1500)
        legacy_tools = page.evaluate("""async ([sup, tx, inv]) => {
            suppliersData = sup.map(s => ({id: s.id, name: s.name, bin: s.bin || ''}));
            moneyTx = tx.map(t => ({id: t.id, date: t.date, flow: t.flow, category: t.category, counterparty: t.ctr || '', amount: t.amount, accountId: String(t.account)}));
            _esfData = {invoices: inv.map(i => ({supplier_name: i.supplier, supplier_bin: i.bin || '', total_with_nds: +i.total, date: i.date}))};
            let plan = '';
            window.confirm = m => { plan = m; return false; }; window.alert = m => { plan = m; };
            await importSuppliersFromEsf(null);
            return {orphans: findOrphanSupplierCounterparties().map(o => [o.name, o.count, Math.round(o.sum), o.lastDate].join('|')),
                    spellings: findCounterpartySpellingGroups().map(g => [g.canonicalSupplierName || '', ...g.variants.map(v => v.text + ':' + v.count)].join('|')), plan};
        }""", [suppliers, money['transactions'], fin_pur['invoices']])
        for key in ('orphans', 'spellings', 'plan'):
            a2, b2 = sup_tools[key], legacy_tools[key]
            n = len(a2) if isinstance(a2, list) else 1
            print(f'supplier tools {key} ({theme}): {n} — ' + ('совпадает' if a2 == b2 else 'РАСХОЖДЕНИЕ'))
        ctx.close()
        for shot in ('login', 'm14') + (('m14-modal',) if first else ()) + ('catalog',) + (('catalog-modal',) if product_id is not None else ()) + ('customers',) + (('customers-modal',) if persons else ()) + ('dealers', 'dealer-modal', 'cust-reconcile', 'suppliers') + (('supplier-card',) if plain is not None else ()) + ('suppliers-categories', 'suppliers-rating', 'supplier-merge', 'warehouse', 'warehouse-categories', 'warehouse-workshops', 'warehouse-docs', 'warehouse-remains', 'warehouse-aliases', 'warehouse-add', 'esf') + (('esf-detail',) if invoices else ()) + ('esf-import', 'crm', 'crm-leads', 'crm-leads-table') + (('crm-lead-modal',) if first_lead is not None else ()) + ('crm-funnel', 'crm-funnel-modal') + (('crm-popover',) if first_card is not None else ()) + ('crm-tasks', 'crm-task-modal', 'crm-clients') + (('crm-client-card',) if first_client else ()) + ('crm-board-prod', 'crm-board-pay', 'crm-projects', 'crm-project-new') + ('purchases', 'purchases-kanban', 'purchases-planfact', 'purchases-modal', 'sales', 'sales-queue', 'sales-done', 'sales-journal', 'sales-report', 'mrp', 'mrp-queue', 'money', 'money-zprecon', 'money-bank', 'money-all', 'money-advances', 'money-bankrecon', 'money-calendar', 'money-salonwb', 'money-salon', 'salon-report', 'salon-paysheet', 'salon-history', 'reconcile', 'dashboard', 'finance-ar', 'finance-cfs', 'finance-purchases', 'accruals', 'payroll', 'payroll-payouts', 'hr', 'hr-employees', 'hr-table') + (('hr-card',) if hr['employees'] else ()):
            if EMPTY and shot in EMPTY_DEVIATIONS:  # pictures kept, not compared: a documented deviation
                print(f'{shot}-{theme}: not compared — {EMPTY_DEVIATIONS[shot]}')
                continue
            results[f'{shot}-{theme}'] = diff(OUT / f'legacy-{shot}-{theme}.png', OUT / f'target-{shot}-{theme}.png', f'{shot}-{theme}')
    browser.close()
filter_failures = 0
if FILTERS is not None:
    for line in FILTERS.report():
        print(line)
    filter_failures = FILTERS.failed
print(json.dumps({'output': str(OUT), 'legacy_blob': BLOB.name, 'viewport': f"{VIEWPORT['width']}x{VIEWPORT['height']}", 'diff_percent': results}, indent=1))
sys.exit(1 if any(v > 0.5 for v in results.values()) or filter_failures else 0)
