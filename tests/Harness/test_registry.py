"""Registry traceability (make registry, ТЗ AC-09) on a synthetic legacy page, inventory and target — no Docker."""
from __future__ import annotations

import csv
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools' / 'harness'))
import registry  # noqa: E402

LEGACY = """<html><body>
<div id="page-catalog">
<button onclick="saveItem()">Сохранить</button>
<button onclick="exportXls()">📥 Excel</button>
<div id="cat-later">
<button onclick="laterThing()">Позже</button>
</div>
<div onclick="fillLogin()">👔 Директор director / s3cret-pass</div>
<select id="cat-manager"><option>Иванов Иван</option></select>
</div>
<script>
function saveItem(){ return 1; }
function exportXls(){ return 2; }
function laterThing(){ return 3; }
function neverCalled(){ return 4; }
function fillLogin(){ return 5; }
</script>
</body></html>
"""

TARGET = """<template>
    <div id="page-catalog">
        <button @click="save">Сохранить</button>
        <button @click="excelLater">📥 Excel</button>
        <button>Позже</button>
        <select id="cat-manager"><option v-for="m in managers" :key="m">{{ m }}</option></select>
    </div>
</template>
<script setup lang="ts">
// Legacy saveItem: the server saves.
</script>
"""


def control(line, text, calls, tag='button', ident=''):
    return {'key': f'page-catalog|{tag}|{text}#1', 'tag': tag, 'group': 'page-catalog', 'line': line, 'id': ident, 'name': '',
            'type': '', 'text': text, 'handlers': ['onclick'] if calls else [], 'calls': calls}


INVENTORY = {
    'pages': [{'id': 'page-catalog', 'line': 2, 'end_line': 10}],
    'modals': [],
    'controls': [
        control(3, 'Сохранить', ['saveItem']),
        control(4, '📥 Excel', ['exportXls']),
        control(6, 'Позже', ['laterThing']),
        control(8, '👔 Директор director / s3cret-pass', ['fillLogin'], tag='div'),
        control(9, '', [], tag='select', ident='cat-manager'),
        control(9, 'Иванов Иван', [], tag='option'),
    ],
    'functions': [{'name': n, 'key': f'{n}#1', 'kind': 'declaration', 'line': line, 'end_line': line, 'calls': []}
                  for n, line in (('saveItem', 12), ('exportXls', 13), ('laterThing', 14), ('neverCalled', 15), ('fillLogin', 16))],
    'db_calls': {'by_table': {}, 'dynamic': [], 'items': []},
    'storage': [],
    'network': [],
}


class RegistryTrace(unittest.TestCase):
    def setUp(self) -> None:
        self.root = Path(tempfile.mkdtemp(prefix='promtech-registry-'))
        (self.root / 'resources/js/pages').mkdir(parents=True)
        (self.root / 'resources/js/pages/Catalog.vue').write_text(TARGET, encoding='utf-8')
        (self.root / 'docs/adr').mkdir(parents=True)
        (self.root / 'docs/adr/0002-ui-deviations.md').write_text('# ADR-0002\n', encoding='utf-8')
        (self.root / 'docs/coverage').mkdir(parents=True)
        with open(self.root / 'docs/coverage/requirements.csv', 'w', newline='', encoding='utf-8') as fh:
            w = csv.writer(fh, lineterminator='\n')
            w.writerow(['requirement_id', 'module', 'legacy_ref', 'legacy_locator', 'role', 'scenario', 'target_path', 'test_ids',
                        'visual_id', 'decision_id', 'status', 'evidence', 'owner'])
            w.writerow(['M07.SAVE', 'M07', 'x', 'saveItem', '', 'save', '', '', '', '', 'VERIFIED', '', ''])
            w.writerow(['M07.EXPORT', 'M07', 'x', 'exportXls', '', 'export', '', '', '', 'D-99', 'NOT_STARTED', '', ''])
        self.blob = self.root / 'legacy.html'
        self.blob.write_text(LEGACY, encoding='utf-8')

    def tearDown(self) -> None:
        shutil.rmtree(self.root, ignore_errors=True)

    def trace(self, rules):
        rules = [dict(r, used='') for r in rules]
        t = registry.Trace(self.root, INVENTORY, ['s3cret-pass'], self.blob, rules, {'page-catalog': 'M07 Каталог'})
        t.run()
        return t, {(r['kind'], r['element'].split('|')[-1] if r['kind'] == 'control' else r['element']): r for r in t.rows}

    def test_statuses_follow_the_evidence_and_the_more_specific_rule(self):
        t, rows = self.trace([
            {'kind': 'area', 'selector': 'cat-later', 'status': 'NOT_STARTED', 'reference': 'ERP-999', 'note': ''},
            {'kind': 'control', 'selector': 'page-catalog|select|cat-manager', 'status': 'DECIDED', 'reference': 'ADR-0010', 'note': ''},
        ])
        self.assertEqual(rows['control', 'L3']['status'], 'IMPLEMENTED')            # the label is there, its action is done
        self.assertEqual(rows['control', 'L4']['status'], 'NOT_STARTED')            # the button is there, its action is not
        self.assertIn('exportXls', rows['control', 'L4']['evidence'])
        self.assertEqual([rows['control', 'L6']['status'], rows['control', 'L6']['evidence']], ['NOT_STARTED', 'ERP-999'],
                         'an area rule beats the label found in the target')
        self.assertEqual(rows['control', 'cat-manager']['status'], 'DECIDED', 'a control rule beats the id found in the target')
        self.assertEqual(rows['function', 'saveItem#1']['status'], 'IMPLEMENTED')
        self.assertEqual(rows['function', 'exportXls#1']['status'], 'NOT_STARTED')
        self.assertEqual([rows['function', 'laterThing#1']['status'], rows['function', 'laterThing#1']['evidence']],
                         ['NOT_STARTED', 'by its controls: ERP-999'])
        self.assertEqual(rows['function', 'neverCalled#1']['status'], 'OBSOLETE', 'called from nowhere: dead code')
        self.assertEqual(rows['function', 'fillLogin#1']['status'], 'UNREVIEWED')

    def test_labels_never_carry_secrets_or_names_and_unreviewed_are_listed(self):
        t, rows = self.trace([])
        self.assertEqual(rows['control', 'L8']['label'], '‹скрыто›', 'a legacy password in a label')
        self.assertEqual(rows['control', 'L9']['label'], '‹скрыто›', 'a person-like name in a label')
        total, unreviewed = registry.write_outputs(self.root, t, {'commit': '0' * 40, 'sha256': 'f' * 64})
        written = (self.root / 'docs/coverage/registry.csv').read_text(encoding='utf-8') + (self.root / 'docs/coverage/registry.md').read_text(encoding='utf-8')
        self.assertNotIn('s3cret-pass', written)
        self.assertNotIn('Иванов', written)
        self.assertGreater(len(unreviewed), 0)
        self.assertIn(f'## Не просмотрено: {len(unreviewed)}', written)


if __name__ == '__main__':
    unittest.main()
