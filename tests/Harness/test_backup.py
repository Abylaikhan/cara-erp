"""H-24 «Backup → отдельная restore-БД» (ТЗ §13): make db-backup / db-restore-check on a throwaway database of the dev
PostgreSQL service (needs Docker and the running `db`, as after `make setup`; the dev database itself is not touched)."""
from __future__ import annotations

import json
import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def sh(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(list(args), cwd=ROOT, capture_output=True, text=True, timeout=600)


class BackupRestoreTest(unittest.TestCase):
    def setUp(self):
        self.user = sh('docker', 'compose', 'exec', '-T', 'db', 'printenv', 'POSTGRES_USER').stdout.strip()
        self.assertTrue(self.user, 'the dev `db` service must be running (make up)')
        self.db = f'promtech_h24_{os.getpid()}'
        self.tmp = Path(tempfile.mkdtemp(prefix='promtech-h24-'))
        self.psql('postgres', f'CREATE DATABASE {self.db}')
        self.psql(self.db, """
            CREATE TABLE demo (id bigserial PRIMARY KEY, name varchar(100) COLLATE "en-US-x-icu" NOT NULL, amount numeric(18,2) CHECK (amount >= 0),
                at timestamptz(6), data jsonb DEFAULT '[]', status varchar(8) CHECK (status IN ('open', 'done')));
            CREATE INDEX demo_name ON demo (name);
            INSERT INTO demo (name, amount, at, data, status) VALUES ('Демо «один»', 10.50, '2026-10-14 08:00:00.123456+00', '{"a": [1, 2]}', 'open'),
                ('Demo two', NULL, NULL, '[]', 'done'), ('Третий', 0, '2026-01-01 00:00:00+05', 'null', NULL);""")

    def tearDown(self):
        self.psql('postgres', f'DROP DATABASE IF EXISTS {self.db} WITH (FORCE)')
        shutil.rmtree(self.tmp, ignore_errors=True)

    def psql(self, db: str, sql: str) -> None:
        r = subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'psql', '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-U', self.user, '-d', db],
                           cwd=ROOT, input=sql, capture_output=True, text=True, timeout=120)
        self.assertEqual(r.returncode, 0, r.stderr)

    def test_h24_backup_restores_into_a_separate_database_with_the_same_schema_rows_and_checksums(self):
        copy = self.tmp / 'copy'
        backup = sh('bash', 'scripts/db-backup.sh', '--db', self.db, '--out', str(copy))
        self.assertEqual(backup.returncode, 0, backup.stdout + backup.stderr)
        manifest = json.loads((copy / 'manifest.json').read_text())
        self.assertEqual([manifest['database'], manifest['fingerprint']['tables'], manifest['fingerprint']['rows'], manifest['fingerprint']['sequences']], [self.db, 1, 3, 1])
        self.assertEqual(stat.S_IMODE(copy.stat().st_mode), 0o700)
        self.assertEqual({stat.S_IMODE(p.stat().st_mode) for p in copy.iterdir()}, {0o600})

        check = sh('bash', 'scripts/db-restore-check.sh', str(copy))
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
        self.assertEqual(json.loads((copy / 'restore-check.json').read_text())['status'], 'PASS')

        # A copy that does not hold what the database held is reported.
        fingerprint = copy / 'fingerprint.tsv'
        original = fingerprint.read_text()
        fingerprint.write_text(original.replace('table:public.demo\t3\t', 'table:public.demo\t4\t'))
        check = sh('bash', 'scripts/db-restore-check.sh', str(copy))
        self.assertNotEqual(check.returncode, 0)
        result = json.loads((copy / 'restore-check.json').read_text())
        self.assertEqual(result['status'], 'FAIL')
        self.assertIn('table:public.demo: 4 → 3', result['problems'])

        # A damaged dump is refused before anything is restored.
        fingerprint.write_text(original)
        with open(copy / 'db.dump', 'ab') as f:
            f.write(b'\0')
        check = sh('bash', 'scripts/db-restore-check.sh', str(copy))
        self.assertNotEqual(check.returncode, 0)
        self.assertIn('does not match its SHA-256', check.stdout + check.stderr)


if __name__ == '__main__':
    unittest.main()
