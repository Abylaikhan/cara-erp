#!/usr/bin/env python3
"""Every page of the menu of the director, the head of the salons and a dealer, with each of its tabs opened, has no id twice (make e2e: throwaway database, synthetic seed).

Legacy has one element per id (one #drilldownModal); scripts, the visual comparison and the browser checks find elements by
id, so a second one — two tabs each putting its own window on the page — breaks them. Read only: nothing is saved.
"""
from __future__ import annotations

import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))
from e2e_lib import BASE, check, login, summary  # noqa: E402

DUPLICATES = """() => { const c = {}; document.querySelectorAll('[id]').forEach(e => { c[e.id] = (c[e.id] || 0) + 1; });
    return Object.keys(c).filter(k => c[k] > 1); }"""


def pages(page, role: str, links: list) -> None:
    for href in links:
        page.goto(BASE + href, wait_until='networkidle')
        tabs = page.locator('.tabs .tab')
        for i in range(tabs.count()):
            try:
                tabs.nth(i).click(timeout=2000)
                page.wait_for_timeout(200)
            except Exception:  # a tab hidden for this role
                pass
        page.wait_for_load_state('networkidle')
        dup = page.evaluate(DUPLICATES)
        check(f'{role} {href}: каждый id один раз', not dup, repr(dup[:5]))



def main() -> int:
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome', headless=True)
        for role in ('director', 'salon_armada_mgr', 'dealer_astana'):
            ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ru-RU')
            page = ctx.new_page()
            page.on('dialog', lambda d: d.dismiss())
            login(page, role)
            links = page.eval_on_selector_all('a.nav-item', 'els => [...new Set(els.map(e => e.getAttribute("href")))]')
            check(f'{role}: в меню есть разделы', len(links) > 0, repr(len(links)))
            pages(page, role, links)
            ctx.close()
        browser.close()
    return summary()

if __name__ == '__main__':
    sys.exit(main())
