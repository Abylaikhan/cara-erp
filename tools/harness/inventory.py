"""Builds the legacy inventory in an isolated Node container (no network, read-only, no Docker socket)."""
from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any, Dict, List, Tuple

from hlib import HARNESS_DIR, CmdResult, atomic_write, read_json, run_cmd, sha256_bytes, write_json

NODE_DIR = HARNESS_DIR / 'node'


def tool_hash() -> str:
    parts = [(NODE_DIR / name).read_bytes() for name in ('legacy_inventory.mjs', 'package-lock.json')]
    return sha256_bytes(b'\0'.join(parts))[:16]


def _user() -> str:
    return f'{os.getuid()}:{os.getgid()}'


def ensure_node_deps(image: str, timeout: int = 300) -> CmdResult:
    """npm ci from the lock file (network allowed, source not mounted). Skipped when the stamp matches."""
    stamp = NODE_DIR / 'node_modules' / '.harness-lock-sha256'
    lock = sha256_bytes((NODE_DIR / 'package-lock.json').read_bytes())
    if stamp.is_file() and stamp.read_text().strip() == lock:
        return CmdResult(['npm', 'ci', '(cached)'], 0, 0.0, 'node_modules matches package-lock.json')
    res = run_cmd(['docker', 'run', '--rm', '--user', _user(), '-e', 'HOME=/tmp', '-e', 'npm_config_cache=/tmp/npm-cache',
                   '-v', f'{NODE_DIR}:/tool', '-w', '/tool', image, 'npm', 'ci', '--no-audit', '--no-fund'], timeout=timeout)
    if res.ok:
        atomic_write(stamp, lock + '\n')
    return res


def build_inventory(blob_file: Path, cache_dir: Path, image: str, timeout: int, memory: str) -> Tuple[Dict[str, Any], List[str], CmdResult]:
    """Returns (inventory, redaction values, command result). Cached by blob SHA-256 and tool hash."""
    key = f'{sha256_bytes(blob_file.read_bytes())[:24]}-{tool_hash()}'
    cached = read_json(cache_dir / f'{key}.json')
    secrets = read_json(cache_dir / f'{key}.secrets.json', [])
    if cached is not None:
        return cached, secrets, CmdResult(['inventory', '(cached)', key], 0, 0.0)
    argv = ['docker', 'run', '--rm', '--network', 'none', '--read-only', '--memory', memory, '--cpus', '2',
            '--pids-limit', '128', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--user', _user(),
            '--tmpfs', '/tmp:size=16m', '-v', f'{NODE_DIR}:/tool:ro', '-v', f'{blob_file.parent}:/src:ro',
            image, 'node', '/tool/legacy_inventory.mjs', f'/src/{blob_file.name}']
    res = run_cmd(argv, timeout=timeout)
    if not res.ok:
        return {}, [], res
    payload = json.loads(res.stdout)
    inventory, secrets = payload['inventory'], payload.get('redaction_values', [])
    res.stdout = f'inventory {key}: ' + json.dumps(inventory['counts'], ensure_ascii=False)
    write_json(cache_dir / f'{key}.json', inventory)
    write_json(cache_dir / f'{key}.secrets.json', secrets, mode=0o600)
    return inventory, secrets, res
