"""Structural comparison of two legacy inventories and its impact on target modules/requirements."""
from __future__ import annotations

import csv
from collections import defaultdict, deque
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Set

COUNT_KEYS = ('pages', 'modals', 'controls', 'html_tables', 'scripts', 'scripts_failed', 'function_declarations',
              'functions_all', 'db_tables', 'db_calls', 'db_calls_dynamic', 'storage_calls')


def _by(items: Iterable[Dict[str, Any]], key: str) -> Dict[str, Dict[str, Any]]:
    return {i[key]: i for i in items if i.get(key)}


def _public_control(c: Dict[str, Any]) -> Dict[str, Any]:
    # Visible texts are not published: option lists may contain names of real people.
    return {k: c[k] for k in ('group', 'tag', 'id', 'name', 'type', 'calls', 'line') if c.get(k) not in (None, '', [])}


def diff_inventories(old: Dict[str, Any], new: Dict[str, Any]) -> Dict[str, Any]:
    fo, fn = _by(old['functions'], 'key'), _by(new['functions'], 'key')
    functions = {
        'added': sorted(set(fn) - set(fo)),
        'removed': sorted(set(fo) - set(fn)),
        'changed': sorted(k for k in set(fo) & set(fn) if fo[k]['hash'] != fn[k]['hash']),
    }
    sections = {}
    for name in ('pages', 'modals'):
        o, n = _by(old[name], 'id'), _by(new[name], 'id')
        common = set(o) & set(n)
        sections[name] = {
            'added': sorted(set(n) - set(o)),
            'removed': sorted(set(o) - set(n)),
            'changed': sorted(k for k in common if o[k]['hash'] != n[k]['hash']),
            'text_changed': sorted(k for k in common if o[k].get('text_hash') != n[k].get('text_hash')),
        }
    co, cn = _by(old['controls'], 'key'), _by(new['controls'], 'key')
    sig = lambda c: (c.get('handlers'), c.get('calls'), c.get('type'))
    controls = {
        'added': [_public_control(cn[k]) for k in sorted(set(cn) - set(co))],
        'removed': [_public_control(co[k]) for k in sorted(set(co) - set(cn))],
        'changed': [_public_control(cn[k]) for k in sorted(set(co) & set(cn)) if sig(co[k]) != sig(cn[k])],
    }
    to, tn = old['db_calls']['by_table'], new['db_calls']['by_table']
    db = {
        'tables_added': sorted(set(tn) - set(to)),
        'tables_removed': sorted(set(to) - set(tn)),
        'calls_changed': {t: {'old': to[t], 'new': tn[t]} for t in sorted(set(to) & set(tn)) if to[t] != tn[t]},
        'dynamic': {'old': old['db_calls'].get('dynamic', 0), 'new': new['db_calls'].get('dynamic', 0)},
    }
    st = lambda inv: {f"{s['api']}.{s['method']}({s.get('key')})" for s in inv.get('storage', [])}
    rs = lambda inv: {f"{r['tag']} {r['url']}" for r in inv.get('resources', [])}
    nw = lambda inv: {f"{n.get('function')} → {n.get('target')}" for n in inv.get('network', [])}
    return {
        'counts': {k: {'old': old['counts'].get(k), 'new': new['counts'].get(k)} for k in COUNT_KEYS
                   if old['counts'].get(k) != new['counts'].get(k)},
        'functions': functions,
        'pages': sections['pages'],
        'modals': sections['modals'],
        'controls': controls,
        'db': db,
        'storage': {'added': sorted(st(new) - st(old)), 'removed': sorted(st(old) - st(new))},
        'resources': {'added': sorted(rs(new) - rs(old)), 'removed': sorted(rs(old) - rs(new))},
        'network': {'added': sorted(nw(new) - nw(old)), 'removed': sorted(nw(old) - nw(new))},
        'scripts_failed': [s for s in new['scripts'] if s.get('ok') is False],
    }


def is_empty(diff: Dict[str, Any]) -> bool:
    f, d = diff['functions'], diff['db']
    return not (diff['counts'] or f['added'] or f['removed'] or f['changed']
                or any(diff[s][k] for s in ('pages', 'modals') for k in ('added', 'removed', 'changed'))
                or any(diff['controls'].values()) or d['tables_added'] or d['tables_removed'] or d['calls_changed']
                or any(diff[s][k] for s in ('storage', 'resources', 'network') for k in ('added', 'removed')))


def load_coverage(path: Path) -> List[Dict[str, str]]:
    if not path.is_file():
        return []
    with open(path, newline='', encoding='utf-8') as fh:
        return list(csv.DictReader(fh))


def impact(diff: Dict[str, Any], old: Dict[str, Any], new: Dict[str, Any], modules: Dict[str, str],
           coverage: List[Dict[str, str]], max_depth: int = 8) -> List[Dict[str, Any]]:
    """For each added/removed/changed function: UI groups that can reach it, modules and mapped requirements."""
    callers: Dict[str, Set[str]] = defaultdict(set)
    for inv in (old, new):
        for f in inv['functions']:
            for callee in f.get('calls', []):
                callers[callee].add(f['name'])
    entry_groups: Dict[str, Set[str]] = defaultdict(set)
    for inv in (old, new):
        for c in inv['controls']:
            for name in c.get('calls', []):
                entry_groups[name].add(c['group'])

    def groups_reaching(name: str) -> Set[str]:
        seen, queue, groups = {name}, deque([(name, 0)]), set()
        while queue:
            current, depth = queue.popleft()
            groups |= entry_groups.get(current, set())
            if depth < max_depth:
                for caller in callers.get(current, ()):
                    if caller not in seen:
                        seen.add(caller)
                        queue.append((caller, depth + 1))
        return groups

    items = []
    for change, keys in (('added', diff['functions']['added']), ('removed', diff['functions']['removed']),
                         ('changed', diff['functions']['changed'])):
        for key in keys:
            name = key.rsplit('#', 1)[0]
            groups = sorted(groups_reaching(name))
            mods = sorted({modules[g] for g in groups if g in modules})
            reqs = [r for r in coverage if name and name in (r.get('legacy_locator') or '').split()]
            items.append({
                'function': key,
                'change': change,
                'ui_groups': groups,
                'modules': mods,
                'requirement_ids': sorted({r['requirement_id'] for r in reqs if r.get('requirement_id')}),
                'test_ids': sorted({t for r in reqs for t in (r.get('test_ids') or '').split() if t}),
                'mapping': 'MAPPED' if reqs else 'UNMAPPED_CHANGE',
            })
    for name in diff['pages']['added'] + diff['pages']['removed'] + diff['pages']['changed']:
        items.append({'page': name, 'change': 'page', 'modules': [modules.get(name, 'не сопоставлено')],
                      'mapping': 'UNMAPPED_CHANGE', 'requirement_ids': [], 'test_ids': [], 'ui_groups': [name]})
    return items
