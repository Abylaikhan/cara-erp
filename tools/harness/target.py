"""Target (Laravel) checks on a clean worktree of the pinned commit, in a separate Compose project.

The developer's working tree and dev database are never used: the worktree gets its own .env with
generated secrets, random loopback ports and its own volumes (project name from config).
"""
from __future__ import annotations

import base64
import os
import re
import secrets
import shutil
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from hlib import CmdResult, atomic_write, run_cmd

STEP_UNSUPPORTED = 64


class TargetError(Exception):
    pass


class TargetRunner:
    def __init__(self, cfg: Dict[str, Any], repo_root: Path, work: Path):
        self.cfg = cfg
        self.repo = repo_root
        self.work = work
        self.project = cfg['target']['compose_project']
        self.image = cfg['target']['image']
        if 'audit' not in self.project or self.project == cfg['target'].get('dev_compose_project'):
            raise TargetError('compose project for audits must contain "audit" and differ from the dev project')
        self.secrets: List[str] = []

    def git(self, *args: str, cwd: Optional[Path] = None) -> CmdResult:
        return run_cmd(['git', '-C', str(cwd or self.repo), *args], env={'LC_ALL': 'C'}, timeout=120)

    def resolve(self, ref: str) -> str:
        res = self.git('rev-parse', '--verify', '--quiet', f'{ref}^{{commit}}')
        if not res.ok:
            raise TargetError(f'target ref {ref!r} not found')
        return res.stdout.strip()

    def describe(self, sha: str) -> Dict[str, Any]:
        info = self.git('show', '-s', '--format=%cI%x1f%s', sha).stdout.strip().split('\x1f', 1)
        pushed = self.git('branch', '-r', '--contains', sha)
        dirty = self.git('status', '--porcelain', '--untracked-files=no')
        return {
            'committed_at': info[0] if info else None,
            'subject': info[1][:200] if len(info) > 1 else '',
            'on_remote_branches': [b.strip() for b in pushed.stdout.splitlines() if b.strip()] if pushed.ok else [],
            'working_tree_dirty': bool(dirty.stdout.strip()),
        }

    def changed_files(self, base: Optional[str], head: str) -> List[str]:
        if not base or base == head:
            return []
        res = self.git('diff', '--name-only', base, head)
        return res.stdout.split() if res.ok else []

    def worktree(self, sha: str) -> Tuple[Path, List[CmdResult]]:
        root = self.work / 'target'
        path = root / sha[:12]
        log: List[CmdResult] = []
        if path.exists():
            head = self.git('rev-parse', 'HEAD', cwd=path)
            clean = self.git('status', '--porcelain', '--untracked-files=no', cwd=path)
            log += [head, clean]
            if head.stdout.strip() != sha or clean.stdout.strip():
                log.append(self.git('worktree', 'remove', '--force', str(path)))
                shutil.rmtree(path, ignore_errors=True)
        if not path.exists():
            root.mkdir(parents=True, exist_ok=True)
            self.git('worktree', 'prune')
            res = self.git('worktree', 'add', '--detach', '--force', str(path), sha)
            log.append(res)
            if not res.ok:
                raise TargetError('worktree add failed: ' + res.stderr.strip())
        # keep only the two most recent audit worktrees
        others = sorted((p for p in root.iterdir() if p.is_dir() and p != path), key=lambda p: p.stat().st_mtime)
        for old in others[:-1]:
            log.append(self.git('worktree', 'remove', '--force', str(old)))
            shutil.rmtree(old, ignore_errors=True)
        self.write_env(path)
        return path, log

    def write_env(self, path: Path) -> None:
        """Fresh .env per audit: generated secrets, APP_KEY, ephemeral ports. Values are added to the redactor."""
        example = (path / '.env.example').read_text(encoding='utf-8')
        lines, generated = [], []
        for line in example.splitlines():
            if '__GENERATE__' in line:
                value = secrets.token_hex(24)
                generated.append(value)
                line = line.replace('__GENERATE__', value)
            lines.append(line)
        key = base64.b64encode(secrets.token_bytes(32)).decode()
        generated += [key, 'base64:' + key]
        overrides = {'APP_KEY': 'base64:' + key, 'APP_ENV': 'local', 'APP_PORT': '0', 'VITE_PORT': '0',
                     'DB_FORWARD_PORT': '0', 'APP_IMAGE': self.image}
        text = '\n'.join(lines) + '\n'
        for k, v in overrides.items():
            if re.search(rf'^{k}=.*$', text, flags=re.M):
                text = re.sub(rf'^{k}=.*$', f'{k}={v}', text, flags=re.M)
            else:
                text += f'{k}={v}\n'
        atomic_write(path / '.env', text, mode=0o600)
        self.secrets += generated

    def env(self) -> Dict[str, str]:
        return {'COMPOSE_PROJECT_NAME': self.project, 'HOST_UID': str(os.getuid()), 'HOST_GID': str(os.getgid()),
                'APP_IMAGE': self.image, 'COMPOSE_PROGRESS': 'plain', 'NO_COLOR': '1'}

    def run_step(self, path: Path, step: str, timeout: int, junit: Optional[str] = None) -> CmdResult:
        env = self.env()
        if junit:
            env['JUNIT'] = junit
            (path / junit).unlink(missing_ok=True)
        return run_cmd(['bash', 'scripts/check.sh', step], cwd=path, env=env, timeout=timeout)

    def reset_databases(self, path: Path) -> List[CmdResult]:
        """Fresh PostgreSQL for every audit: drop only this audit project's database volumes (never dev)."""
        log = [self.teardown(path)]
        for name in self.cfg['target'].get('ephemeral_volumes', []):
            log.append(run_cmd(['docker', 'volume', 'rm', '-f', f'{self.project}_{name}'], timeout=120))
        return log

    def teardown(self, path: Path) -> CmdResult:
        return run_cmd(['docker', 'compose', 'down', '--remove-orphans'], cwd=path, env=self.env(), timeout=300)


def parse_junit(path: Path) -> Optional[Dict[str, Any]]:
    if not path.is_file():
        return None
    root = ET.parse(path).getroot()
    cases = []
    for case in root.iter('testcase'):
        status = 'passed'
        if case.find('failure') is not None:
            status = 'failed'
        elif case.find('error') is not None:
            status = 'error'
        elif case.find('skipped') is not None:
            status = 'skipped'
        cls = case.get('class') or case.get('classname') or ''
        cases.append({'id': f"{cls}::{case.get('name')}", 'status': status})
    total = {s: sum(1 for c in cases if c['status'] == s) for s in ('passed', 'failed', 'error', 'skipped')}
    return {'tests': len(cases), **total, 'cases': sorted(cases, key=lambda c: c['id'])}
