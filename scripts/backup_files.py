#!/usr/bin/env python3
"""Uploaded files of the application for make db-backup / db-restore-check (ERP-074). Standard library only.

  state   ROOT                 one line per file (path, size, mtime) — compared before and after the copy
  archive ROOT TAR LIST        tar of every file under ROOT (sorted, owner and mode normalised), LIST = path, size, SHA-256
  verify  TAR LIST DEST        extract TAR into the empty directory DEST refusing links and paths outside it; every file must
                               match LIST and LIST must have no file the archive lacks
  links   LIST REFS            REFS = «storage_path<TAB>size» of project_files: each must be «private/<storage_path>» in LIST
                               with that size; files under private/projects/ nobody refers to are orphans

ROOT is storage/app (disk «local» = private/, disk «public» = public/); .gitignore files are not data. Paths are printed only
into the JSON on standard output, which the scripts keep in the owner-only backup directory: file names may be personal.
"""
from __future__ import annotations

import hashlib
import json
import os
import posixpath
import sys
import tarfile

SKIP = {'.gitignore'}


def walk(root: str):
    """(relative path, absolute path) of every regular file, sorted; links are refused, not followed."""
    out, links = [], []
    for base, dirs, files in os.walk(root):
        dirs.sort()
        for name in sorted(files):
            full = os.path.join(base, name)
            rel = os.path.relpath(full, root).replace(os.sep, '/')
            if name in SKIP:
                continue
            if os.path.islink(full) or not os.path.isfile(full):
                links.append(rel)
                continue
            out.append((rel, full))
        for d in list(dirs):
            if os.path.islink(os.path.join(base, d)):
                links.append(os.path.relpath(os.path.join(base, d), root).replace(os.sep, '/'))
                dirs.remove(d)
    return out, links


def sha256(path: str) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def state(root: str) -> int:
    if os.path.isdir(root):
        for rel, full in walk(root)[0]:
            st = os.stat(full)
            print(f'{rel}\t{st.st_size}\t{st.st_mtime_ns}')
    return 0


def archive(root: str, tar_path: str, list_path: str) -> int:
    files, links = walk(root) if os.path.isdir(root) else ([], [])
    if links:
        print(json.dumps({'error': f'{len(links)} links or special files in the storage: not copied'}))
        return 1
    rows, total = [], 0
    with tarfile.open(tar_path, 'w', format=tarfile.PAX_FORMAT) as tar:
        dirs = set()
        for rel, full in files:
            parent = posixpath.dirname(rel)
            missing = []
            while parent and parent not in dirs:
                missing.append(parent)
                parent = posixpath.dirname(parent)
            for d in reversed(missing):
                info = tarfile.TarInfo(d)
                info.type, info.mode, info.mtime = tarfile.DIRTYPE, 0o700, int(os.stat(os.path.join(root, d)).st_mtime)
                tar.addfile(info)
                dirs.add(d)
            st = os.stat(full)
            info = tarfile.TarInfo(rel)
            info.size, info.mode, info.mtime = st.st_size, 0o600, int(st.st_mtime)
            digest = hashlib.sha256()
            with open(full, 'rb') as f:
                tar.addfile(info, _Tee(f, digest))
            rows.append(f'{rel}\t{st.st_size}\t{digest.hexdigest()}')
            total += st.st_size
    with open(list_path, 'w', encoding='utf-8') as f:
        f.write(''.join(r + '\n' for r in rows))
    print(json.dumps({'count': len(rows), 'bytes': total}))
    return 0


class _Tee:
    """File reader that hashes what tarfile reads, so each file is read once."""

    def __init__(self, f, digest):
        self.f, self.digest = f, digest

    def read(self, size=-1):
        data = self.f.read(size)
        self.digest.update(data)
        return data


def read_list(list_path: str) -> dict[str, tuple[int, str]]:
    out = {}
    with open(list_path, encoding='utf-8') as f:
        for line in f.read().splitlines():
            if line:
                path, size, digest = line.split('\t')
                out[path] = (int(size), digest)
    return out


def verify(tar_path: str, list_path: str, dest: str) -> int:
    expected = read_list(list_path)
    problems, seen, total = [], set(), 0
    if os.listdir(dest):
        print(json.dumps({'status': 'FAIL', 'problems': ['the destination directory is not empty']}))
        return 1
    with tarfile.open(tar_path, 'r:') as tar:
        for m in tar:
            name = m.name
            norm = posixpath.normpath(name)
            if name.startswith('/') or norm == '..' or norm.startswith('../') or '\\' in name or norm != name.rstrip('/'):
                problems.append(f'unsafe path in the archive: {name!r}')
                continue
            target = os.path.join(dest, *norm.split('/'))
            if not (m.isdir() or m.isreg()):
                problems.append(f'not a regular file in the archive: {name}')
                continue
            try:
                if m.isdir():
                    os.makedirs(target, mode=0o700, exist_ok=True)
                    continue
                os.makedirs(os.path.dirname(target), mode=0o700, exist_ok=True)
                digest, size = hashlib.sha256(), 0
                src = tar.extractfile(m)
                with open(target, 'xb') as out:  # never over another member
                    for chunk in iter(lambda: src.read(1 << 20), b''):
                        digest.update(chunk)
                        out.write(chunk)
                        size += len(chunk)
                os.chmod(target, 0o600)
                os.utime(target, (m.mtime, m.mtime))
            except OSError as e:
                problems.append(f'cannot restore {name}: {e.strerror}')
                continue
            seen.add(norm)
            total += size
            want = expected.get(norm)
            if want is None:
                problems.append(f'file not in the list: {norm}')
            elif want != (size, digest.hexdigest()):
                problems.append(f'file differs from the list: {norm}')
    problems += [f'file missing from the archive: {p}' for p in sorted(set(expected) - seen)]
    print(json.dumps({'status': 'FAIL' if problems else 'PASS', 'files': len(seen), 'bytes': total, 'problems': problems}, ensure_ascii=False))
    return 1 if problems else 0


def links(list_path: str, refs_path: str) -> int:
    files = read_list(list_path)
    refs = []
    with open(refs_path, encoding='utf-8') as f:
        for line in f.read().splitlines():
            if line:
                path, size = line.split('\t')
                refs.append((path, int(size)))
    referenced = {'private/' + p for p, _ in refs}
    missing = sorted(p for p, _ in refs if 'private/' + p not in files)
    size_differs = sorted(p for p, s in refs if 'private/' + p in files and files['private/' + p][0] != s)
    orphans = sorted(p for p in files if p.startswith('private/projects/') and p not in referenced)
    print(json.dumps({'referenced': len(refs), 'missing': missing, 'size_differs': size_differs, 'orphans': len(orphans)}, ensure_ascii=False))
    return 0


def main(argv: list[str]) -> int:
    commands = {'state': (state, 1), 'archive': (archive, 3), 'verify': (verify, 3), 'links': (links, 2)}
    if len(argv) < 2 or argv[1] not in commands or len(argv) - 2 != commands[argv[1]][1]:
        print(__doc__, file=sys.stderr)
        return 64
    return commands[argv[1]][0](*argv[2:])


if __name__ == '__main__':
    sys.exit(main(sys.argv))
