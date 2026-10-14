#!/usr/bin/env python3
"""`make harness-selftest`: runs tests/Harness and prints the H-01…H-25 coverage table (ТЗ §13)."""
from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

# H-ID → (scenario, how it is covered). 'test_hNN' methods are automated here; other rows say where/why.
MATRIX = [
    ('H-01', 'Чистая машина с Docker', 'MANUAL: чистый клон → make setup/doctor/check (13.10.2026, отчёт в docs/agent-state.md)'),
    ('H-02', 'Повтор setup/down/up', 'MANUAL: .env/APP_KEY не меняются, пробная запись пережила down/up (13.10.2026)'),
    ('H-03', 'Тест на dev/production БД', 'PHPUnit: tests/Unit/TestDatabaseGuardTest (выполняется в TGT-TESTS каждого аудита)'),
    ('H-04', 'Первый audit', 'test_h04'),
    ('H-05', 'Изменена формула/поле legacy', 'test_h05'),
    ('H-06', 'Изменена миграция/Policy', 'BY DESIGN: каждый аудит запускает полный backend-набор, выборочного пропуска нет'),
    ('H-07', 'Ничего не изменилось', 'test_h07'),
    ('H-08', 'Пропуск 3 дней', 'test_h08'),
    ('H-09', 'GitHub/API/БД/модель недоступны', 'test_h09'),
    ('H-10', 'Падение теста', 'test_h10'),
    ('H-11', 'Два audit одновременно', 'test_h11'),
    ('H-12', 'Повтор того же входа', 'test_h07_h12'),
    ('H-13', 'Новый main посреди запуска', 'test_h13'),
    ('H-14', 'Force-push / baseline исчез', 'test_h14'),
    ('H-15', 'Index.html и index.html', 'test_h15'),
    ('H-16', 'Команды в исходнике/коммите', 'test_h16'),
    ('H-17', 'AI предлагает закрыть без теста', 'test_h17'),
    ('H-18', 'Удалён тест/проверка', 'test_h18'),
    ('H-19', 'Падение перед публикацией', 'test_h19'),
    ('H-20', 'Publisher недоступен', 'test_h20'),
    ('H-21', 'Секрет-маркер в данных', 'test_h21'),
    ('H-22', 'Отказ между шагами записи', 'NOT_IMPLEMENTED: бизнес-операций ERP ещё нет'),
    ('H-23', 'Два одновременных проведения', 'NOT_IMPLEMENTED: бизнес-операций ERP ещё нет'),
    ('H-24', 'Backup → restore-БД', 'test_h24'),
    ('H-25', 'Новый сотрудник по инструкции', 'NOT_VERIFIED: нужна проверка человеком по README'),
]


def main() -> int:
    suite = unittest.defaultTestLoader.discover(str(ROOT / 'tests' / 'Harness'))
    ran = set(_names(suite))  # collect before running: unittest drops finished tests from the suite
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    failed = {t.id().rsplit('.', 1)[-1] for t, _ in result.failures + result.errors}
    print('\nH-ID  статус           сценарий / покрытие')
    for hid, scenario, how in MATRIX:
        if how.startswith('test_'):
            names = [n for n in ran if n.startswith(how)]
            status = 'FAIL' if any(n in failed for n in names) else ('PASS' if names else 'MISSING')
        else:
            status = how.split(':')[0]
        print(f'{hid}  {status:<16} {scenario} — {how}')
    extra = sorted(n for n in ran if not any(n.startswith(h) for _, _, h in MATRIX if h.startswith('test_')))
    for n in extra:
        print(f'      {"FAIL" if n in failed else "PASS":<16} {n}')
    return 0 if result.wasSuccessful() else 1


def _names(suite):
    for item in suite:
        if isinstance(item, unittest.TestSuite):
            yield from _names(item)
        elif item is not None:
            yield item.id().rsplit('.', 1)[-1]


if __name__ == '__main__':
    sys.exit(main())
