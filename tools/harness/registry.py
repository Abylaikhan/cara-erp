#!/usr/bin/env python3
"""Registry traceability (ТЗ AC-09, M-ALL) — make registry.

Every element of the static registry of the accepted legacy reference — the harness inventory: pages, modal windows,
controls, functions, database tables, storage keys, network calls — gets a status and its evidence:

  IMPLEMENTED  found in the target: the id or the label of a control in the files of its page or window; a function named
               in the target code (ports carry the legacy names in their comments) or in a requirement of the coverage map
  DECIDED      a written decision covers it (a documented difference, a replacement): named in an ADR, or a rule
  DUPLICATE    a second view of an implemented element (rule)
  OBSOLETE     not carried over by a decision (rule)
  NOT_STARTED  its page, window or tab is not migrated yet; the rule names the task
  PENDING_DECISION  reviewed, whether to carry it over waits for the customer (unreachable in legacy, old functions —
               D-07, D-15); becomes OBSOLETE or NOT_STARTED with the answer
  UNREVIEWED   nothing of the above — to be triaged. Not allowed at acceptance (AC-09): the exit code is then 1.
A function whose name occurs nowhere in legacy but its own definition is dead code: OBSOLETE (ADR-0029).

Manual decisions: docs/coverage/registry-rules.csv (kind, selector, status, reference, note). Kinds:
  group     a page or modal id (or «global»): all its controls and the element itself
  area      the id of any legacy element: the controls within its lines (a tab, a block)
  control   «<group>|<tag>|<id>» or «<group>|L<line>»
  function  a function name;  function-re  a regular expression on function names
  table / storage / network   a database table, a storage key, «<callee>@<function>» of a network call
More specific rules win: control > area > group > automatic evidence. A function without evidence takes the status of the
controls that reach it through the call graph (handlers in generated HTML count as calls).

Writes docs/coverage/registry.csv (one row per element) and docs/coverage/registry.md (summary by module). Labels are
written only as plain UI text: a value of the redaction list of the inventory (legacy passwords, keys), a person-like name,
a long number, an e-mail or a phone is shown as «‹скрыто›».
"""
from __future__ import annotations

import argparse
import csv
import html
import html.parser
import io
import json
import re
import sys
from collections import Counter, defaultdict, deque
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Set, Tuple

sys.path.insert(0, str(Path(__file__).resolve().parent))
import inventory  # noqa: E402
from hlib import REPO_ROOT, atomic_write, load_config, sha256_file  # noqa: E402

STATUSES = ('IMPLEMENTED', 'DECIDED', 'DUPLICATE', 'OBSOLETE', 'NOT_STARTED', 'PENDING_DECISION', 'UNREVIEWED')
REVIEWED_AT_ACCEPTANCE = ('IMPLEMENTED', 'DECIDED', 'DUPLICATE', 'OBSOLETE')  # AC-09; NOT_STARTED — work still to do

