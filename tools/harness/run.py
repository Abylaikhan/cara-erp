#!/usr/bin/env python3
"""PromTech audit harness — entry point of `make audit-daily` (ТЗ §7 RUN-01…RUN-04).

  python3 tools/harness/run.py daily [--target-ref REF] [--mode daily|manual] [--no-target]
  python3 tools/harness/run.py status

Exit codes: 0 — all required checks passed and the report was published; 1 — a required check failed;
2 — the required set is incomplete (BLOCKED/NOT_RUN) or publication failed; 3 — harness error;
75 — another audit holds the lock (ALREADY_RUNNING).
"""
from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import fcntl
import os
import platform
import re
import secrets
import subprocess
import sys
import time
import traceback
from pathlib import Path
from typing import Any, Dict, Iterator, List, Optional, Tuple

sys.path.insert(0, str(Path(__file__).resolve().parent))

import ai_review  # noqa: E402
import compare  # noqa: E402
import findings as findings_mod  # noqa: E402
import inventory  # noqa: E402
import publish  # noqa: E402
import tasks  # noqa: E402
from checks import (EXIT_ALREADY_RUNNING, EXIT_HARNESS_ERROR, Check, counts, execution_status,  # noqa: E402
                    exit_code, product_status)
from fetch_source import SourceError, SourceRepo, case_collisions, exact_entry  # noqa: E402
from hlib import (HARNESS_DIR, HARNESS_VERSION, REPO_ROOT, Redactor, atomic_write, canonical_json,  # noqa: E402
                  env_file_secrets, home_path, iso, load_config, local_dt, now_utc, parse_iso, read_json,
                  sha256_bytes, sha256_file, write_json)
from schema_check import validate  # noqa: E402
from target import TargetError, TargetRunner, parse_junit  # noqa: E402

SUITE_BASE = 'suite-1'


@contextlib.contextmanager
def timed(check: Check) -> Iterator[Check]:
    started = time.monotonic()
    try:
        yield check
    finally:
        check.duration_s = round(check.duration_s + time.monotonic() - started, 2)


def harness_hash() -> str:
    files = sorted(p for p in HARNESS_DIR.rglob('*') if p.is_file() and 'node_modules' not in p.parts
                   and '__pycache__' not in p.parts)
    return sha256_bytes(b''.join(p.relative_to(HARNESS_DIR).as_posix().encode() + b'\0' + p.read_bytes() for p in files))[:12]


