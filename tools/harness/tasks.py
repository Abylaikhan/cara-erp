"""Google Sheets tracker: read access for task_sync_status; writes require a service account (not configured)."""
from __future__ import annotations

import json
import os
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, Dict, List, Tuple

from checks import Check
from hlib import home_path

API = 'https://sheets.googleapis.com/v4/spreadsheets/'


def _get(spreadsheet: str, path: str, key: str, timeout: int = 30) -> Dict[str, Any]:
    req = urllib.request.Request(API + spreadsheet + path, headers={'X-goog-api-key': key})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.load(resp)


def outbox_pending(work: Path) -> int:
    path = work / 'tasks' / 'outbox.jsonl'
    if not path.is_file():
        return 0
    return sum(1 for line in path.read_text(encoding='utf-8').splitlines() if '"PENDING_SYNC"' in line)


def sheets_status(cfg: Dict[str, Any], work: Path) -> Tuple[List[Check], Dict[str, Any]]:
    read = Check('TASKS-READ', 'Чтение Google Sheets-трекера', False, 'tasks')
    write = Check('TASKS-WRITE', 'Запись статусов в Google Sheets с read-back', False, 'tasks')
    sheets = cfg.get('sheets', {})
    spreadsheet = os.environ.get('PROMTECH_SHEETS_ID') or sheets.get('spreadsheet_id')
    key_file = home_path(sheets.get('api_key_file', ''))
    tabs: List[Dict[str, Any]] = []
    if not spreadsheet:
        read.set('NOT_RUN', 'ID таблицы не задан (PROMTECH_SHEETS_ID или ~/.config/promtech/harness.local.json)')
    elif not key_file.is_file():
        read.set('BLOCKED', 'нет ключа Google API для чтения')
    else:
        key = key_file.read_text().strip()
        read.command = 'GET sheets.googleapis.com/v4/spreadsheets/<id> (API key)'
        try:
            meta = _get(spreadsheet, '?fields=sheets.properties(sheetId,title)', key)
            for s in meta.get('sheets', []):
                title = s['properties']['title']
                rng = urllib.parse.quote(f"'{title}'!A1:Z2000")
                values = _get(spreadsheet, f'/values/{rng}', key).get('values', [])
                tabs.append({'title': title, 'rows': len(values)})
            read.set('PASS', 'вкладки: ' + ', '.join(f"{t['title']} ({t['rows']} строк)" for t in tabs), tabs=tabs)
        except (urllib.error.URLError, OSError, ValueError) as exc:
            read.set('BLOCKED', f'ошибка чтения: {type(exc).__name__}')
    sa = home_path(sheets.get('service_account_file', ''))
    if sa.is_file():
        write.set('NOT_RUN', 'service account найден; адаптер записи с read-back ещё не реализован')
    else:
        write.set('BLOCKED', 'нет service account с доступом «Редактор» (Sheets API не принимает запись по API-ключу)')
    status = {'read': read.status, 'write': write.status, 'tabs': tabs, 'pending_events': outbox_pending(work),
              'delivered': 0, 'conflicts': 0}
    return [read, write], status
