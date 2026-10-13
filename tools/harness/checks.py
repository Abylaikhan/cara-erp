"""Check result model, status aggregation and exit codes (see ТЗ §8)."""
from __future__ import annotations

from dataclasses import asdict, dataclass, field
from typing import Any, Dict, List, Optional

STATUSES = ('PASS', 'FAIL', 'BLOCKED', 'NOT_RUN', 'NOT_APPLICABLE')
EXIT_OK, EXIT_FAIL, EXIT_INCOMPLETE, EXIT_HARNESS_ERROR, EXIT_ALREADY_RUNNING = 0, 1, 2, 3, 75


@dataclass
class Check:
    id: str
    title: str
    required: bool
    stage: str
    status: str = 'NOT_RUN'
    duration_s: float = 0.0
    command: str = ''
    exit_code: Optional[int] = None
    evidence: List[str] = field(default_factory=list)
    scope: str = ''
    reason: str = ''
    planned: bool = False
    details: Dict[str, Any] = field(default_factory=dict)

    def set(self, status: str, reason: str = '', **details: Any) -> 'Check':
        assert status in STATUSES, status
        self.status = status
        if reason:
            self.reason = reason
        self.details.update(details)
        return self

    def to_dict(self) -> Dict[str, Any]:
        d = asdict(self)
        if not d['details']:
            del d['details']
        return d


def counts(checks: List[Check]) -> Dict[str, int]:
    return {s: sum(1 for c in checks if c.status == s) for s in STATUSES}


def execution_status(checks: List[Check]) -> str:
    """COMPLETE: everything in scope ran; PARTIAL: something in scope did not; BLOCKED: no required check ran."""
    in_scope = [c for c in checks if not c.planned]
    required = [c for c in in_scope if c.required]
    if required and all(c.status in ('BLOCKED', 'NOT_RUN') for c in required):
        return 'BLOCKED'
    if any(c.status in ('BLOCKED', 'NOT_RUN') for c in in_scope):
        return 'PARTIAL'
    return 'COMPLETE'


def product_status(checks: List[Check]) -> str:
    """State of the target application in the checked scope (legacy problems are reported separately)."""
    target = [c for c in checks if c.stage == 'target' and c.required and not c.planned]
    if any(c.status == 'FAIL' for c in target):
        return 'FAIL'
    if not target or any(c.status != 'PASS' for c in target):
        return 'UNKNOWN'
    return 'PASS'


def exit_code(checks: List[Check], published: bool) -> int:
    required = [c for c in checks if c.required and not c.planned]
    if any(c.status == 'FAIL' for c in required):
        return EXIT_FAIL
    if any(c.status in ('BLOCKED', 'NOT_RUN') for c in required) or not published:
        return EXIT_INCOMPLETE
    return EXIT_OK
