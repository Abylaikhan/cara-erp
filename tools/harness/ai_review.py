"""Optional Claude review stage. It explains and proposes; it never changes check results or findings."""
from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple

from checks import Check
from hlib import Redactor, read_json, run_cmd, write_json
from inventory import NODE_DIR

LIST_KEYS = ('business_changes', 'risks', 'suggested_checks', 'finding_recommendations')
REQUIRED = ('summary',) + LIST_KEYS + ('gaps',)


def validate_review(review: Any) -> List[str]:
    problems = []
    if not isinstance(review, dict):
        return ['review is not an object']
    for key in REQUIRED:
        if key not in review:
            problems.append(f'missing {key}')
    for key in LIST_KEYS:
        items = review.get(key, [])
        if not isinstance(items, list):
            problems.append(f'{key} is not a list')
            continue
        for i, item in enumerate(items):
            if not isinstance(item, dict) or not isinstance(item.get('evidence_refs', []), list):
                problems.append(f'{key}[{i}] malformed')
    return problems


def classify(review: Dict[str, Any], known: Set[str]) -> Tuple[Dict[str, Any], int]:
    """Mark every claim as supported (cites known evidence) or hypothesis. Recommendations stay advisory."""
    hypotheses = 0
    out = dict(review)
    for key in LIST_KEYS:
        marked = []
        for item in review.get(key, []):
            refs = [r for r in item.get('evidence_refs', []) if isinstance(r, str)]
            valid = [r for r in refs if r in known]
            entry = dict(item, valid_refs=valid, unknown_refs=[r for r in refs if r not in known],
                         kind='supported' if valid else 'hypothesis')
            if key == 'finding_recommendations':
                entry['applied'] = False  # findings change only through re-run checks on a new ref
            hypotheses += 0 if valid else 1
            marked.append(entry)
        out[key] = marked
    return out, hypotheses


def run_review(cfg: Dict[str, Any], payload: Dict[str, Any], known: Set[str], redactor: Redactor,
               run_dir: Path, image: str) -> Tuple[Check, Optional[Dict[str, Any]]]:
    check = Check('AI-REVIEW', 'Разбор изменений Claude (дополнение, не заменяет проверки)', False, 'ai',
                  scope='structured diff, impact map, check results, open findings')
    ai = cfg.get('ai_review', {})
    if ai.get('fake_response'):
        response = read_json(Path(ai['fake_response']), {})
        check.command = 'fake response (selftest)'
    elif not ai.get('enabled'):
        return check.set('NOT_RUN', ai.get('reason_disabled', 'AI-разбор отключён конфигурацией')), None
    elif not os.environ.get('ANTHROPIC_API_KEY'):
        return check.set('BLOCKED', 'не задан ANTHROPIC_API_KEY'), None
    else:
        write_json(run_dir / 'ai-input.json', redactor.redact_obj(payload))
        argv = ['docker', 'run', '--rm', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                '--user', f'{os.getuid()}:{os.getgid()}', '--tmpfs', '/tmp:size=16m', '-e', 'ANTHROPIC_API_KEY',
                '-e', f"AI_MODEL={ai.get('model', 'claude-opus-5-5')}", '-e', f"AI_EFFORT={ai.get('effort', 'high')}",
                '-e', f"AI_MAX_TOKENS={ai.get('max_tokens', 16000)}", '-e', f"AI_BUDGET_USD={ai.get('budget_usd_per_run', 1)}",
                '-v', f'{NODE_DIR}:/tool:ro', '-v', f'{run_dir}:/in:ro', image, 'node', '/tool/ai_review.mjs', '/in/ai-input.json']
        res = run_cmd(argv, timeout=int(ai.get('timeout_s', 600)))
        check.command, check.duration_s, check.exit_code = 'docker run … node ai_review.mjs', res.duration_s, res.exit_code
        try:
            response = json.loads(res.stdout)
        except ValueError:
            response = {'status': 'ERROR', 'reason': redactor.redact(res.stderr.strip()[-300:])}
    status = response.get('status')
    if status != 'OK':
        return check.set('BLOCKED', f"{status}: {response.get('reason', '')}".strip()), response
    problems = validate_review(response.get('review'))
    if problems:
        return check.set('BLOCKED', 'ответ модели не прошёл проверку схемы: ' + '; '.join(problems[:5])), response
    review, hypotheses = classify(response['review'], known)
    response = dict(response, review=redactor.redact_obj(review))
    check.set('PASS', details={'model': response.get('model'), 'hypotheses': hypotheses,
                               'fallback_used': response.get('fallback_used', False)})
    return check, response
