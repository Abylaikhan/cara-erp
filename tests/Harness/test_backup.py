"""H-24 «Backup → отдельная restore-БД» (ТЗ §13, ERP-074): make db-backup / db-restore-check on throwaway databases of the dev
PostgreSQL service and synthetic files (needs Docker and the running `db`, as after `make setup`; the dev database and the
dev files are not touched)."""
from __future__ import annotations

import hashlib
import io
import json
import os
import shutil
import stat
import subprocess
import tarfile
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def sh(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(list(args), cwd=ROOT, capture_output=True, text=True, timeout=900)


class BackupRestoreTest(unittest.TestCase):
    def setUp(self):
        self.user = sh('docker', 'compose', 'exec', '-T', 'db', 'printenv', 'POSTGRES_USER').stdout.strip()
        self.assertTrue(self.user, 'the dev `db` service must be running (make up)')
        self.tmp = Path(tempfile.mkdtemp(prefix='promtech-h24-'))
        self.dbs: list[str] = []

    def tearDown(self):
        for db in self.dbs:
            self.psql('postgres', f'DROP DATABASE IF EXISTS {db} WITH (FORCE)')
        shutil.rmtree(self.tmp, ignore_errors=True)

    def psql(self, db: str, sql: str) -> None:
        r = subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'psql', '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-U', self.user, '-d', db],
                           cwd=ROOT, input=sql, capture_output=True, text=True, timeout=120)
        self.assertEqual(r.returncode, 0, r.stderr)

    def database(self, prefix: str) -> str:
        db = f'{prefix}_{os.getpid()}'
        self.psql('postgres', f'CREATE DATABASE {db}')
        self.dbs.append(db)
        return db

    def storage(self, files: dict[str, bytes]) -> Path:
        root = self.tmp / f'storage-{len(list(self.tmp.iterdir()))}'
        for rel, content in files.items():
            (root / rel).parent.mkdir(parents=True, exist_ok=True)
            (root / rel).write_bytes(content)
        return root

    def backup(self, db: str, files: Path) -> Path:
        copy = self.tmp / f'copy-{db}'
        r = sh('bash', 'scripts/db-backup.sh', '--db', db, '--files', str(files), '--out', str(copy))
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.backup_output = r.stdout
        return copy

    def restore_check(self, copy: Path) -> tuple[subprocess.CompletedProcess, dict]:
        r = sh('bash', 'scripts/db-restore-check.sh', str(copy))
        return r, json.loads((copy / 'restore-check.json').read_text())

    @staticmethod
    def retar(copy: Path, change: dict[str, bytes]) -> None:
        """Rewrites files.tar with other members (as a damaged or forged archive would be) and fixes its SHA-256 in the manifest."""
        members = []
        with tarfile.open(copy / 'files.tar') as tar:
            for m in tar:
                members.append((m, tar.extractfile(m).read() if m.isreg() else None))
        with tarfile.open(copy / 'files.tar', 'w', format=tarfile.PAX_FORMAT) as tar:
            for m, data in members:
                if m.isreg() and m.name in change:
                    data = change.pop(m.name)
                    m.size = len(data)
                tar.addfile(m, io.BytesIO(data) if data is not None else None)
            for name, data in change.items():
                info = tarfile.TarInfo(name)
                info.size = len(data)
                tar.addfile(info, io.BytesIO(data))
        manifest = json.loads((copy / 'manifest.json').read_text())
        manifest['files']['sha256'] = hashlib.sha256((copy / 'files.tar').read_bytes()).hexdigest()
        (copy / 'manifest.json').write_text(json.dumps(manifest))

    def test_h24_backup_restores_into_a_separate_database_with_the_same_schema_rows_checksums_and_files(self):
        db = self.database('promtech_h24')
        self.psql(db, """
            CREATE TABLE demo (id bigserial PRIMARY KEY, name varchar(100) COLLATE "en-US-x-icu" NOT NULL, amount numeric(18,2) CHECK (amount >= 0),
                at timestamptz(6), data jsonb DEFAULT '[]', status varchar(8) CHECK (status IN ('open', 'done')));
            CREATE INDEX demo_name ON demo (name);
            INSERT INTO demo (name, amount, at, data, status) VALUES ('Демо «один»', 10.50, '2026-10-14 08:00:00.123456+00', '{"a": [1, 2]}', 'open'),
                ('Demo two', NULL, NULL, '[]', 'done'), ('Третий', 0, '2026-01-01 00:00:00+05', 'null', NULL);
            CREATE TABLE project_files (id bigserial PRIMARY KEY, storage_path varchar(255) NOT NULL, size bigint NOT NULL);
            INSERT INTO project_files (storage_path, size) VALUES ('projects/1/demo.txt', 5), ('projects/1/gone.pdf', 10);""")
        files = self.storage({'private/projects/1/demo.txt': b'hello', 'public/logo.bin': bytes(range(256)), 'private/.gitignore': b'*\n'})

        copy = self.backup(db, files)
        manifest = json.loads((copy / 'manifest.json').read_text())
        self.assertEqual([manifest['database'], manifest['fingerprint']['tables'], manifest['fingerprint']['rows'], manifest['fingerprint']['sequences']], [db, 2, 5, 2])
        self.assertEqual([manifest['files']['count'], manifest['files']['bytes'], manifest['files']['links']['referenced'], manifest['files']['links']['missing']],
                         [2, 261, 2, ['projects/1/gone.pdf']], '.gitignore is not data')
        self.assertIsNone(manifest['views'], 'not the application database: no answers recorded')
        self.assertIn('1 project file(s) have no file', self.backup_output)
        self.assertEqual(stat.S_IMODE(copy.stat().st_mode), 0o700)
        self.assertEqual({stat.S_IMODE(p.stat().st_mode) for p in copy.iterdir()}, {0o600})

        check, result = self.restore_check(copy)
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
        self.assertEqual([result['status'], result['files']['restored'], result['files']['missing_at_copy'], result['app']['status']], ['PASS', 2, 1, 'SKIPPED'])

        # A copy that does not hold what the database held is reported.
        fingerprint = copy / 'fingerprint.tsv'
        original = fingerprint.read_text()
        fingerprint.write_text(original.replace('table:public.demo\t3\t', 'table:public.demo\t4\t'))
        check, result = self.restore_check(copy)
        self.assertNotEqual(check.returncode, 0)
        self.assertEqual(result['status'], 'FAIL')
        self.assertIn('table:public.demo: 4 → 3', result['problems'])
        fingerprint.write_text(original)

        # A file that is not what was copied, and a path out of the restore directory, are reported.
        self.retar(copy, {'private/projects/1/demo.txt': b'HELLO', f'../evil-{os.getpid()}': b'x'})
        check, result = self.restore_check(copy)
        self.assertNotEqual(check.returncode, 0)
        self.assertIn('file differs from the list: private/projects/1/demo.txt', result['files']['problems'])
        self.assertTrue(any(p.startswith('unsafe path in the archive') for p in result['files']['problems']))
        self.assertNotIn('demo.txt', check.stdout + check.stderr, 'file names stay out of the console')
        self.assertFalse((Path(tempfile.gettempdir()) / f'evil-{os.getpid()}').exists())

        # A damaged dump or archive is refused before anything is restored.
        with open(copy / 'db.dump', 'ab') as f:
            f.write(b'\0')
        check = sh('bash', 'scripts/db-restore-check.sh', str(copy))
        self.assertNotEqual(check.returncode, 0)
        self.assertIn('does not match its SHA-256', check.stdout + check.stderr)

    def test_h24_the_application_on_the_restored_copy_answers_as_at_the_copy(self):
        db = self.database('promtech_h24app')
        migrate = sh('docker', 'compose', 'run', '--rm', '--no-deps', '-T', '-e', f'DB_DATABASE={db}', 'app', 'php', 'artisan', 'migrate', '--force')
        self.assertEqual(migrate.returncode, 0, migrate.stdout + migrate.stderr)
        self.psql(db, """
            INSERT INTO users (login, name, password, role, is_active) VALUES ('h24_director', 'Демо Директор', 'not-a-password', 'director', true),
                ('h24_storekeeper', 'Демо Кладовщик', 'not-a-password', 'storekeeper', true);
            INSERT INTO projects (id, num, title, channel, status, created_at, updated_at) VALUES (7, 'ПР-0007', 'Демо проект', 'office', 'request', '2026-10-01 10:00+05', '2026-10-01 10:00+05');
            INSERT INTO project_files (project_id, side, file_name, storage_path, mime, size, created_at, updated_at)
                VALUES (7, 'designer', 'plan.txt', 'projects/7/h24_plan.txt', 'text/plain', 9, '2026-10-01 10:00+05', '2026-10-01 10:00+05');""")
        files = self.storage({'private/projects/7/h24_plan.txt': b'demo plan'})

        copy = self.backup(db, files)
        manifest = json.loads((copy / 'manifest.json').read_text())
        views = (copy / 'views.tsv').read_text().splitlines()
        self.assertEqual(manifest['views']['answers'], len(views))
        self.assertTrue(any(line.endswith('\t200\t' + hashlib.sha256(b'demo plan').hexdigest()) and '\tfile:' in line for line in views), 'the file as the CRM serves it')
        self.assertTrue(any(line.startswith('guest\t/login\t200\t') for line in views))

        check, result = self.restore_check(copy)
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
        self.assertEqual([result['status'], result['app']['status'], result['files']['restored']], ['PASS', 'PASS', 1])
        self.assertIn('answers match', check.stdout)

        # The application serves what the restored files hold: a forged file is a different answer.
        self.retar(copy, {'private/projects/7/h24_plan.txt': b'demo PLAN'})
        check, result = self.restore_check(copy)
        self.assertNotEqual(check.returncode, 0)
        self.assertEqual([result['status'], result['app']['status']], ['FAIL', 'FAIL'])
        self.assertTrue(any(': content differs' in p and '\tfile:' in p for p in result['app']['problems']), result['app']['problems'])


if __name__ == '__main__':
    unittest.main()
