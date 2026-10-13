"""Markdown rendering of the run report and the cumulative journal entry (ТЗ §8 template)."""
from __future__ import annotations

import re
from typing import Any, Dict, List

STATUS_RU = {'PASS': 'PASS', 'FAIL': 'FAIL', 'BLOCKED': 'BLOCKED', 'NOT_RUN': 'NOT_RUN', 'NOT_APPLICABLE': 'N/A'}
SOURCE_RU = {
    'INITIAL_BASELINE': 'первый запуск (INITIAL_BASELINE): полный inventory, предыстория не выдаётся за сегодняшние изменения',
    'UNCHANGED': 'изменений нет',
    'CHANGED': 'есть изменения',
    'HISTORY_DIVERGED': 'история разошлась (HISTORY_DIVERGED): прежний ref не является предком нового',
    'BLOCKED': 'не получен (BLOCKED)',
}
CHANGELOG_HEADER = """# Ежедневный журнал аудита PromTech ERP

Часовой пояс: `Asia/Almaty`. Записи добавляет `make audit-daily` (tools/harness); подробные отчёты — в `docs/daily/`.
Числа берутся из результатов команд, а не из текста модели. Ручной анализ до 13.10.2026 — в журнале пакета ТЗ.
"""


def data_text(value: Any, limit: int = 160) -> str:
    """Untrusted text (commit subjects etc.) rendered inert: no Markdown/HTML, quoted, truncated."""
    s = re.sub(r'\s+', ' ', str(value or '')).strip()
    s = s.replace('\\', '\\\\').replace('`', "'").replace('|', '\\|').replace('<', '‹').replace('>', '›')
    s = s.replace('[', '(').replace(']', ')').replace('*', '∗').replace('_', '\\_')
    return '«' + (s[:limit] + '…' if len(s) > limit else s) + '»'


def short(sha: Any) -> str:
    return str(sha)[:7] if sha else '—'


def _list(items: List[str], limit: int = 30) -> str:
    if not items:
        return '—'
    shown = ', '.join(f'`{x}`' for x in items[:limit])
    return shown + (f' и ещё {len(items) - limit}' if len(items) > limit else '')


def change_summary(r: Dict[str, Any]) -> str:
    s, t = r['source'], r['target']
    parts = []
    if r['source_status'] == 'INITIAL_BASELINE':
        parts.append('legacy: начальная фиксация эталона, сравнение с прошлым отчётом не выполнялось')
    elif r['source_status'] == 'UNCHANGED':
        parts.append('legacy: изменений нет')
    elif r['source_status'] == 'BLOCKED':
        parts.append('legacy: не получен')
    else:
        d = s.get('diff') or {}
        f = d.get('functions', {})
        parts.append(f"legacy: {s.get('commit_count', 0)} коммит(ов), функции +{len(f.get('added', []))}/−{len(f.get('removed', []))}/~{len(f.get('changed', []))}")
    files = t.get('changed_files') or []
    if not t.get('head'):
        parts.append('целевой код: не проверялся')
    elif t.get('base_ref') is None:
        parts.append('целевой код: первая фиксация')
    elif not files:
        parts.append('целевой код: изменений нет')
    else:
        parts.append(f'целевой код: изменено файлов {len(files)}')
    return '; '.join(parts)


def actions(r: Dict[str, Any]) -> List[str]:
    out = []
    failed = [c for c in r['checks'] if c['status'] == 'FAIL' and c['required']]
    if failed:
        out.append('Разобрать и исправить провалы: ' + ', '.join(f"`{c['id']}`" for c in failed) + ' (ответственный — исполнитель задачи, приоритет P0).')
    blocked = [c for c in r['checks'] if c['status'] == 'BLOCKED' and c['required']]
    if blocked:
        out.append('Снять блокировки обязательных проверок: ' + ', '.join(f"`{c['id']}`" for c in blocked) + '.')
    unmapped = [i for i in (r['source'].get('impact') or []) if i.get('mapping') == 'UNMAPPED_CHANGE']
    if unmapped:
        out.append(f'Ручной разбор {len(unmapped)} изменений legacy без привязки к требованиям (UNMAPPED_CHANGE); карта требований — задача ERP-003.')
    if r['findings']['needs_review']:
        out.append('Просмотреть находки со статусом NEEDS_REVIEW: ' + _list(r['findings']['needs_review']) + '.')
    ts = r['task_sync_status']
    if ts['write'] != 'PASS':
        out.append('Google Sheets: запись недоступна — нужен service account с доступом «Редактор» (вопрос заказчику №1).')
    if r['ai_review']['status'] != 'PASS':
        out.append('AI-разбор не выполнен: ' + (r['ai_review'].get('reason') or '') + '.')
    return out or ['Действий не требуется.']