# The Inertia page of each migrated legacy page; the others render NotMigrated.
PAGE_COMPONENTS = {
    'page-dashboard': 'Dashboard', 'page-mrp': 'Mrp', 'page-warehouse': 'Warehouse', 'page-purchases': 'Purchases',
    'page-esf': 'Esf', 'page-suppliers': 'Suppliers', 'page-catalog': 'Catalog', 'page-customers': 'Customers',
    'page-sales': 'Sales', 'page-crm': 'Crm', 'page-finance': 'Finance', 'page-money': 'Money',
    'page-expense_cats': 'ExpenseCats', 'page-money_salon': 'MoneySalon', 'page-reconcile': 'Reconcile',
    'page-salon_report': 'SalonReport', 'page-accruals': 'Accruals', 'page-hr': 'Hr', 'page-payroll': 'Payroll',
}
# The module of each legacy modal window (by its title and the page that opens it).
MODAL_MODULES = {
    'aliasModal': 'M03', 'aliasFindModal': 'M03', 'whDocModal': 'M03', 'whDocViewModal': 'M03', 'whMoveModal': 'M03',
    'addMaterialModal': 'M03',
    'normsModal': 'M04', 'newMaterialRequestModal': 'M04', 'goodsReceiptModal': 'M04', 'purchaseModal': 'M04',
    'esfDetailModal': 'M05', 'esfExpenseModal': 'M05', 'esfXmlImportModal': 'M05',
    'supplierCardModal': 'M06', 'orphanSuppliersModal': 'M06', 'mergeSuppliersModal': 'M06', 'spellingGroupsModal': 'M06',
    'addSupplierModal': 'M06',
    'productModal': 'M07', 'attrCatalogModal': 'M07',
    'personModal': 'M08', 'addDealerModal': 'M08', 'addSellerModal': 'M08', 'addClientModal': 'M08',
    'newCustOrderModal': 'M09', 'editOrderModal': 'M09', 'paymentModal': 'M09', 'orderReportModal': 'M09',
    'swDocModal': 'M09', 'invoicePreviewModal': 'M09', 'contractPreviewModal': 'M09',
    'leadModal': 'M10', 'taskModal': 'M10', 'crmLeadModal': 'M10', 'crmConvertModal': 'M10',
    'drilldownModal': 'M11', 'fixedCostModal': 'M11', 'orderMarginModal': 'M11', 'marginDetailModal': 'M11',
    'moneyTxModal': 'M12', 'reconDayModal': 'M12', 'salonTransferModal': 'M12', 'bankStatementModal': 'M12',
    'bankAccountModal': 'M12', 'advanceModal': 'M12',
    'chOrderModal': 'M13',
    'expCatModal': 'M14', 'expCatMergeModal': 'M14',
    'userModal': 'M17', 'employeeCardModal': 'M17',
    'addWageModal': 'M18',
    'addPayrollEmployeeModal': 'M19', 'wagePaymentModal': 'M19', 'payrollEntryModal': 'M19', 'payrollCardModal': 'M19',
    'vacationCalcModal': 'M20', 'salaryCalcModal': 'M20', 'accrualModal': 'M20',
    'cabEmailModal': 'M22',
    'newOrderModal': 'M02', 'pwbStageModal': 'M02', 'importResultModal': 'M02', 'techCardModal': 'M02',
    'tcTemplateEditorModal': 'M02', 'tcTemplateModal': 'M02', 'sfStageModal': 'M02', 'waybillModal': 'M02',
}
GLOBAL_FILES = ('resources/js/layouts', 'resources/js/pages/Auth', 'resources/js/app.ts', 'config/erp.php')
# Legacy blocks outside any page or modal that belong to a page (their controls are looked for in its files).
AREA_GROUPS = {'crm-popover': 'page-crm'}
# Strong evidence of a function: the status of the requirements that list it in the coverage map.
DONE_REQUIREMENTS = ('VERIFIED', 'IMPLEMENTED')
LATER_REQUIREMENTS = ('NOT_STARTED', 'BLOCKED', 'IN_PROGRESS')
TARGET_CODE = ('resources/js', 'app', 'routes', 'database', 'config', 'tests/Feature', 'tests/Unit', 'tests/e2e', 'tests/visual')
HANDLER = re.compile(r'''\bon(?:click|change|input|submit|keydown|keyup|keypress|blur|focus|dblclick|mousedown|mouseup|'''
                     r'''dragstart|dragend|dragover|dragleave|drop|paste|contextmenu)\s*=\s*\\?["'`]\s*(?:return\s+|event\.\w+\(\);?\s*)?'''
                     r'''([A-Za-z_$][\w$]*)\s*\(''')
LISTENER = re.compile(r'''addEventListener\(\s*['"][a-z]+['"]\s*,\s*([A-Za-z_$][\w$]*)\s*[,)]''')
PERSON = re.compile(r'^[А-ЯЁ][а-яё]+(\s+[А-ЯЁ][а-яё]+){1,2}$|[А-ЯЁ][а-яё]+\s+[А-ЯЁ]\.\s?([А-ЯЁ]\.)?')
SENSITIVE = re.compile(r'\d{5,}|@\w|\+7|8\s?\(?7\d\d')
VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'}


