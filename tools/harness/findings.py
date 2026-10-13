"""Findings registry with stable IDs and history. A finding closes only when its check passes on a new ref."""
from __future__ import annotations

import hashlib
from typing import Any, Dict, List, Optional

from checks import Check


def finding_id(check_id: str, locator: str) -> str:
    return 'AF-' + hashlib.sha1(f'{check_id}|{locator}'.encode()).hexdigest()[:8]


CATEGORY = {'target': 'CODE', 'source': 'CODE', 'harness': 'RISK'}
MANUAL = {'SRC-HISTORY', 'SRC-ACCEPTED', 'HAR-COVERAGE', 'HAR-JOURNAL', 'HAR-SECRETS'}


def candidates(checks: List[Check], junit: Optional[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Findings implied by failed required checks of this run."""
    out = []
    for c in checks:
        if c.status != 'FAIL' or not c.required:
            continue
        if c.id == 'TGT-TESTS' and junit and any(x['status'] in ('failed', 'error') for x in junit['cases']):
            for case in junit['cases']:
                if case['status'] in ('failed', 'error'):
                    out.append(_make(c, case['id'], f'Падает тест {case["id"]}', 'high'))
        elif c.id == 'SRC-SYNTAX':
            for s in c.details.get('failed_scripts', []) or [{}]:
                out.append(_make(c, f"script#{s.get('index')}", f"Синтаксическая ошибка в inline-скрипте #{s.get('index')} (строка {s.get('line')})", 'high'))
        elif c.id == 'HAR-COVERAGE':
            for item in c.details.get('removed', []) or [c.reason]:
                out.append(_make(c, str(item), f'Сокращение покрытия: {item}', 'medium'))
        else:
            out.append(_make(c, c.id, c.title + (f': {c.reason}' if c.reason else ''), 'high' if c.stage == 'target' else 'medium'))
    return out


def _make(c: Check, locator: str, title: str, severity: str) -> Dict[str, Any]:
    return {'id': finding_id(c.id, locator), 'check_id': c.id, 'locator': locator, 'title': title[:300],
            'category': CATEGORY.get(c.stage, 'RISK'), 'severity': severity,
            'close_policy': 'manual' if c.id in MANUAL else 'recheck'}


def update(registry: Dict[str, Any], found: List[Dict[str, Any]], checks: List[Check], junit: Optional[Dict[str, Any]],
           run: Dict[str, str]) -> Dict[str, List[str]]:
    """Apply this run to the registry in place; return IDs grouped as new / repeated / closed / needs_review."""
    items = {f['id']: f for f in registry.setdefault('findings', [])}
    by_check = {c.id: c for c in checks}
    passed_cases = {x['id'] for x in junit['cases'] if x['status'] == 'passed'} if junit else set()
    all_cases = {x['id'] for x in junit['cases']} if junit else set()
    result = {'new': [], 'repeated': [], 'closed': [], 'needs_review': []}
    seen_now = set()
    for cand in found:
        seen_now.add(cand['id'])
        f = items.get(cand['id'])
        if f is None or f['status'] == 'CLOSED':
            reopened = f is not None
            f = f or dict(cand, first_seen=run, history=[])
            f.update({'status': 'OPEN', 'last_seen': run, 'closed': None})
            f['history'].append({'run_id': run['run_id'], 'event': 'REOPENED' if reopened else 'OPENED',
                                 'target_ref': run['target_ref'], 'source_ref': run['source_ref']})
            items[cand['id']] = f
            result['new'].append(f['id'])
        else:
            f['last_seen'] = run
            f['history'].append({'run_id': run['run_id'], 'event': 'REPRODUCED', 'target_ref': run['target_ref'],
                                 'source_ref': run['source_ref']})
            result['repeated'].append(f['id'])
    for f in items.values():
        if f['id'] in seen_now or f['status'] == 'CLOSED':
            continue
        c = by_check.get(f['check_id'])
        if c is None or c.status != 'PASS':
            continue  # no evidence this run: stays as is
        if f.get('close_policy') == 'manual':
            result['needs_review'].append(f['id'])
            continue
        ref_key = 'target_ref' if c.stage == 'target' else 'source_ref'
        if f['check_id'] == 'TGT-TESTS' and junit:
            if f['locator'] not in all_cases:
                f['status'] = 'NEEDS_REVIEW'
                f['history'].append({'run_id': run['run_id'], 'event': 'TEST_REMOVED', 'target_ref': run['target_ref']})
                result['needs_review'].append(f['id'])
                continue
            if f['locator'] not in passed_cases:
                continue
        if f['last_seen'].get(ref_key) == run[ref_key]:
            f['status'] = 'NEEDS_REVIEW'
            f['history'].append({'run_id': run['run_id'], 'event': 'PASSED_ON_SAME_REF', ref_key: run[ref_key]})
            result['needs_review'].append(f['id'])
            continue
        f['status'] = 'CLOSED'
        f['closed'] = {'run_id': run['run_id'], 'target_ref': run['target_ref'], 'source_ref': run['source_ref'],
                       'evidence': f"{f['check_id']} PASS"}
        f['history'].append({'run_id': run['run_id'], 'event': 'CLOSED', 'target_ref': run['target_ref'],
                             'source_ref': run['source_ref'], 'evidence': f"{f['check_id']} PASS"})
        result['closed'].append(f['id'])
    registry['findings'] = sorted(items.values(), key=lambda f: (f['status'] == 'CLOSED', f['id']))
    return result