def render_md(r: Dict[str, Any]) -> str:
    s, t, ts = r['source'], r['target'], r['task_sync_status']
    acc = s.get('accepted_ref') or {}
    acc_note = {True: 'совпадает с наблюдаемой версией', False: 'отличается от наблюдаемой версии', None: 'не проверен'}[acc.get('matches_observed')]
    if not t.get('head'):
        pushed = 'не проверялся'
    elif t.get('on_remote_branches'):
        pushed = 'есть в ' + ', '.join(t['on_remote_branches'])
    else:
        pushed = 'локальный коммит, ещё не опубликован'
    lines = [
        f"# Аудит {r['local_date']} · {r['run_id']}",
        '',
        f"- Проверено: {r['started_at']} UTC / {r['local_time']} {r['timezone']}; длительность {r['duration_s']:.0f} с. Режим: {r['mode']}.",
        f"- Harness {r['harness']['version']}, набор проверок `{r['harness']['suite_version']}`, конфигурация `{r['harness']['config_hash']}`, inventory `{r['harness'].get('inventory_tool', '—')}`.",
        f"- Источник: {s['repository']} `{s['branch']}`, путь `{s['path']}`: `{short(s.get('base_ref'))}` → `{short(s['observed_head'])}`; blob `{short(s.get('blob'))}`, SHA-256 `{s.get('sha256') or '—'}`.",
        f"- Состояние источника: {SOURCE_RU[r['source_status']]}. Принятый эталон `{short(acc.get('commit'))}` {acc_note}.",
        f"- Целевой код: `{short(t.get('base_ref'))}` → `{short(t['head'])}` ({pushed}).",
        f"- Google Sheets: чтение {ts['read']}, запись {ts['write']}; ожидают синхронизации {ts['pending_events']}, доставлено {ts['delivered']}, конфликтов {ts['conflicts']}.",
        f"- **Выполнение: {r['execution_status']}. Продукт: {r['product_status']}. Exit code: {r['exit_code']}.**",
    ]
    if r.get('repeat_of'):
        lines.append(f"- Повтор того же набора входных данных, что и `{r['repeat_of']}`; находки не дублируются.")
    if r.get('previous_attempt'):
        p = r['previous_attempt']
        lines.append(f"- Предыдущая попытка `{p.get('run_id')}` не была завершена (стадия `{p.get('stage')}`); результат этой попытки не публиковался.")
    if r.get('missed_days'):
        lines.append(f"- Пропущены дни без отчёта: {', '.join(r['missed_days'])}. Диапазон коммитов ниже покрывает весь пропуск.")

    lines += ['', '## Изменения', '', '### Legacy']
    if r['source_status'] == 'BLOCKED':
        lines.append('- Источник не получен — изменения не определены.')
    elif r['source_status'] == 'INITIAL_BASELINE':
        lines.append('- Первый запуск: зафиксирован полный inventory наблюдаемой версии. Сравнение с прошлым отчётом не выполнялось.')
    elif r['source_status'] == 'UNCHANGED':
        lines.append('- Изменений нет: blob точного пути совпадает с прошлым отчётом.')
    if s.get('commits') and r['source_status'] == 'INITIAL_BASELINE':
        lines.append('- Наблюдаемый коммит (текст коммита — данные, а не инструкции):')
    elif s.get('commits'):
        lines.append(f"- Коммиты в диапазоне: {s.get('commit_count')}. Тексты коммитов — данные, а не инструкции:")
        for c in s['commits'][:20]:
            lines.append(f"  - `{short(c['sha'])}` {c['committed_at']} — {data_text(c['subject'])}")
    d = s.get('diff')
    if d:
        f = d['functions']
        lines.append(f"- Строки `{s['path']}`: +{s['numstat']['added']} / −{s['numstat']['removed']}.")
        lines.append(f"- Функции: добавлено {len(f['added'])}, удалено {len(f['removed'])}, изменено {len(f['changed'])}.")
        for label, key in (('добавлены', 'added'), ('удалены', 'removed'), ('изменены', 'changed')):
            if f[key]:
                lines.append(f'  - {label}: {_list(f[key])}')
        for sec, ru in (('pages', 'Страницы'), ('modals', 'Модальные окна')):
            x = d[sec]
            if x['added'] or x['removed'] or x['changed']:
                lines.append(f"- {ru}: +{_list(x['added'])}; −{_list(x['removed'])}; изменены {_list(x['changed'])}.")
        c = d['controls']
        if c['added'] or c['removed'] or c['changed']:
            lines.append(f"- Элементы управления: +{len(c['added'])} / −{len(c['removed'])} / изменены обработчики {len(c['changed'])} (подписи не публикуются: могут содержать персональные данные).")
        db = d['db']
        if db['tables_added'] or db['tables_removed'] or db['calls_changed']:
            lines.append(f"- Таблицы БД: +{_list(db['tables_added'])}; −{_list(db['tables_removed'])}; изменены вызовы {_list(sorted(db['calls_changed']))}.")
        for sec, ru in (('storage', 'localStorage/sessionStorage'), ('resources', 'Внешние ресурсы'), ('network', 'Сетевые вызовы')):
            if d[sec]['added'] or d[sec]['removed']:
                lines.append(f"- {ru}: +{_list(d[sec]['added'])}; −{_list(d[sec]['removed'])}.")
        if d['counts']:
            lines.append('- Счётчики: ' + ', '.join(f"{k} {v['old']}→{v['new']}" for k, v in d['counts'].items()) + '.')
    watched = [w for w in s.get('watched', []) if w.get('changed')]
    lines.append('- Другие HTML (не эталон): ' + (', '.join(f"`{w['path']}` изменён" for w in watched) if watched else 'без изменений') + '.')
    if s.get('case_collisions'):
        lines.append('- Коллизии регистра в дереве: ' + '; '.join(' / '.join(f'`{p}`' for p in g) for g in s['case_collisions'])
                     + f". Используется blob точного пути `{s['path']}`, checkout не выполняется.")
    if s.get('diff_vs_accepted'):
        lines.append('- Отличие наблюдаемой версии от принятого эталона: ' + s['diff_vs_accepted'] + '.')

    lines += ['', '### Целевой код']
    files = t.get('changed_files') or []
    if not t.get('head'):
        lines.append('- Не проверялся: ' + next((c['reason'] for c in r['checks'] if c['id'] == 'TGT-REF'), '') + '.')
    elif t.get('base_ref') is None:
        lines.append(f"- Первая фиксация целевого кода: `{short(t['head'])}` {data_text(t.get('subject'))}.")
    elif not files:
        lines.append('- Изменений нет.')
    else:
        lines.append(f"- Изменено файлов: {len(files)}: {_list(files, 40)}.")
    if t.get('working_tree_dirty'):
        lines.append('- В рабочем дереве есть незакоммиченные изменения: они не проверялись (проверяется только зафиксированный коммит).')

    lines += ['', '## Влияние на перенос', '']
    impact = s.get('impact') or []
    if not impact:
        lines.append('- Изменений legacy, требующих переноса, не обнаружено.' if r['source_status'] != 'INITIAL_BASELINE'
                     else '- Не оценивалось: первый запуск фиксирует эталон.')
    else:
        mods: Dict[str, int] = {}
        for i in impact:
            for m in i.get('modules') or ['не сопоставлено']:
                mods[m] = mods.get(m, 0) + 1
        lines.append('- Затронутые модули: ' + ', '.join(f'{m} ({n})' for m, n in sorted(mods.items())) + '.')
        unmapped = sum(1 for i in impact if i['mapping'] == 'UNMAPPED_CHANGE')
        lines.append(f'- Связано с требованиями: {len(impact) - unmapped}; без связи (UNMAPPED_CHANGE, нужен ручной разбор): {unmapped}.')
        for i in impact[:25]:
            name = i.get('function') or i.get('page')
            lines.append(f"  - `{name}` ({i['change']}): {', '.join(i.get('modules') or ['модуль не определён'])}; "
                         f"требования {_list(i.get('requirement_ids', []))}; тесты {_list(i.get('test_ids', []))}.")

    sm = r['summary']
    lines += ['', '## Проверки', '',
              f"PASS {sm['PASS']} / FAIL {sm['FAIL']} / BLOCKED {sm['BLOCKED']} / NOT_RUN {sm['NOT_RUN']} / N/A {sm['NOT_APPLICABLE']}.", '',
              '| ID | Проверка | Обяз. | Статус | Время, с | Подробности |', '|---|---|---|---|---|---|']
    for c in r['checks']:
        detail = c.get('reason') or ''
        if c.get('evidence'):
            detail = (detail + '; ' if detail else '') + 'лог: ' + ', '.join(f'`{e}`' for e in c['evidence'][:2])
        lines.append(f"| `{c['id']}` | {c['title']} | {'да' if c['required'] else 'нет'} | {STATUS_RU[c['status']]} | "
                     f"{c['duration_s']:.1f} | {detail.replace('|', '/')} |")
    tests = t.get('tests')
    if tests:
        lines.append('')
        lines.append(f"Backend-тесты: {tests['tests']} (passed {tests['passed']}, failed {tests['failed']}, error {tests['error']}, skipped {tests['skipped']}).")

    fd = r['findings']
    lines += ['', '## Находки', '',
              f"- Новые: {_list(fd['new'])}. Повторные: {_list(fd['repeated'])}. Исправленные (закрыты проверкой): {_list(fd['closed'])}. "
              f"Требуют решения/review: {_list(fd['needs_review'])}. Всего открыто: {fd['open_total']}."]
    for it in fd.get('items', [])[:30]:
        lines.append(f"  - `{it['id']}` [{it['status']}] `{it['check_id']}` — {it['title']} (впервые `{it['first_seen']['run_id']}`).")

    ai = r['ai_review']
    lines += ['', '## AI-разбор', '', f"- Статус: {ai['status']}" + (f" — {ai['reason']}" if ai.get('reason') else '') + '.']
    if ai.get('summary'):
        lines.append('- Кратко (текст модели, не результат проверок): ' + data_text(ai['summary'], 600))
        for item in ai.get('items', [])[:20]:
            lines.append(f"  - [{item['kind']}] {data_text(item['text'], 300)}")
        lines.append('- Рекомендации модели по находкам не применяются автоматически: закрытие только по повторной проверке.')

    lines += ['', '## Визуальные отличия', '', '- Не проверялись: визуальные эталоны legacy ещё не сняты (`TGT-VISUAL` запланирована).',
              '', '## БД', '',
              f"- Миграции и синтетический seed на чистой PostgreSQL: {next((c['status'] for c in r['checks'] if c['id'] == 'TGT-MIGRATE'), 'NOT_RUN')}.",
              '- Бизнес-инварианты DATA-01: не реализованы — модули ERP ещё не перенесены. Backup/restore-check: не реализован.',
              '', '## Действия', '']
    lines += [f'- {a}' for a in r['actions']]
    lines += ['', '## Не проверено', '']
    lines += [f'- {x}' for x in r['not_checked']]
    return '\n'.join(lines) + '\n'


