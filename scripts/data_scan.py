#!/usr/bin/env python3
"""make data-scan — before a commit: do the changed files carry values of the real legacy data?

The repository is public, the dev database holds the copy of the real legacy data (schema `legacy`). Every distinct value
of that copy (5…300 characters) is looked for in the files changed against HEAD (and the untracked ones). Values that are
UI texts of the accepted legacy reference, bare identifiers and short numbers are skipped. Hexadecimal runs of 32 characters
or more (SHA hashes of commits, artifacts and audit runs, new on every run) are blanked before the search: digits inside a hash
are not data. A value counts as new when the file had no such value at HEAD: the exit code is 1 if there is any.

Nothing of the data is printed: a hit shows the table and column it comes from, its first two characters, its length
and its SHA-256. A hit that is reviewed and harmless (a word of the code or a probe that happens to be a value of the data
too) goes into scripts/data_scan.allow as that SHA-256 with the reason — the value itself is never written.
Usage: python3 scripts/data_scan.py [--all-files]
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HASH = re.compile(r'\b[0-9a-f]{32,}\b')
QUERY = ("select distinct r.table_name, e.key, e.value from legacy.rows r, jsonb_each_text(r.data) e "
         "where e.value is not null and length(e.value) between 5 and 300")


def git(*args: str) -> str:
    return subprocess.run(['git', *args], cwd=ROOT, capture_output=True, text=True, check=True).stdout


def accepted_reference() -> str:
    cfg = json.loads((ROOT / 'tools/harness/config.json').read_text(encoding='utf-8'))
    sha = cfg['source']['accepted_ref']['sha256']
    for blob in sorted((ROOT / '.local-audit/blobs').glob('*.html')):
        if hashlib.sha256(blob.read_bytes()).hexdigest() == sha:
            return blob.read_text(encoding='utf-8', errors='ignore')
    sys.exit('the accepted legacy reference is not in .local-audit/blobs: run make audit-daily first')


def legacy_values(reference: str) -> dict:
    sh = 'psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -F "$(printf "\\037")" -R "$(printf "\\036")" -c "$0"'
    out = subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'sh', '-c', sh, QUERY], cwd=ROOT, capture_output=True,
                         text=True, check=True).stdout
    values = {}
    for line in out.split('\x1e'):
        if line.count('\x1f') < 2:
            continue
        table, key, value = line.split('\x1f', 2)
        value = value.strip()
        if len(value) < 5 or re.fullmatch(r'[a-z_]+', value) or re.fullmatch(r'\d{1,5}(\.\d+)?', value) or value in reference:
            continue
        values.setdefault(value, f'{table}.{key}')
    return values


def allowed() -> set:
    path = ROOT / 'scripts/data_scan.allow'
    lines = path.read_text(encoding='utf-8').splitlines() if path.is_file() else []
    return {line.split()[0] for line in lines if line.strip() and not line.startswith('#')}


def changed_files(all_files: bool) -> list:
    if all_files:
        return [f for f in git('ls-files').split('\n') if f]
    files = set(git('diff', '--name-only', 'HEAD').split()) | set(git('ls-files', '--others', '--exclude-standard').split())
    return sorted(files)


def main() -> int:
    ap = argparse.ArgumentParser(description='Values of the real legacy data in changed files (masked output).')
    ap.add_argument('--all-files', action='store_true', help='every tracked file, not only the changed ones')
    args = ap.parse_args()
    allow = allowed()
    values = {v: s for v, s in legacy_values(accepted_reference()).items() if hashlib.sha256(v.encode()).hexdigest() not in allow}
    files = changed_files(args.all_files)
    new, old = [], 0
    for rel in files:
        path = ROOT / rel
        if not path.is_file() or path.stat().st_size > 2_000_000:
            continue
        text = HASH.sub(' ', path.read_text(encoding='utf-8', errors='ignore'))
        try:
            before = HASH.sub(' ', git('show', f'HEAD:{rel}'))
        except subprocess.CalledProcessError:
            before = ''
        for value, source in values.items():
            if value in text:
                if value in before:
                    old += 1
                else:
                    new.append((rel, source, f'{value[:2]}…({len(value)})', hashlib.sha256(value.encode()).hexdigest()))
    print(f'files {len(files)}, legacy values {len(values)} ({len(allow)} allowed): new hits {len(new)}, already there before the change {old}')
    for hit in new[:50]:
        print('  NEW', *hit)
    return 1 if new else 0


if __name__ == '__main__':
    sys.exit(main())
