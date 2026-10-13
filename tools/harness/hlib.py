"""Shared helpers of the PromTech audit harness. Standard library only, Python >= 3.9."""
from __future__ import annotations

import datetime as dt
import hashlib
import json
import os
import re
import subprocess
import tempfile
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Sequence, Union

try:
    from zoneinfo import ZoneInfo
except ImportError:  # pragma: no cover - Python < 3.9 is not supported
    raise SystemExit('Python >= 3.9 is required')

HARNESS_DIR = Path(__file__).resolve().parent
REPO_ROOT = HARNESS_DIR.parent.parent
HARNESS_VERSION = '0.1.0'
REDACTED = '[REDACTED]'


def deep_merge(base: Dict[str, Any], extra: Dict[str, Any]) -> Dict[str, Any]:
    out = dict(base)
    for key, value in extra.items():
        if isinstance(value, dict) and isinstance(out.get(key), dict):
            out[key] = deep_merge(out[key], value)
        else:
            out[key] = value
    return out


def load_config(path: Optional[Path] = None, local: bool = True) -> Dict[str, Any]:
    """Versioned config from tools/harness/config.json plus an optional local overlay outside Git."""
    cfg = json.loads((path or HARNESS_DIR / 'config.json').read_text(encoding='utf-8'))
    overlay = Path(os.path.expanduser(cfg.get('sheets', {}).get('local_config', ''))) if local else None
    if overlay and overlay.is_file():
        cfg = deep_merge(cfg, json.loads(overlay.read_text(encoding='utf-8')))
    return cfg


def canonical_json(obj: Any) -> str:
    return json.dumps(obj, ensure_ascii=False, sort_keys=True, separators=(',', ':'))


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def git_blob_sha(data: bytes) -> str:
    return hashlib.sha1(b'blob %d\0' % len(data) + data).hexdigest()


def now_utc() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0)


def iso(moment: dt.datetime) -> str:
    return moment.astimezone(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def parse_iso(value: str) -> dt.datetime:
    return dt.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=dt.timezone.utc)


def local_dt(moment: dt.datetime, tz: str) -> dt.datetime:
    return moment.astimezone(ZoneInfo(tz))


