"""Legacy source acquisition: pin the branch head once, fetch by SHA, read exact-path blobs.

The source repository is never checked out: case-colliding names (Index.html / index.html)
cannot overwrite each other, and fetched content is treated as data only.
"""
from __future__ import annotations

from pathlib import Path
from typing import Dict, List, Optional, Tuple

from hlib import CmdResult, git_blob_sha, run_cmd


class SourceError(Exception):
    def __init__(self, code: str, message: str, result: Optional[CmdResult] = None):
        super().__init__(f'{code}: {message}')
        self.code = code
        self.result = result


class SourceRepo:
    def __init__(self, url: str, git_dir: Path, timeout: int = 180):
        self.url = url
        self.git_dir = git_dir
        self.timeout = timeout
        self.log: List[CmdResult] = []

    def _git(self, *args: str, binary: bool = False, timeout: Optional[int] = None) -> CmdResult:
        env = {'GIT_TERMINAL_PROMPT': '0', 'GIT_ASKPASS': 'true', 'LC_ALL': 'C'}
        res = run_cmd(['git', '--git-dir', str(self.git_dir), *args], env=env,
                      timeout=timeout or self.timeout, binary=binary)
        self.log.append(res)
        return res

    def ensure(self) -> None:
        if not (self.git_dir / 'HEAD').is_file():
            self.git_dir.mkdir(parents=True, exist_ok=True)
            res = run_cmd(['git', 'init', '--bare', '-q', str(self.git_dir)])
            if not res.ok:
                raise SourceError('LOCAL_GIT_FAILED', res.stderr.strip(), res)
            self._git('config', 'gc.auto', '0')
        current = self._git('config', '--get', 'remote.origin.url')
        if not current.ok:
            self._git('remote', 'add', 'origin', self.url)
        elif current.stdout.strip() != self.url:
            self._git('remote', 'set-url', 'origin', self.url)

    def ls_remote(self, branch: str) -> str:
        res = self._git('ls-remote', '--exit-code', 'origin', f'refs/heads/{branch}')
        if not res.ok:
            raise SourceError('FETCH_FAILED', f'ls-remote {branch}: {res.stderr.strip() or res.exit_code}', res)
        line = res.stdout.strip().splitlines()[0] if res.stdout.strip() else ''
        sha = line.split('\t')[0]
        if len(sha) != 40:
            raise SourceError('FETCH_FAILED', f'unexpected ls-remote output for {branch}', res)
        return sha

    def has_commit(self, sha: str) -> bool:
        return self._git('cat-file', '-e', f'{sha}^{{commit}}').ok

    def fetch(self, branch: str, shas: List[str]) -> None:
        """Fetch the branch plus explicitly pinned commits; keep them alive with harness refs."""
        self._git('fetch', '--no-tags', '-q', 'origin', f'+refs/heads/{branch}:refs/remotes/origin/{branch}')
        missing = [s for s in shas if s and not self.has_commit(s)]
        if missing:
            res = self._git('fetch', '--no-tags', '-q', 'origin', *missing)
            still = [s for s in missing if not self.has_commit(s)]
            if still:
                raise SourceError('COMMIT_UNAVAILABLE', 'not fetched: ' + ', '.join(still), res)
        for s in shas:
            if s:
                self._git('update-ref', f'refs/harness/seen/{s}', s)

    def tree(self, sha: str) -> List[Dict[str, object]]:
        res = self._git('ls-tree', '-r', '-l', '-z', '--full-tree', sha, binary=True)
        if not res.ok:
            raise SourceError('TREE_FAILED', res.stderr.strip(), res)
        entries = []
        for item in res.stdout.split(b'\0'):
            if not item:
                continue
            meta, path = item.split(b'\t', 1)
            mode, kind, obj, size = meta.decode().split()
            entries.append({'path': path.decode('utf-8', 'replace'), 'mode': mode, 'type': kind, 'sha': obj,
                            'size': int(size) if size != '-' else None})
        return entries

    def read_blob(self, blob_sha: str, expected_size: Optional[int] = None) -> bytes:
        res = self._git('cat-file', 'blob', blob_sha, binary=True)
        if not res.ok:
            raise SourceError('BLOB_UNAVAILABLE', f'{blob_sha}: {res.stderr.strip()}', res)
        data = res.stdout
        if git_blob_sha(data) != blob_sha:
            raise SourceError('BLOB_MISMATCH', f'content of {blob_sha} does not hash to its id')
        if expected_size is not None and len(data) != expected_size:
            raise SourceError('BLOB_INCOMPLETE', f'{blob_sha}: {len(data)} bytes, tree says {expected_size}')
        return data

    def is_ancestor(self, older: str, newer: str) -> Optional[bool]:
        res = self._git('merge-base', '--is-ancestor', older, newer)
        if res.exit_code == 0:
            return True
        if res.exit_code == 1:
            return False
        return None

    def commits(self, base: Optional[str], head: str, limit: int = 500) -> List[Dict[str, str]]:
        rng = f'{base}..{head}' if base else head
        res = self._git('log', '--reverse', f'--max-count={limit}', '--format=%H%x1f%aI%x1f%cI%x1f%s%x1e', rng)
        if not res.ok:
            raise SourceError('LOG_FAILED', res.stderr.strip(), res)
        out = []
        for rec in res.stdout.split('\x1e'):
            rec = rec.strip()
            if rec:
                sha, authored, committed, subject = rec.split('\x1f', 3)
                out.append({'sha': sha, 'authored_at': authored, 'committed_at': committed, 'subject': subject[:200]})
        return out

    def commit_count(self, base: Optional[str], head: str) -> int:
        res = self._git('rev-list', '--count', f'{base}..{head}' if base else head)
        return int(res.stdout.strip()) if res.ok and res.stdout.strip().isdigit() else -1

    def changed_paths(self, base: str, head: str) -> List[Tuple[str, str]]:
        res = self._git('diff', '--name-status', '-z', '--no-renames', base, head, binary=True)
        if not res.ok:
            raise SourceError('DIFF_FAILED', res.stderr.strip(), res)
        parts = [p.decode('utf-8', 'replace') for p in res.stdout.split(b'\0') if p]
        return list(zip(parts[0::2], parts[1::2]))

    def numstat(self, old_blob: str, new_blob: str) -> Tuple[int, int]:
        res = self._git('diff', '--numstat', old_blob, new_blob)
        if not res.ok or not res.stdout.strip():
            return (0, 0)
        added, removed = res.stdout.split()[:2]
        return (int(added) if added.isdigit() else 0, int(removed) if removed.isdigit() else 0)


def case_collisions(tree: List[Dict[str, object]]) -> List[List[str]]:
    groups: Dict[str, List[str]] = {}
    for e in tree:
        groups.setdefault(str(e['path']).lower(), []).append(str(e['path']))
    return sorted(sorted(g) for g in groups.values() if len(g) > 1)


def exact_entry(tree: List[Dict[str, object]], path: str) -> Optional[Dict[str, object]]:
    """Case-sensitive exact match only — never a 'similar' file name."""
    for e in tree:
        if e['path'] == path and e['type'] == 'blob':
            return e
    return None