# ---------------------------------------------------------------------------------------------------- inputs
def accepted_inventory(root: Path, cfg: Dict[str, Any]) -> Tuple[Dict[str, Any], List[str], Path]:
    """The harness inventory of the accepted reference (built in its isolated container when not cached)."""
    sha = cfg['source']['accepted_ref']['sha256']
    work = root / cfg['paths']['work']
    blob = next((b for b in sorted((work / 'blobs').glob('*.html')) if sha256_file(b) == sha), None)
    if blob is None:
        sys.exit('the accepted reference is not in .local-audit/blobs: run make audit-daily first')
    inv, secrets, res = inventory.build_inventory(blob, work / 'inventory', cfg['images']['node'],
                                                  cfg['limits']['inventory_timeout_s'], cfg['limits']['inventory_memory'])
    if not inv:
        sys.exit('inventory not built: ' + res.output()[-400:])
    return inv, secrets, blob


class Ranges(html.parser.HTMLParser):
    """Line ranges of the legacy elements that have an id (for area rules)."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.stack: List[Tuple[str, Optional[str], int]] = []
        self.ranges: Dict[str, Tuple[int, int]] = {}

    def handle_starttag(self, tag: str, attrs: List[Tuple[str, Optional[str]]]) -> None:
        if tag in VOID:
            return
        self.stack.append((tag, dict(attrs).get('id'), self.getpos()[0]))

    def handle_endtag(self, tag: str) -> None:
        for i in range(len(self.stack) - 1, -1, -1):
            if self.stack[i][0] == tag:
                for t, ident, start in self.stack[i:]:
                    if ident and ident not in self.ranges:
                        self.ranges[ident] = (start, self.getpos()[0])
                del self.stack[i:]
                return


def element_ranges(blob: Path) -> Dict[str, Tuple[int, int]]:
    text = blob.read_text(encoding='utf-8', errors='replace')
    # Only the markup: script bodies would confuse the stack (and hold no static elements).
    text = re.sub(r'(<script\b[^>]*>)(.*?)(</script>)', lambda m: m.group(1) + '\n' * m.group(2).count('\n') + m.group(3), text,
                  flags=re.S | re.I)
    parser = Ranges()
    parser.feed(text)
    return parser.ranges


def read_rules(path: Path) -> List[Dict[str, str]]:
    if not path.is_file():
        return []
    with open(path, newline='', encoding='utf-8') as fh:
        rules = [r for r in csv.DictReader(fh) if (r.get('kind') or '').strip() and not r['kind'].startswith('#')]
    for r in rules:
        if r['status'] not in STATUSES[:-1]:
            sys.exit(f"registry rule {r['kind']},{r['selector']}: unknown status {r['status']}")
        r['used'] = ''
    return rules


def norm(text: str) -> str:
    return ' '.join((text or '').split())


def label_candidates(text: str) -> List[str]:
    """The label as written, and without what the target renders by itself: a counter «( 0 )» or «0», an amount «₸0», a
    sort arrow, a leading icon."""
    label = norm(text)
    bare = re.sub(r'\s*(\(\s*\d*\s*\)?|₸\s*[\d\s.,]*|[▲▼↑↓⇅]|\d+)\s*$', '', label).rstrip(' (:').strip()
    plain = re.sub(r'^[^\wА-Яа-яЁё№#]+', '', bare).strip()
    out = []
    for cand in (label, bare, plain):
        if cand and cand not in out:
            out.append(cand)
    return out


def target_files(root: Path) -> Dict[str, str]:
    files = {}
    for base in TARGET_CODE:
        p = root / base
        for f in ([p] if p.is_file() else p.rglob('*')):
            if f.is_file() and f.suffix in ('.vue', '.ts', '.php', '.py'):
                files[str(f.relative_to(root))] = f.read_text(encoding='utf-8', errors='replace')
    return files


IMPORT = re.compile(r'''from\s+['"](@/[^'"]+|\.{1,2}/[^'"]+)['"]''')


def with_imports(root: Path, start: Iterable[str], files: Dict[str, str]) -> Set[str]:
    """The given target files and every local module they import, recursively."""
    seen: Set[str] = set()
    queue = deque(start)
    while queue:
        rel = queue.popleft()
        if rel in seen or rel not in files:
            continue
        seen.add(rel)
        for spec in IMPORT.findall(files[rel]):
            base = (root / 'resources/js' / spec[2:]) if spec.startswith('@/') else (root / rel).parent / spec
            for cand in (base, base.with_suffix('.ts'), base.with_suffix('.vue'), base / 'index.ts'):
                key = str(cand.resolve().relative_to(root.resolve())) if cand.resolve().is_relative_to(root.resolve()) else ''
                if key in files:
                    queue.append(key)
                    break
    return seen


# ---------------------------------------------------------------------------------------------------- the trace
class Trace:
    def __init__(self, root: Path, inv: Dict[str, Any], secrets: List[str], blob: Path, rules: List[Dict[str, str]],
                 modules: Dict[str, str]):
        self.root, self.inv, self.secrets, self.rules, self.modules = root, inv, [s for s in secrets if len(s) >= 4], rules, modules
        self.lines = blob.read_text(encoding='utf-8', errors='replace').split('\n')
        self.ranges = element_ranges(blob)
        self.files = target_files(root)
        self.word_file: Dict[str, str] = {}  # each identifier of the target → the first file that names it
        for k in sorted(self.files):
            for w in set(re.findall(r'[A-Za-z_$][\w$]*', self.files[k])):
                self.word_file.setdefault(w, k)
        self.code_words = set(self.word_file)
        self.adr = {p.name: p.read_text(encoding='utf-8') for p in sorted((root / 'docs/adr').glob('*.md'))}
        with open(root / 'docs/coverage/requirements.csv', newline='', encoding='utf-8') as fh:
            self.coverage = list(csv.DictReader(fh))
        self.rows: List[Dict[str, Any]] = []
        self.reach = self.reaching_controls()
        source = '\n'.join(self.lines)
        words = Counter(re.findall(r'(?<![\w$.])[A-Za-z_$][\w$]*(?![\w$])', source))
        definitions = Counter(f['name'] for f in inv['functions'])
        self.dead = {n for n in definitions if '.' not in n and words[n] <= definitions[n]}

    # -- helpers
    def label(self, text: str) -> str:
        t = norm(text)
        if not t:
            return ''
        if any(s in t for s in self.secrets) or PERSON.search(t) or SENSITIVE.search(t):
            return '‹скрыто›'
        return t[:80]

    def rule(self, kind: str, selector: str) -> Optional[Dict[str, str]]:
        for r in self.rules:
            if r['kind'] == kind and r['selector'] == selector:
                r['used'] = 'yes'
                return r
        return None

    def area_rule(self, line: int) -> Optional[Dict[str, str]]:
        """The innermost area rule whose element contains the line."""
        best = None
        for r in self.rules:
            if r['kind'] != 'area' or r['selector'] not in self.ranges:
                continue
            a, b = self.ranges[r['selector']]
            if a <= line <= b and (best is None or a >= self.ranges[best['selector']][0]):
                best = r
        if best:
            best['used'] = 'yes'
        return best

    def group_files(self, group: str) -> Set[str]:
        if group in PAGE_COMPONENTS:
            return with_imports(self.root, [f'resources/js/pages/{PAGE_COMPONENTS[group]}.vue'], self.files)
        if group == 'global':
            start = [k for k in self.files if any(k == g or k.startswith(g + '/') for g in GLOBAL_FILES)]
            return with_imports(self.root, start, self.files)
        holders = [k for k, v in self.files.items() if k.endswith('.vue') and re.search(r'''\bid=["']''' + re.escape(group) + r'''["']''', v)]
        return with_imports(self.root, holders, self.files)

    # -- the passes
    def run(self) -> None:
        inv = self.inv
        group_files = {g: self.group_files(g) for g in {c['group'] for c in inv['controls']} | {p['id'] for p in inv['pages']}
                       | {m['id'] for m in inv['modals']} | set(AREA_GROUPS.values())}
        group_text = {g: norm(html.unescape('\n'.join(self.files[f] for f in fs))) for g, fs in group_files.items()}
        by_code = {v.split(' ', 1)[0]: v for v in self.modules.values()}
        modal_modules = {m: by_code.get(code, code) for m, code in MODAL_MODULES.items()}

        def module_of(group: str) -> str:
            if group == 'global':
                return 'UI оболочка'
            return self.modules.get(group) or modal_modules.get(group) or 'не сопоставлено'

        # pages and modals
        for kind, items in (('page', inv['pages']), ('modal', inv['modals'])):
            for el in items:
                g = el['id']
                r = self.rule('group', g)
                if r:
                    status, evidence = r['status'], r['reference']
                elif group_files[g]:
                    status, evidence = 'IMPLEMENTED', sorted(group_files[g])[0]
                else:
                    status, evidence = 'UNREVIEWED', ''
                self.add(kind, g, g, el['line'], module_of(g), status, evidence, '')

        # controls
        self.control_status: Dict[int, str] = {}
        self.control_ref: Dict[int, str] = {}
        ordinal: Counter = Counter()
        for i, c in enumerate(inv['controls']):
            g, line = c['group'], c['line']
            ident = c.get('id') or ''
            element = f"{g}|{c['tag']}|{ident}" if ident else f"{g}|L{line}"
            home = next((AREA_GROUPS[a] for a in AREA_GROUPS if a in self.ranges and self.ranges[a][0] <= line <= self.ranges[a][1]), g)
            r = (self.rule('control', element) or (self.rule('control', f'{g}|L{line}') if ident else None)
                 or self.area_rule(line) or self.rule('group', g))
            if r:
                status, evidence = r['status'], r['reference']
            else:
                status, evidence = self.control_evidence(c, group_text.get(home, ''), group_files.get(home, set()))
                if status == 'IMPLEMENTED':
                    status, evidence = self.control_action(c, evidence)
            self.control_status[i], self.control_ref[i] = status, evidence
            ordinal[element] += 1
            self.add('control', element + (f"#{ordinal[element]}" if ordinal[element] > 1 else ''), g, line, module_of(g),
                     status, evidence, self.label(c.get('text', '')), c['tag'])

        # A button that clicks another control (a hidden file input) does what that control does.
        by_id = {(c['group'], c.get('id')): i for i, c in enumerate(inv['controls']) if c.get('id')}
        rows_of = {i: row for i, row in zip(range(len(inv['controls'])), [r for r in self.rows if r['kind'] == 'control'])}
        for i, c in enumerate(inv['controls']):
            m = re.search(r"""getElementById\(['"]([\w-]+)['"]\)\.click\(\)""", self.lines[c['line'] - 1])
            j = by_id.get((c['group'], m.group(1))) if m else None
            if j is not None and j != i and self.control_status[i] == 'IMPLEMENTED' and self.control_status[j] in ('NOT_STARTED', 'PENDING_DECISION'):
                self.control_status[i], self.control_ref[i] = self.control_status[j], self.control_ref[j]
                rows_of[i].update(status=self.control_status[j], evidence=f'clicks {m.group(1)}: {self.control_ref[j]}')

        # functions
        for f in inv['functions']:
            name = f['name']
            r = self.rule('function', name) or self.function_re(name)
            controls = self.reach.get(name, set())
            mods = sorted({module_of(inv['controls'][i]['group']) for i in controls})
            module = ', '.join(mods[:3]) + (' …' if len(mods) > 3 else '') if mods else '—'
            if r:
                status, evidence = r['status'], r['reference']
            else:
                status, evidence = self.function_evidence(name, controls)
            self.add('function', f['key'], '', f['line'], module, status, evidence, '')

        # database tables, storage, network
        for table in sorted(inv['db_calls']['by_table']):
            r = self.rule('table', table)
            status, evidence = (r['status'], r['reference']) if r else self.table_evidence(table)
            self.add('table', table, '', '', '—', status, evidence, '')
        function_rows = [(f['line'], f['end_line'], f['name']) for f in inv['functions']]
        status_of = {}
        for row in self.rows:
            if row['kind'] == 'function':
                status_of.setdefault(row['element'].rsplit('#', 1)[0], (row['status'], row['evidence']))
        for s in inv['storage']:
            s = dict(s, key=s.get('key') or '(computed key)')
            r = self.rule('storage', s['key'])
            named = s['key'] in '\n'.join(self.files.values())
            holder = max((f for f in function_rows if f[0] <= s['line'] <= f[1]), key=lambda f: f[0], default=None)
            if r:
                status, evidence = r['status'], r['reference']
            elif named:
                status, evidence = 'IMPLEMENTED', 'the same key in the target'
            elif holder and holder[2] in status_of:  # a key is a part of the function that reads or writes it
                st, ev = status_of[holder[2]]
                status, evidence = st, f'as its function {holder[2]}' + (f': {ev}' if ev else '')
            else:
                status, evidence = 'UNREVIEWED', ''
            self.add('storage', f"{s['api']}.{s['method']}:{s['key']}", '', s['line'], 'UI оболочка', status, evidence, '')
        function_status = {}
        for row in self.rows:
            if row['kind'] == 'function':
                function_status.setdefault(row['element'].rsplit('#', 1)[0], (row['status'], row['evidence']))
        for n in inv['network']:
            sel = f"{n['callee']}@{n.get('function') or ''}"
            r = self.rule('network', sel)
            if r:
                status, evidence = r['status'], r['reference']
            elif n.get('function') in function_status:  # a call is a part of its function
                st, ev = function_status[n['function']]
                status, evidence = st, f"as its function {n['function']}" + (f': {ev}' if ev else '')
            else:
                status, evidence = 'UNREVIEWED', ''
            self.add('network', sel, '', n['line'], '—', status, evidence, '')

    def add(self, kind: str, element: str, group: str, line: Any, module: str, status: str, evidence: str, label: str,
            tag: str = '') -> None:
        self.rows.append({'kind': kind, 'element': element, 'tag': tag, 'group': group, 'line': line, 'module': module,
                          'status': status, 'evidence': evidence, 'label': label})

    def control_evidence(self, c: Dict[str, Any], text: str, files: Set[str]) -> Tuple[str, str]:
        if not files:
            return 'UNREVIEWED', ''
        ident = c.get('id') or ''
        if ident and re.search(r'(?<![\w-])' + re.escape(ident) + r'(?![\w-])', text):
            return 'IMPLEMENTED', 'id'
        if c.get('name') and re.search(r'''name=["']''' + re.escape(c['name']) + r'''["']''', text):
            return 'IMPLEMENTED', 'name'
        labels = label_candidates(c.get('text', ''))
        if c['tag'] in ('input', 'textarea') and not labels:  # an input is known by its placeholder
            labels = [norm(x) for x in re.findall(r'placeholder="([^"]+)"', self.lines[c['line'] - 1])]
        for cand in labels:
            if cand in text:
                return 'IMPLEMENTED', 'label'
        if not ident and not labels:
            if c['tag'] == 'th' and not c.get('calls'):
                return 'IMPLEMENTED', 'an empty column header: its table is compared visually'
            strong = [self.strong(n) for n in c.get('calls', [])]
            if any(st == 'IMPLEMENTED' for st, _ in strong):
                return 'IMPLEMENTED', next(ev for st, ev in strong if st == 'IMPLEMENTED')
        return 'UNREVIEWED', ''

    def function_re(self, name: str) -> Optional[Dict[str, str]]:
        for r in self.rules:
            if r['kind'] == 'function-re' and re.fullmatch(r['selector'], name):
                r['used'] = 'yes'
                return r
        return None

    def strong(self, name: str) -> Tuple[Optional[str], str]:
        """The status of a function by the coverage map: the requirements that list it."""
        reqs = [r for r in self.coverage if name in (r.get('legacy_locator') or '').split()]
        done = sorted({r['requirement_id'] for r in reqs if r['status'] in DONE_REQUIREMENTS})
        if done:
            return 'IMPLEMENTED', 'requirement ' + ', '.join(done)
        later = sorted({f"{r['requirement_id']}" + (f" ({r['decision_id']})" if r.get('decision_id') else '') for r in reqs
                        if r['status'] in LATER_REQUIREMENTS})
        if later:
            return 'NOT_STARTED', 'requirement ' + ', '.join(later)
        return None, ''

    def function_evidence(self, name: str, controls: Set[int]) -> Tuple[str, str]:
        status, evidence = self.strong(name)
        if status:
            return status, evidence
        if name in self.dead:
            return 'OBSOLETE', 'ADR-0029: dead code — called from nowhere in legacy'
        statuses = {self.control_status[i] for i in controls}
        refs = sorted({self.control_ref[i] for i in controls if self.control_status[i] != 'IMPLEMENTED' and self.control_ref[i]})
        if statuses and not statuses & {'IMPLEMENTED', 'UNREVIEWED'}:
            for s in ('NOT_STARTED', 'PENDING_DECISION', 'DECIDED', 'DUPLICATE', 'OBSOLETE'):
                if s in statuses:
                    return s, 'by its controls: ' + '; '.join(refs[:3])
        if name in self.code_words:
            return 'IMPLEMENTED', 'named in ' + self.word_file[name]
        adr = next((k for k, v in self.adr.items() if re.search(r'(?<![\w$])' + re.escape(name) + r'(?![\w$])', v)), None)
        if adr:
            return 'DECIDED', adr
        if not statuses:
            return 'UNREVIEWED', 'not reached from the static UI'
        return 'UNREVIEWED', 'reached from migrated controls, not in the coverage map'

    def decided(self, name: str) -> Tuple[Optional[str], str]:
        """The status of a function known before the trace: its rule, else the coverage map."""
        r = self.rule('function', name) or self.function_re(name)
        if r:
            return r['status'], r['reference']
        return self.strong(name)

    def control_action(self, c: Dict[str, Any], evidence: str) -> Tuple[str, str]:
        """A control that is there but whose legacy action is still to come (the target shows a message) is NOT_STARTED;
        one whose action waits for a decision is PENDING_DECISION."""
        for wanted in ('NOT_STARTED', 'PENDING_DECISION'):
            later = [f'{name}: {ref}' for name in c.get('calls', []) for status, ref in [self.decided(name)] if status == wanted]
            if later:
                return wanted, 'present, its action comes later — ' + '; '.join(later[:2])
        return 'IMPLEMENTED', evidence

    def table_evidence(self, table: str) -> Tuple[str, str]:
        """Imported by legacy:import — or explained by legacy:control-totals (a table without an importer and why)."""
        totals = self.files.get('app/Console/Commands/LegacyControlTotals.php', '')
        explained = re.search(r"""['"]""" + re.escape(table) + r"""['"]\s*=>\s*'([^']+)'""", totals)
        if explained:
            return 'DECIDED', 'legacy:control-totals: ' + explained.group(1)
        for k, v in sorted(self.files.items()):
            if k.startswith('app/Legacy/') and re.search(r"""['"]""" + re.escape(table) + r"""['"]""", v):
                return 'IMPLEMENTED', 'legacy:import — ' + k
        return 'UNREVIEWED', ''

    def reaching_controls(self, max_depth: int = 8) -> Dict[str, Set[int]]:
        """For each function: the nearest controls that reach it — directly, through calls, or through the handlers of
        the HTML the functions generate (onclick="g(…)" inside a template string)."""
        inv = self.inv
        callers: Dict[str, Set[str]] = defaultdict(set)
        for f in inv['functions']:
            for callee in f.get('calls', []):
                callers[callee].add(f['name'])
            body = '\n'.join(self.lines[f['line'] - 1:f['end_line']])
            for callee in set(HANDLER.findall(body)) | set(LISTENER.findall(body)):
                callers[callee].add(f['name'])
        entries: Dict[str, Set[int]] = defaultdict(set)
        for i, c in enumerate(inv['controls']):
            for name in c.get('calls', []):
                entries[name].add(i)
        out: Dict[str, Set[int]] = {}
        for f in inv['functions']:
            # The nearest controls only: the first depth at which any control reaches the function. Generic dispatchers
            # (save → re-render everything) make almost any function reachable from everywhere further away.
            seen, level, found, depth = {f['name']}, [f['name']], set(), 0
            while level and not found and depth <= max_depth:
                for name in level:
                    found |= entries.get(name, set())
                nxt = []
                for name in level:
                    for caller in callers.get(name, ()):
                        if caller not in seen:
                            seen.add(caller)
                            nxt.append(caller)
                level, depth = nxt, depth + 1
            out[f['name']] = found
        return out

# ---------------------------------------------------------------------------------------------------- outputs
def write_outputs(root: Path, t: Trace, ref: Dict[str, Any]) -> Tuple[Counter, List[Dict[str, Any]]]:
    rows = t.rows
    buf = io.StringIO()
    w = csv.DictWriter(buf, fieldnames=['kind', 'element', 'tag', 'group', 'line', 'module', 'status', 'evidence', 'label'], lineterminator='\n')
    w.writeheader()
    w.writerows(rows)
    atomic_write(root / 'docs/coverage/registry.csv', buf.getvalue())

    total = Counter(r['status'] for r in rows)
    by_kind: Dict[str, Counter] = defaultdict(Counter)
    for r in rows:
        by_kind[r['kind']][r['status']] += 1
    by_module: Dict[str, Counter] = defaultdict(Counter)
    for r in rows:
        if r['kind'] in ('page', 'modal', 'control'):
            by_module[r['module']][r['status']] += 1
    unreviewed = [r for r in rows if r['status'] == 'UNREVIEWED']
    stale = [r for r in t.rules if not r['used']]
    head = ['Статус'] + list(STATUSES)
    md = [
        '# Трассировка реестра legacy (AC-09)', '',
        f"Принятый эталон `{ref['commit'][:7]}` (SHA-256 `{ref['sha256'][:12]}…`). Создаётся `make registry` "
        f"(`tools/harness/registry.py`); решения — `docs/coverage/registry-rules.csv`, построчно — `registry.csv`.", '',
        '## Итог', '',
        '| Элементы | ' + ' | '.join(STATUSES) + ' | Всего |', '|---|' + '---:|' * (len(STATUSES) + 1),
    ]
    for kind in ('page', 'modal', 'control', 'function', 'table', 'storage', 'network'):
        c = by_kind[kind]
        md.append(f'| {kind} | ' + ' | '.join(str(c[s]) for s in STATUSES) + f' | {sum(c.values())} |')
    md.append('| **всего** | ' + ' | '.join(f'**{total[s]}**' for s in STATUSES) + f' | **{sum(total.values())}** |')
    md += ['', '## Страницы, окна и элементы по модулям', '',
           '| Модуль | ' + ' | '.join(STATUSES) + ' |', '|---|' + '---:|' * len(STATUSES)]
    for mod in sorted(by_module):
        md.append(f'| {mod} | ' + ' | '.join(str(by_module[mod][s]) for s in STATUSES) + ' |')
    md += ['', f'## Не просмотрено: {len(unreviewed)}', '']
    md += [f"- {r['kind']} `{r['element']}`" + (f" ({r['label']})" if r['label'] else '') + (f" — {r['evidence']}" if r['evidence'] else '')
           for r in unreviewed[:300]]
    if len(unreviewed) > 300:
        md.append(f'- … ещё {len(unreviewed) - 300} — в `registry.csv`')
    md += ['', f'## Правила, которые ничего не задели: {len(stale)}', '']
    md += [f"- {r['kind']} `{r['selector']}`" for r in stale]
    atomic_write(root / 'docs/coverage/registry.md', '\n'.join(md) + '\n')
    return total, unreviewed


def main(argv: Optional[List[str]] = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--root', type=Path, default=REPO_ROOT)
    ap.add_argument('--allow-unreviewed', action='store_true', help='exit 0 while elements are still unreviewed (work in progress)')
    args = ap.parse_args(argv)
    cfg = load_config(local=False)
    inv, secrets, blob = accepted_inventory(args.root, cfg)
    rules = read_rules(args.root / 'docs/coverage/registry-rules.csv')
    t = Trace(args.root, inv, secrets, blob, rules, cfg.get('modules', {}))
    t.run()
    total, unreviewed = write_outputs(args.root, t, cfg['source']['accepted_ref'])
    print('registry: ' + ', '.join(f'{s} {total[s]}' for s in STATUSES) + f' — docs/coverage/registry.md')
    stale = [r for r in rules if not r['used']]
    if stale:
        print(f'rules that matched nothing: {len(stale)}')
    return 0 if not unreviewed or args.allow_unreviewed else 1


if __name__ == '__main__':
    sys.exit(main())