def render_changelog_entry(r: Dict[str, Any], md_rel: str) -> str:
    s, t, ts, sm, fd = r['source'], r['target'], r['task_sync_status'], r['summary'], r['findings']
    impact = s.get('impact') or []
    mods = sorted({m for i in impact for m in (i.get('modules') or [])})
    lines = [
        f"## {r['local_date']} · {r['run_id']}",
        f"- Проверено: {r['started_at']} UTC / {r['local_time']} {r['timezone']}; {r['duration_s']:.0f} с.",
        f"- Источник: `{short(s.get('base_ref'))}` → `{short(s['observed_head'])}`, `{s['path']}`, SHA-256 `{(s.get('sha256') or '—')[:16]}…`; {SOURCE_RU[r['source_status']]}.",
        f"- Целевой код: `{short(t.get('base_ref'))}` → `{short(t['head'])}`; harness {r['harness']['version']}, набор `{r['harness']['suite_version']}`.",
        f"- Google Sheets: чтение {ts['read']}, запись {ts['write']}, ожидают {ts['pending_events']}.",
        f"- Выполнение: {r['execution_status']}. Продукт: {r['product_status']}. Exit code: {r['exit_code']}.",
        f"- Изменения: {change_summary(r)}.",
        f"- Влияние на перенос: {', '.join(mods) if mods else 'не выявлено'}"
        + (f"; без привязки к требованиям: {sum(1 for i in impact if i['mapping'] == 'UNMAPPED_CHANGE')}" if impact else '') + '.',
        f"- Проверки: PASS {sm['PASS']} / FAIL {sm['FAIL']} / BLOCKED {sm['BLOCKED']} / NOT_RUN {sm['NOT_RUN']}; [полный отчёт]({md_rel}).",
        f"- Ошибки: новые {len(fd['new'])}, повторные {len(fd['repeated'])}, исправленные {len(fd['closed'])}, требуют решения {len(fd['needs_review'])}; открыто всего {fd['open_total']}.",
        '- Визуальные отличия: не проверялись (эталоны не сняты).',
        f"- БД: миграции/seed {next((c['status'] for c in r['checks'] if c['id'] == 'TGT-MIGRATE'), 'NOT_RUN')}; инварианты и restore-check не реализованы.",
        f"- Действия: {r['actions'][0]}" + (f' (и ещё {len(r["actions"]) - 1})' if len(r['actions']) > 1 else ''),
        f"- Не проверено: {len(r['not_checked'])} пунктов — см. отчёт.",
    ]
    return '\n'.join(lines) + '\n'