def atomic_write(path: Path, data: Union[str, bytes], mode: Optional[int] = None) -> None:
    """Write via a temporary file in the same directory, fsync, then rename over the target."""
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = data.encode('utf-8') if isinstance(data, str) else data
    fd, tmp = tempfile.mkstemp(prefix='.tmp-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as fh:
            fh.write(payload)
            fh.flush()
            os.fsync(fh.fileno())
        if mode is not None:
            os.chmod(tmp, mode)
        os.replace(tmp, path)
    except BaseException:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise


def write_json(path: Path, obj: Any, mode: Optional[int] = None) -> None:
    atomic_write(path, json.dumps(obj, ensure_ascii=False, indent=2, sort_keys=False) + '\n', mode)


def read_json(path: Path, default: Any = None) -> Any:
    if not path.is_file():
        return default
    return json.loads(path.read_text(encoding='utf-8'))


@dataclass
class CmdResult:
    argv: List[str]
    exit_code: int
    duration_s: float
    stdout: str = ''
    stderr: str = ''
    timed_out: bool = False

    @property
    def ok(self) -> bool:
        return self.exit_code == 0 and not self.timed_out

    def output(self) -> str:
        return self.stdout + (('\n' + self.stderr) if self.stderr else '')


def run_cmd(argv: Sequence[str], cwd: Optional[Path] = None, env: Optional[Dict[str, str]] = None,
            timeout: Optional[float] = None, input_data: Optional[bytes] = None, binary: bool = False) -> CmdResult:
    """Run a command without a shell; never raises for non-zero exit codes or timeouts."""
    started = time.monotonic()
    full_env = dict(os.environ)
    if env:
        full_env.update(env)
    try:
        proc = subprocess.run(list(argv), cwd=str(cwd) if cwd else None, env=full_env, input=input_data,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout)
        out = proc.stdout if binary else proc.stdout.decode('utf-8', 'replace')
        return CmdResult(list(argv), proc.returncode, round(time.monotonic() - started, 2), out,
                         proc.stderr.decode('utf-8', 'replace'))
    except subprocess.TimeoutExpired as exc:
        out = exc.stdout or b''
        return CmdResult(list(argv), 124, round(time.monotonic() - started, 2),
                         out if binary else out.decode('utf-8', 'replace'),
                         (exc.stderr or b'').decode('utf-8', 'replace') + f'\n[harness] timeout after {timeout}s', True)
    except FileNotFoundError as exc:
        return CmdResult(list(argv), 127, round(time.monotonic() - started, 2), b'' if binary else '', str(exc))


GENERIC_SECRET_PATTERNS = [
    re.compile(r'eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}'),
    re.compile(r'AIza[0-9A-Za-z_-]{30,}'),
    re.compile(r'\bsk-(?:ant-)?[A-Za-z0-9_-]{20,}'),
    re.compile(r'\bgh[pousr]_[A-Za-z0-9]{30,}'),
    re.compile(r'\bsb_(?:publishable|secret)_[A-Za-z0-9_-]{10,}'),
    re.compile(r'-----BEGIN [A-Z ]*PRIVATE KEY-----'),
    re.compile(r'base64:[A-Za-z0-9+/]{40,}={0,2}'),
]


class Redactor:
    """Replaces known secret values and generic secret patterns before anything is written."""

    def __init__(self, values: Iterable[str] = ()):
        self.values: set = set()
        self._compiled: Optional[re.Pattern] = None
        self.add(values)

    def add(self, values: Iterable[str]) -> None:
        for v in values:
            if isinstance(v, str) and len(v) >= 4:
                self.values.add(v)
        self._compiled = None

    def _pattern(self) -> Optional[re.Pattern]:
        if self._compiled is None and self.values:
            alternatives = sorted(self.values, key=len, reverse=True)
            self._compiled = re.compile('|'.join(re.escape(v) for v in alternatives))
        return self._compiled

    def redact(self, text: str) -> str:
        pattern = self._pattern()
        if pattern is not None:
            text = pattern.sub(REDACTED, text)
        for generic in GENERIC_SECRET_PATTERNS:
            text = generic.sub(REDACTED, text)
        return text

    def redact_obj(self, obj: Any) -> Any:
        if isinstance(obj, str):
            return self.redact(obj)
        if isinstance(obj, list):
            return [self.redact_obj(x) for x in obj]
        if isinstance(obj, dict):
            return {k: self.redact_obj(v) for k, v in obj.items()}
        return obj

    def leaks(self, text: str) -> List[str]:
        """Describe (without revealing) secrets still present in text."""
        found = []
        pattern = self._pattern()
        if pattern is not None and pattern.search(text):
            found.append('known-secret-value')
        for generic in GENERIC_SECRET_PATTERNS:
            if generic.search(text):
                found.append('pattern:' + generic.pattern[:12])
        return found


def env_file_secrets(path: Path) -> List[str]:
    """Values of secret-looking keys from a dotenv file (passwords, keys, tokens)."""
    values = []
    if not path.is_file():
        return values
    for line in path.read_text(encoding='utf-8', errors='replace').splitlines():
        m = re.match(r'^\s*([A-Z0-9_]+)\s*=\s*"?([^"\n]*)"?\s*$', line)
        if m and re.search(r'PASSWORD|SECRET|KEY|TOKEN', m.group(1)) and m.group(2) not in ('', 'null'):
            values.append(m.group(2))
            if m.group(2).startswith('base64:'):
                values.append(m.group(2)[7:])
    return values


def home_path(value: str) -> Path:
    return Path(os.path.expanduser(value))
