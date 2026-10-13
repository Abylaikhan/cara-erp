"""Visual comparison legacy ↔ target (ТЗ UI-03): login screen and M14 «Статьи расходов», dark and light themes, 1440×900.

Legacy runs from the accepted snapshot in .local-audit/blobs with every network request blocked except Google Fonts —
it never reaches the legacy backend. Both sides get the same synthetic directory (DatabaseSeeder).
Requires host python3 with `pip install playwright==1.55.0 pillow` and Google Chrome; the app must be up (make up)
and seeded. Interim tool until the Dockerised TGT-VISUAL suite exists.

    python3 tests/visual/legacy_compare.py            # → .local-audit/visual/<time>/, prints % of differing pixels
"""
import datetime, json, re, sys
from pathlib import Path
from PIL import Image, ImageChops
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
CFG = json.loads((ROOT / 'tools/harness/config.json').read_text())
STATE = json.loads((ROOT / 'docs/audit/state.json').read_text())
BLOB = ROOT / '.local-audit/blobs' / f"{STATE['last_reported']['source_blob']}.html"
OUT = ROOT / '.local-audit/visual' / datetime.datetime.now().strftime('%Y%m%dT%H%M%S'); OUT.mkdir(parents=True, exist_ok=True)
LEGACY = BLOB.as_uri()
ENV = (ROOT / '.env').read_text()
TARGET = 'http://localhost:' + (re.search(r'^APP_PORT=(\d+)', ENV, re.M) or [None, '8080'])[1]
DEV_PASSWORD = re.search(r'^DEV_ADMIN_PASSWORD=(.*)$', ENV, re.M).group(1).strip()
CATS = [  # identical synthetic data in both versions (same as DatabaseSeeder)
    dict(code='general', name='Общие расходы', parent_code=None, is_fixed=False, sort_order=10),
    dict(code='gen_electricity', name='Электроэнергия', parent_code='general', is_fixed=True, sort_order=10),
    dict(code='gen_rent', name='Аренда помещений', parent_code='general', is_fixed=True, sort_order=20),
    dict(code='gen_internet', name='Связь и интернет', parent_code='general', is_fixed=True, sort_order=30),
    dict(code='production', name='Производство', parent_code=None, is_fixed=False, sort_order=20),
    dict(code='prod_materials', name='Материалы', parent_code='production', is_fixed=False, sort_order=10),
    dict(code='prod_tools', name='Инструмент и оснастка', parent_code='production', is_fixed=False, sort_order=20),
    dict(code='sales_marketing', name='Продажи и маркетинг', parent_code=None, is_fixed=False, sort_order=30),
    dict(code='mkt_ads', name='Реклама', parent_code='sales_marketing', is_fixed=False, sort_order=10),
    dict(code='mkt_delivery', name='Доставка клиентам', parent_code='sales_marketing', is_fixed=False, sort_order=20),
]

def guard(route):
    url = route.request.url
    if url.startswith('file://') or 'fonts.googleapis.com' in url or 'fonts.gstatic.com' in url:
        return route.continue_()
    return route.abort()

def diff(a, b, name):
    ia, ib = Image.open(a).convert('RGB'), Image.open(b).convert('RGB')
    w, h = min(ia.width, ib.width), min(ia.height, ib.height)
    ia, ib = ia.crop((0, 0, w, h)), ib.crop((0, 0, w, h))
    d = ImageChops.difference(ia, ib).convert('L').point(lambda v: 255 if v > 24 else 0)
    changed = sum(1 for v in d.getdata() if v)
    canvas = Image.new('RGB', (w * 3, h)); canvas.paste(ia, (0, 0)); canvas.paste(ib, (w, 0)); canvas.paste(Image.merge('RGB', (d, d.point(lambda v: 0), d.point(lambda v: 0))), (2 * w, 0))
    canvas.save(OUT / f'{name}-side-by-side.png')
    return round(100 * changed / (w * h), 2)

results = {}
with sync_playwright() as p:
    browser = p.chromium.launch(channel='chrome', headless=True)
    for theme in ('dark', 'light'):
        # ---------------- legacy
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ru-RU')
        ctx.route('**/*', guard)
        page = ctx.new_page()
        page.add_init_script(f"localStorage.setItem('erp_theme', '{theme}')")
        page.goto(LEGACY, wait_until='load', timeout=60000); page.wait_for_timeout(1500)
        page.evaluate("document.getElementById('diag-panel')?.remove(); document.getElementById('login-hints-block')?.remove()")
        page.evaluate("document.fonts.ready"); page.wait_for_timeout(500)
        page.screenshot(path=str(OUT / f'legacy-login-{theme}.png'))
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
        }""", CATS)
        page.wait_for_timeout(500)
        page.screenshot(path=str(OUT / f'legacy-m14-{theme}.png'))
        page.evaluate("openExpCatModal('gen_rent', null, false)"); page.wait_for_timeout(300)
        page.screenshot(path=str(OUT / f'legacy-m14-modal-{theme}.png'))
        ctx.close()
        # ---------------- target
        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ru-RU')
        page = ctx.new_page()
        page.add_init_script(f"localStorage.setItem('erp_theme', '{theme}')")
        page.goto(TARGET + '/login', wait_until='networkidle'); page.evaluate("document.fonts.ready"); page.wait_for_timeout(500)
        page.screenshot(path=str(OUT / f'target-login-{theme}.png'))
        page.fill('#login-username', 'director'); page.fill('#login-password', DEV_PASSWORD); page.click('#login-btn')
        page.wait_for_url('**/dashboard'); page.goto(TARGET + '/expense_cats', wait_until='networkidle'); page.wait_for_timeout(500)
        page.screenshot(path=str(OUT / f'target-m14-{theme}.png'))
        page.locator('#ec-table-wrap tr', has_text='gen_rent').locator('button').click(); page.wait_for_timeout(300)
        page.screenshot(path=str(OUT / f'target-m14-modal-{theme}.png'))
        ctx.close()
        for shot in ('login', 'm14', 'm14-modal'):
            results[f'{shot}-{theme}'] = diff(OUT / f'legacy-{shot}-{theme}.png', OUT / f'target-{shot}-{theme}.png', f'{shot}-{theme}')
    browser.close()
print(json.dumps({'output': str(OUT), 'legacy_blob': BLOB.name, 'diff_percent': results}, indent=1))
sys.exit(1 if any(v > 0.5 for v in results.values()) else 0)