class Audit:
    def __init__(self, cfg: Dict[str, Any], root: Path, mode: str):
        self.cfg, self.root, self.mode = cfg, root, mode
        p = cfg['paths']
        self.work = root / p['work']
        self.reports = root / p['reports']
        self.changelog = root / p['changelog']
        self.state_path = root / p['state']
        self.findings_path = root / p['findings']
        self.tz = cfg['timezone']
        self.image = cfg['images']['node']
        self.redactor = Redactor()
        self.log_dir: Path = self.work

    # ------------------------------------------------------------------ infrastructure
    def run(self, target_ref: str, skip_target: bool) -> int:
        self.work.mkdir(parents=True, exist_ok=True)
        with open(self.work / 'run.lock', 'a+') as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                print('ALREADY_RUNNING: другой запуск аудита держит lock; состояние и журнал не изменены.')
                return EXIT_ALREADY_RUNNING
            try:
                return self._run(target_ref, skip_target)
            except Exception:  # pragma: no cover - reported, never swallowed
                trace = self.redactor.redact(traceback.format_exc())
                atomic_write(self.log_dir / 'harness-error.log', trace)
                print(trace, file=sys.stderr)
                return EXIT_HARNESS_ERROR

    def hook(self, name: str) -> None:
        """Fault injection for `make harness-selftest` only (requires HARNESS_SELFTEST=1)."""
        if os.environ.get('HARNESS_SELFTEST') != '1':
            return
        for item in os.environ.get('HARNESS_SELFTEST_HOOKS', '').split(';;'):
            key, _, action = item.partition('=')
            if key == name and action == 'crash':
                raise RuntimeError(f'selftest crash at {name}')
            if key == name and action:
                subprocess.run(action, shell=True, check=True, stdout=subprocess.DEVNULL)

    def save_log(self, name: str, text: str) -> str:
        path = self.log_dir / f'{name}.log'
        atomic_write(path, self.redactor.redact(text))
        return path.relative_to(self.root).as_posix()

    def load_state(self) -> Dict[str, Any]:
        acc = self.cfg['source']['accepted_ref']
        state = read_json(self.state_path) or {'schema': 'promtech.audit-state/1', 'last_attempt': None,
                                               'last_observed': None, 'last_reported': None,
                                               'last_verified': None, 'changelog': None}
        state['accepted_source_ref'] = {'commit': acc['commit'], 'sha256': acc['sha256'], 'basis': acc.get('basis', '')}
        return state

    def save_state(self, state: Dict[str, Any]) -> None:
        write_json(self.state_path, state)

    def load_redactions(self) -> None:
        self.redactor.add(read_json(self.work / 'state' / 'redact-values.json', []))
        self.redactor.add(env_file_secrets(self.root / '.env'))
        for name in ('api_key_file',):
            f = home_path(self.cfg.get('sheets', {}).get(name, ''))
            if f.is_file():
                self.redactor.add([f.read_text().strip()])
        for var in ('ANTHROPIC_API_KEY', 'GITHUB_TOKEN', 'GH_TOKEN'):
            if os.environ.get(var):
                self.redactor.add([os.environ[var]])

    def remember_redactions(self, values: List[str]) -> None:
        path = self.work / 'state' / 'redact-values.json'
        known = set(read_json(path, []))
        if not set(values) <= known:
            write_json(path, sorted(known | set(values)), mode=0o600)
        self.redactor.add(values)

    # ------------------------------------------------------------------ source (legacy)
    def source_stage(self, state: Dict[str, Any], checks: List[Check]) -> Tuple[Dict[str, Any], Dict[str, Any]]:
        scfg, acc = self.cfg['source'], self.cfg['source']['accepted_ref']
        last = state.get('last_reported') or {}
        src: Dict[str, Any] = {
            'repository': scfg['repository'], 'branch': scfg['branch'], 'path': scfg['path'], 'observed_head': None,
            'base_ref': last.get('source_ref'), 'blob': None, 'sha256': None, 'status': 'BLOCKED', 'commits': [],
            'commit_count': 0, 'watched': [], 'case_collisions': [], 'diff': None, 'impact': [],
            'numstat': {'added': 0, 'removed': 0}, 'diff_vs_accepted': None,
            'accepted_ref': {'commit': acc['commit'], 'sha256': acc['sha256'], 'matches_observed': None},
        }
        new = lambda i, t, req=True: Check(i, t, req, 'source', scope=f"{scfg['repository']} {scfg['path']}")
        pin = new('SRC-PIN', 'Фиксация HEAD источника один раз в начале запуска')
        fetch = new('SRC-FETCH', 'Получение коммитов по SHA (без checkout)')
        accepted = new('SRC-ACCEPTED', 'Целостность принятого эталона: SHA-256 точного пути')
        path = new('SRC-PATH', 'Точный путь: blob, размер, git-хеш, SHA-256')
        case = new('SRC-CASE', 'Коллизии регистра в дереве (Index.html / index.html)')
        history = new('SRC-HISTORY', 'Непрерывность истории от прошлого отчёта (ancestry)')
        inv = new('SRC-INVENTORY', 'Inventory: страницы, модальные окна, элементы, функции (AST), таблицы БД')
        syntax = new('SRC-SYNTAX', 'Синтаксис всех inline-скриптов (acorn)')
        diff = new('SRC-DIFF', 'Структурный diff и карта влияния на модули')
        watched = new('SRC-WATCHED', 'Изменения других HTML (не эталон)', False)
        group = [pin, fetch, accepted, path, case, history, inv, syntax, diff, watched]
        checks += group
        inv_info: Dict[str, Any] = {}
        repo = SourceRepo(scfg['repository'], self.work / 'source.git', self.cfg['limits']['git_timeout_s'])

        def block_rest(reason: str) -> None:
            for c in group:
                if c.status == 'NOT_RUN':
                    c.set('BLOCKED', reason)

        try:
            with timed(pin):
                repo.ensure()
                head = repo.ls_remote(scfg['branch'])
            pin.set('PASS', details={'head': head})
            pin.command = f"git ls-remote origin refs/heads/{scfg['branch']}"
            src['observed_head'] = head
            self.hook('after_pin')
            with timed(fetch):
                repo.fetch(scfg['branch'], [head, acc['commit']])
                if src['base_ref'] and not repo.has_commit(src['base_ref']):
                    try:
                        repo.fetch(scfg['branch'], [src['base_ref']])
                    except SourceError:
                        pass  # handled by SRC-HISTORY
            fetch.set('PASS')
            fetch.command = 'git fetch --no-tags origin <branch> <pinned SHAs>'
        except SourceError as exc:
            failed = pin if pin.status == 'NOT_RUN' else fetch
            failed.set('BLOCKED', exc.code)
            failed.evidence.append(self.save_log('source-git', '\n'.join(r.output() for r in repo.log)))
            block_rest('источник не получен')
            return src, inv_info
        blobs = self.work / 'blobs'

        def materialize(commit: str) -> Tuple[Optional[Dict[str, Any]], List[Dict[str, Any]], Optional[Path]]:
            tree = repo.tree(commit)
            entry = exact_entry(tree, scfg['path'])
            if entry is None:
                return None, tree, None
            target = blobs / f"{entry['sha']}.html"
            if not target.is_file():
                data = repo.read_blob(str(entry['sha']), entry['size'])  # verifies git hash and size
                atomic_write(target, data, mode=0o444)
            return entry, tree, target

        try:
            with timed(path):
                entry, tree, blob_file = materialize(head)
                if entry is None:
                    path.set('FAIL', f"PATH_MISSING: в коммите {head[:7]} нет файла с точным именем {scfg['path']}")
                else:
                    src.update(blob=entry['sha'], sha256=sha256_file(blob_file), bytes=entry['size'])
                    path.set('PASS', details={'blob': entry['sha'], 'bytes': entry['size'], 'sha256': src['sha256']})
            with timed(case):
                src['case_collisions'] = case_collisions(tree)
                case.set('PASS', 'обнаружены: ' + '; '.join('/'.join(g) for g in src['case_collisions'])
                         if src['case_collisions'] else 'коллизий нет', collisions=src['case_collisions'])
            with timed(accepted):
                if not repo.has_commit(acc['commit']):
                    accepted.set('FAIL', 'принятый коммит недоступен')
                else:
                    a_entry, _, a_file = materialize(acc['commit'])
                    actual = sha256_file(a_file) if a_file else None
                    if actual == acc['sha256']:
                        accepted.set('PASS', details={'blob': a_entry['sha']})
                    else:
                        accepted.set('FAIL', 'SHA-256 эталона не совпадает с принятым значением (подмена источника?)',
                                     actual=actual)
                    src['accepted_ref']['matches_observed'] = (a_entry is not None and entry is not None
                                                               and a_entry['sha'] == entry['sha'])
            if entry is None:
                block_rest('нет файла точного пути')
                return src, inv_info
            # history and commit range
            base = src['base_ref']
            with timed(history):
                if base is None:
                    src['status'] = 'INITIAL_BASELINE'
                    history.set('PASS', 'INITIAL_BASELINE: прошлого отчёта нет')
                    src['commits'] = repo.commits(None, head, limit=1)
                elif not repo.has_commit(base):
                    src['status'] = 'HISTORY_DIVERGED'
                    history.set('FAIL', f'HISTORY_DIVERGED: прошлый ref {base[:7]} недоступен ни локально, ни на сервере')
                else:
                    anc = repo.is_ancestor(base, head)
                    src['commits'] = repo.commits(base, head)
                    src['commit_count'] = repo.commit_count(base, head)
                    if anc:
                        history.set('PASS', f"линейно: {src['commit_count']} коммит(ов) с прошлого отчёта")
                    else:
                        src['status'] = 'HISTORY_DIVERGED'
                        history.set('FAIL', f'HISTORY_DIVERGED: {base[:7]} не является предком {head[:7]} (force-push?)')
            # inventory (isolated container)
            with timed(inv):
                deps = inventory.ensure_node_deps(self.image)
                if not deps.ok:
                    inv.set('BLOCKED', 'npm ci для инструментов harness не выполнен')
                    inv.evidence.append(self.save_log('harness-node-deps', deps.output()))
                else:
                    inventory_data, values, res = inventory.build_inventory(
                        blob_file, self.work / 'inventory', self.image, self.cfg['limits']['inventory_timeout_s'],
                        self.cfg['limits']['inventory_memory'])
                    inv.command = 'docker run --network none --read-only … node legacy_inventory.mjs'
                    inv.evidence.append(self.save_log('source-inventory', res.output()))
                    if not res.ok:
                        inv.set('BLOCKED', f'inventory завершился с кодом {res.exit_code}')
                    else:
                        self.remember_redactions(values)
                        inv_info = inventory_data
                        inv.set('PASS', details={'counts': inventory_data['counts']})
            if not inv_info:
                block_rest('inventory не построен')
                return src, inv_info
            failed_scripts = [s for s in inv_info['scripts'] if s.get('ok') is False]
            syntax.set('FAIL' if failed_scripts else 'PASS',
                       f'ошибки в {len(failed_scripts)} скриптах' if failed_scripts else f"{len(inv_info['scripts'])} скриптов разобраны",
                       failed_scripts=failed_scripts)
            # diff against the last reported snapshot and against the accepted baseline
            with timed(diff):
                modules = self.cfg.get('modules', {})
                coverage = compare.load_coverage(self.root / self.cfg['paths']['coverage'])
                base_blob = last.get('source_blob')
                if src['status'] == 'INITIAL_BASELINE':
                    diff.set('PASS', 'INITIAL_BASELINE: зафиксирован полный inventory')
                elif base_blob == src['blob'] and src['status'] != 'HISTORY_DIVERGED':
                    src['status'] = 'UNCHANGED'
                    diff.set('PASS', 'blob не изменился')
                else:
                    base_file = blobs / f'{base_blob}.html'
                    if base_blob and not base_file.is_file():
                        b_entry, _, b_path = materialize(src['base_ref']) if repo.has_commit(src['base_ref']) else (None, [], None)
                        base_file = b_path if b_path else base_file
                    if not base_blob or not base_file.is_file():
                        diff.set('BLOCKED', 'снимок прошлого отчёта недоступен')
                    else:
                        old_inv, old_values, _ = inventory.build_inventory(
                            base_file, self.work / 'inventory', self.image, self.cfg['limits']['inventory_timeout_s'],
                            self.cfg['limits']['inventory_memory'])
                        self.remember_redactions(old_values)
                        src['diff'] = compare.diff_inventories(old_inv, inv_info)
                        src['impact'] = compare.impact(src['diff'], old_inv, inv_info, modules, coverage)
                        src['numstat'] = dict(zip(('added', 'removed'), repo.numstat(base_blob, src['blob'])))
                        if src['status'] != 'HISTORY_DIVERGED':
                            src['status'] = 'CHANGED'
                        diff.set('PASS', 'структурный diff построен' + (' (сравнение снимков при разошедшейся истории)'
                                                                          if src['status'] == 'HISTORY_DIVERGED' else ''))
                if src['accepted_ref']['matches_observed'] is False and accepted.status == 'PASS':
                    a_entry, _, a_file = materialize(acc['commit'])
                    a_inv, a_values, _ = inventory.build_inventory(
                        a_file, self.work / 'inventory', self.image, self.cfg['limits']['inventory_timeout_s'],
                        self.cfg['limits']['inventory_memory'])
                    self.remember_redactions(a_values)
                    d = compare.diff_inventories(a_inv, inv_info)
                    f = d['functions']
                    src['diff_vs_accepted'] = (f"функции +{len(f['added'])}/−{len(f['removed'])}/~{len(f['changed'])}, "
                                               f"страницы изменены {len(d['pages']['changed'])}, модальные окна {len(d['modals']['changed'])}")
            # other HTML files
            base_tree = repo.tree(src['base_ref']) if src['base_ref'] and repo.has_commit(src['base_ref']) else []
            for p in scfg.get('watched_paths', []):
                now_e, was_e = exact_entry(tree, p), exact_entry(base_tree, p)
                src['watched'].append({'path': p, 'blob': now_e['sha'] if now_e else None,
                                       'previous_blob': was_e['sha'] if was_e else None,
                                       'changed': bool(base_tree) and (now_e or {}).get('sha') != (was_e or {}).get('sha')})
            watched.set('PASS', ', '.join(w['path'] + (' изменён' if w['changed'] else '') for w in src['watched']))
        except SourceError as exc:
            for c in group:
                if c.status == 'NOT_RUN':
                    c.set('BLOCKED', exc.code)
        finally:
            log = self.save_log('source-git', '\n'.join(f"$ {' '.join(r.argv[3:])} -> {r.exit_code}" for r in repo.log))
            fetch.evidence.append(log)
        return src, inv_info

    # ------------------------------------------------------------------ target (Laravel)
    def target_stage(self, state: Dict[str, Any], checks: List[Check], target_ref: str,
                     skip: bool) -> Tuple[Dict[str, Any], Optional[Dict[str, Any]]]:
        tcfg = self.cfg['target']
        last = state.get('last_reported') or {}
        tgt: Dict[str, Any] = {'repository': tcfg['repository'], 'ref_requested': target_ref, 'head': None,
                               'base_ref': last.get('target_ref'), 'changed_files': [], 'tests': None}
        tref = Check('TGT-REF', 'Фиксация целевого коммита и чистый worktree', True, 'target')
        steps = [(s, Check(s['id'], s['title'], s.get('required', True), 'target', scope='clean worktree of pinned commit'))
                 for s in tcfg['steps']]
        checks += [tref] + [c for _, c in steps]
        if skip:
            tref.set('NOT_RUN', 'проверки целевого кода отключены параметром --no-target')
            for _, c in steps:
                c.set('NOT_RUN', 'проверки целевого кода отключены параметром --no-target')
            return tgt, None
        try:
            runner = TargetRunner(self.cfg, self.root, self.work)
            with timed(tref):
                head = runner.resolve(target_ref)
                tgt['head'] = head
                tgt.update(runner.describe(head))
                tgt['changed_files'] = runner.changed_files(tgt['base_ref'], head)
                path, wlog = runner.worktree(head)
                self.redactor.add(runner.secrets)
            tref.set('PASS', details={'head': head, 'worktree': path.relative_to(self.root).as_posix()})
            tref.evidence.append(self.save_log('target-worktree', '\n'.join(r.output() for r in wlog)))
        except TargetError as exc:
            tref.set('BLOCKED', str(exc))
            for _, c in steps:
                c.set('BLOCKED', 'целевой коммит не подготовлен')
            return tgt, None
        junit = None
        deps_ok = True
        timeout = self.cfg['limits']['target_step_timeout_s']
        for spec, c in steps:
            if not deps_ok:
                c.set('BLOCKED', 'зависимости не установлены (TGT-DEPS)')
                continue
            res = runner.run_step(path, spec['step'], timeout, junit=spec.get('junit'))
            c.command = f"scripts/check.sh {spec['step']}"
            c.duration_s, c.exit_code = res.duration_s, res.exit_code
            c.evidence.append(self.save_log(f"target-{spec['step']}", res.output()))
            if res.exit_code == 64:
                c.set('BLOCKED', 'шаг не поддерживается этой версией целевого кода')
            elif res.timed_out:
                c.set('BLOCKED', f'превышен таймаут {timeout} с')
            elif res.ok:
                c.set('PASS')
            else:
                c.set('FAIL', f'exit code {res.exit_code}')
            if spec.get('junit'):
                junit = parse_junit(path / spec['junit'])
                if junit:
                    tgt['tests'] = {k: junit[k] for k in ('tests', 'passed', 'failed', 'error', 'skipped')}
                    c.details['tests'] = tgt['tests']
                    failed = [x['id'] for x in junit['cases'] if x['status'] in ('failed', 'error')]
                    if failed:
                        c.details['failed_tests'] = failed
                elif c.status == 'PASS':
                    c.set('FAIL', 'тесты прошли, но JUnit-отчёт не создан — число тестов не подтверждено')
            if spec['step'] == 'deps' and c.status != 'PASS':
                deps_ok = False
        if (path / 'compose.yaml').is_file():
            runner.teardown(path)
        return tgt, junit

    # ------------------------------------------------------------------ harness self-checks
    def coverage_check(self, state: Dict[str, Any], checks: List[Check], junit: Optional[Dict[str, Any]]) -> Check:
        c = Check('HAR-COVERAGE', 'Набор проверок и тестов не сократился без review', True, 'harness')
        prev = state.get('last_reported') or {}
        if not prev:
            return c.set('PASS', 'первый отчёт: базовый набор зафиксирован')
        current = {x.id for x in checks if not x.planned} | {'HAR-COVERAGE', 'HAR-JOURNAL', 'HAR-SECRETS', 'AI-REVIEW'}
        removed = sorted(set(prev.get('check_ids') or []) - current)
        tests_now = {x['id'] for x in junit['cases']} if junit else None
        removed_tests = sorted(set(prev.get('tests') or []) - tests_now) if tests_now is not None else []
        if removed or removed_tests:
            return c.set('FAIL', 'исчезли проверки/тесты — требуется review', removed=removed + removed_tests)
        note = '' if tests_now is not None else '; список тестов не сравнивался (тесты не выполнялись)'
        return c.set('PASS', f'проверок {len(current)}, тестов {len(tests_now) if tests_now is not None else "—"}{note}')

    def journal_check(self, state: Dict[str, Any]) -> Check:
        c = Check('HAR-JOURNAL', 'DAILY-CHANGELOG сохраняет прежние записи', True, 'harness')
        info = state.get('changelog')
        if not info:
            return c.set('PASS', 'журнал создаётся или продолжается впервые')
        text = self.changelog.read_text(encoding='utf-8') if self.changelog.is_file() else ''
        entries = len(re.findall(r'^## ', text, flags=re.M))
        if entries < info.get('entries', 0) or info.get('last_header', '') not in text:
            return c.set('FAIL', f"из журнала пропали записи: было {info.get('entries')}, сейчас {entries}")
        return c.set('PASS', f'записей {entries}')

    # ------------------------------------------------------------------ main flow
    def _run(self, target_ref: str, skip_target: bool) -> int:
        t0 = now_utc()
        run_id = t0.strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(2)
        local = local_dt(t0, self.tz)
        local_date = local.strftime('%Y-%m-%d')
        self.log_dir = self.work / 'runs' / run_id / 'logs'
        self.log_dir.mkdir(parents=True, exist_ok=True)
        run_dir = self.log_dir.parent
        state = self.load_state()
        previous = state.get('last_attempt')
        unfinished = previous if previous and previous.get('status') == 'RUNNING' else None
        state['last_attempt'] = {'run_id': run_id, 'started_at': iso(t0), 'status': 'RUNNING', 'stage': 'start'}
        self.save_state(state)  # the attempt is recorded before any network action
        self.load_redactions()

        def stage(name: str) -> None:
            state['last_attempt']['stage'] = name
            self.save_state(state)

        checks: List[Check] = []
        stage('source')
        src, inv_info = self.source_stage(state, checks)
        stage('target')
        tgt, junit = self.target_stage(state, checks, target_ref, skip_target)
        stage('tasks')
        task_checks, task_status = tasks.sheets_status(self.cfg, self.work)
        checks += task_checks
        for p in self.cfg['target'].get('planned_checks', []):
            checks.append(Check(p['id'], p['title'], False, 'target', planned=True).set('NOT_RUN', 'запланирована, ещё не реализована'))
        stage('harness')
        checks.append(self.coverage_check(state, checks, junit))
        checks.append(self.journal_check(state))
        secrets_check = Check('HAR-SECRETS', 'Секреты и маркеры отсутствуют в MD, JSON и логах', True, 'harness')
        checks.append(secrets_check)
        leaks = [p.name for p in self.log_dir.glob('*.log') if self.redactor.leaks(p.read_text(encoding='utf-8', errors='replace'))]
        secrets_check.set('FAIL' if leaks else 'PASS', ('утечка в логах: ' + ', '.join(leaks)) if leaks else 'логи проверены')

        suite_ids = sorted(c.id for c in checks if not c.planned)
        suite_version = f"{SUITE_BASE}+{sha256_bytes(','.join(suite_ids).encode())[:6]}"
        config_hash = sha256_bytes(canonical_json({k: v for k, v in self.cfg.items() if k != 'sheets'}).encode())[:12]
        logical_key = sha256_bytes(canonical_json({'date': local_date, 'source': src['observed_head'], 'target': tgt['head'],
                                                   'suite': suite_version, 'config': config_hash,
                                                   'harness': harness_hash()}).encode())[:16]
        last = state.get('last_reported') or {}
        repeat_of = last.get('run_id') if last.get('logical_key') == logical_key else None

        stage('findings')
        registry = read_json(self.findings_path) or {'schema': 'promtech.audit-findings/1', 'findings': []}
        run_ref = {'run_id': run_id, 'target_ref': tgt['head'] or '', 'source_ref': src['observed_head'] or '', 'at': iso(t0)}
        fres = findings_mod.update(registry, findings_mod.candidates(checks, junit), checks, junit, run_ref)
        open_items = [f for f in registry['findings'] if f['status'] != 'CLOSED']
        touched = set(fres['new'] + fres['repeated'] + fres['closed'] + fres['needs_review'])
        fres_report = dict(fres, open_total=len(open_items),
                           items=[{k: f[k] for k in ('id', 'status', 'check_id', 'title', 'first_seen')}
                                  for f in registry['findings'] if f['id'] in touched or f['status'] != 'CLOSED'])

        stage('ai')
        payload = {'run_id': run_id, 'source': {k: src.get(k) for k in ('status', 'observed_head', 'base_ref', 'commits', 'diff', 'impact', 'diff_vs_accepted')},
                   'target': {k: tgt.get(k) for k in ('head', 'base_ref', 'changed_files', 'tests')},
                   'checks': [c.to_dict() for c in checks], 'open_findings': fres_report['items']}
        known = {c.id for c in checks} | {f['id'] for f in registry['findings']} | {
            i.get('function') or i.get('page') for i in src.get('impact', [])} | set(self.cfg.get('modules', {}).values())
        ai_check, ai_resp = ai_review.run_review(self.cfg, payload, known, self.redactor, run_dir, self.image)
        checks.append(ai_check)
        ai_report: Dict[str, Any] = {'status': ai_check.status, 'reason': ai_check.reason}
        if ai_resp and ai_resp.get('status') == 'OK':
            rv = ai_resp['review']
            items = [{'kind': it['kind'], 'text': it.get('description') or it.get('rationale', ''), 'key': key}
                     for key in ai_review.LIST_KEYS for it in rv.get(key, [])]
            ai_report.update(model=ai_resp.get('model'), summary=rv.get('summary', ''), items=items,
                             recommendations=rv.get('finding_recommendations', []))

        t1 = now_utc()
        missed = []
        if last.get('local_date'):
            day = dt.date.fromisoformat(last['local_date']) + dt.timedelta(days=1)
            while day < local.date():
                missed.append(day.isoformat())
                day += dt.timedelta(days=1)
        not_checked = [f"{c.id} — {c.title}: {c.reason}" for c in checks if c.status in ('BLOCKED', 'NOT_RUN')]
        not_checked += ['Модули ERP M01–M23 ещё не перенесены: бизнес-сценарии, права и инварианты DATA-01 не проверяются.',
                        'Характеризационные пробы legacy (35 исторических наблюдений) в ежедневный набор пока не включены.',
                        'Карта требований docs/coverage/requirements.csv не создана: изменения legacy не связаны с требованиями.']
        report: Dict[str, Any] = {
            'schema': 'promtech.audit-run/1', 'run_id': run_id, 'mode': self.mode, 'started_at': iso(t0),
            'finished_at': iso(t1), 'duration_s': (t1 - t0).total_seconds(), 'local_date': local_date,
            'local_time': local.strftime('%H:%M'), 'timezone': self.tz, 'logical_key': logical_key, 'repeat_of': repeat_of,
            'harness': {'version': HARNESS_VERSION, 'suite_version': suite_version, 'config_hash': config_hash,
                        'code_hash': harness_hash(), 'inventory_tool': inventory.tool_hash(), 'python': platform.python_version()},
            'source': dict(src, diff=src['diff']), 'target': tgt,
            'inventory': {'counts': inv_info.get('counts')} if inv_info else None,
            'checks': [c.to_dict() for c in checks], 'summary': counts(checks),
            'execution_status': execution_status(checks), 'product_status': product_status(checks),
            'source_status': src['status'], 'findings': fres_report, 'task_sync_status': task_status,
            'ai_review': ai_report, 'previous_attempt': unfinished, 'missed_days': missed,
            'not_checked': not_checked, 'exit_code': exit_code(checks, published=True),
            'publication': {'status': 'PUBLISHED'},
        }
        report['actions'] = publish.actions(report)
        report = self.redactor.redact_obj(report)
        problems = validate(report, read_json(HARNESS_DIR / 'schemas' / 'run-report.schema.json'))
        if problems:
            raise RuntimeError('report does not match schema: ' + '; '.join(problems[:5]))

        md_rel = f"daily/{local_date}/{run_id}.md"
        md = publish.render_md(report)
        if repeat_of:  # same inputs as the previous report: one more attempt line, not a second daily entry
            sm = report['summary']
            entry = (f"- Повтор `{run_id}` ({report['local_time']}): входные данные те же, что у `{repeat_of}`; "
                     f"PASS {sm['PASS']} / FAIL {sm['FAIL']} / BLOCKED {sm['BLOCKED']} / NOT_RUN {sm['NOT_RUN']}; "
                     f"exit {report['exit_code']}; [отчёт]({md_rel}).\n")
        else:
            entry = publish.render_changelog_entry(report, md_rel)
        json_text = __import__('json').dumps(report, ensure_ascii=False, indent=2) + '\n'
        leaked = [n for n, text in (('md', md), ('json', json_text), ('changelog', entry)) if self.redactor.leaks(text)]
        atomic_write(run_dir / 'report.md', self.redactor.redact(md))
        atomic_write(run_dir / 'report.json', self.redactor.redact(json_text))
        if leaked:
            state['last_attempt'].update(status='SECRET_LEAK_BLOCKED', stage='publish')
            self.save_state(state)
            print(f'HAR-SECRETS: обнаружен секрет в {leaked}; публикация остановлена, локальный отчёт: {run_dir}', file=sys.stderr)
            return 1

        stage('publish')
        self.hook('before_publish')
        md_path = self.reports / local_date / f'{run_id}.md'
        try:
            atomic_write(md_path, md)
            atomic_write(md_path.with_suffix('.json'), json_text)
            existing = self.changelog.read_text(encoding='utf-8') if self.changelog.is_file() else publish.CHANGELOG_HEADER
            separator = '\n' if repeat_of else '\n\n'
            atomic_write(self.changelog, existing.rstrip('\n') + separator + entry)
            if not self.changelog.read_text(encoding='utf-8').endswith(entry):
                raise OSError('changelog read-back mismatch')
            write_json(self.findings_path, registry)
        except OSError as exc:
            report['publication'] = {'status': 'FAILED', 'error': type(exc).__name__}
            report['exit_code'] = exit_code(checks, published=False)
            write_json(run_dir / 'report.json', self.redactor.redact_obj(report))
            state['last_attempt'].update(status='PUBLISH_FAILED', stage='publish')
            with contextlib.suppress(OSError):
                self.save_state(state)
            print(f'Публикация не выполнена ({type(exc).__name__}); локальный отчёт: {run_dir}', file=sys.stderr)
            return report['exit_code']

        text = self.changelog.read_text(encoding='utf-8')
        source_complete = all(c.status in ('PASS', 'FAIL') for c in checks if c.stage == 'source' and c.required)
        state['last_attempt'].update(status='COMPLETED', stage='done', finished_at=iso(t1), exit_code=report['exit_code'])
        if src['observed_head'] or tgt['head']:
            state['last_observed'] = {'source_ref': src['observed_head'], 'target_ref': tgt['head'], 'at': iso(t0)}
        if source_complete:
            state['last_reported'] = {
                'run_id': run_id, 'source_ref': src['observed_head'], 'source_blob': src['blob'], 'target_ref': tgt['head'],
                'logical_key': logical_key, 'local_date': local_date, 'completed_at': iso(t1), 'report': md_rel,
                'artifact_sha256': sha256_bytes(json_text.encode()),
                'check_ids': suite_ids, 'tests': sorted(x['id'] for x in junit['cases']) if junit else (last.get('tests') or []),
            }
        if report['exit_code'] == 0:
            state['last_verified'] = {'source_ref': src['observed_head'], 'target_ref': tgt['head'],
                                      'required_suite_version': suite_version, 'completed_at': iso(t1), 'run_id': run_id}
        header = entry.splitlines()[0] if entry.startswith('## ') else (state.get('changelog') or {}).get('last_header', '')
        state['changelog'] = {'entries': len(re.findall(r'^## ', text, flags=re.M)),
                              'last_header': header, 'sha256': sha256_bytes(text.encode())}
        self.save_state(state)
        print(f"{run_id}: выполнение {report['execution_status']}, продукт {report['product_status']}, "
              f"источник {report['source_status']}, PASS {report['summary']['PASS']} / FAIL {report['summary']['FAIL']} / "
              f"BLOCKED {report['summary']['BLOCKED']} / NOT_RUN {report['summary']['NOT_RUN']}; отчёт docs/{md_rel}; exit {report['exit_code']}")
        return report['exit_code']


def main(argv: Optional[List[str]] = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    daily = sub.add_parser('daily', help='full audit run')
    daily.add_argument('--target-ref', default='HEAD')
    daily.add_argument('--mode', choices=['daily', 'manual', 'selftest'], default=os.environ.get('AUDIT_MODE', 'manual'))
    daily.add_argument('--no-target', action='store_true')
    daily.add_argument('--config', type=Path)
    daily.add_argument('--root', type=Path, default=REPO_ROOT)
    status = sub.add_parser('status', help='print state pointers')
    status.add_argument('--root', type=Path, default=REPO_ROOT)
    args = ap.parse_args(argv)
    if args.cmd == 'status':
        cfg = load_config(local=False)
        print(Path(args.root / cfg['paths']['state']).read_text(encoding='utf-8') if (args.root / cfg['paths']['state']).is_file() else 'нет состояния')
        return 0
    cfg = load_config(args.config, local=args.config is None)
    return Audit(cfg, args.root.resolve(), args.mode).run(args.target_ref, args.no_target)


if __name__ == '__main__':
    sys.exit(main())
