#!/usr/bin/env bash
# make db-backup — a copy of the application data: the database (pg_dump, custom format) and the uploaded files
# (storage/app: project files of the CRM), with a manifest — app commit, PostgreSQL version, SHA-256 of the dump and of the
# files archive, the fingerprint of every table, sequence and of the schema, the list of files with their SHA-256 and the
# check that every project_files row has its file. When the database is the application's, it also records what the
# application shows every user (php artisan app:view-digest, checksums only) — db-restore-check compares that on the copy.
# Stored in .local-audit/backups/<UTC time>-<commit>/ — git-ignored, readable by the owner only: the dev database holds the
# read-only copy of the real legacy data.
# Usage: scripts/db-backup.sh [--db NAME] [--files DIR] [--out DIR]
source "$(dirname "$0")/lib.sh"
require_docker
require_env

db="" out="" files="storage/app"
while [ $# -gt 0 ]; do
    case "$1" in
        --db) db="$2"; shift 2 ;;
        --files) files="$2"; shift 2 ;;
        --out) out="$2"; shift 2 ;;
        *) fail "unknown argument: $1" ;;
    esac
done

[ -d "$files" ] || fail "files directory not found: ${files}"
files_abs="$(cd "$files" && pwd)"
dc up -d --wait db >/dev/null
user="$(dc exec -T db printenv POSTGRES_USER | tr -d '\r')"
[ -n "$db" ] || db="$(dc exec -T db printenv POSTGRES_DB | tr -d '\r')"
commit="$(git rev-parse --short=10 HEAD 2>/dev/null || echo unknown)"
[ -n "$out" ] || out=".local-audit/backups/$(date -u +%Y%m%dT%H%M%SZ)-${commit}"
at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
umask 077
mkdir -p "$out"
chmod 700 "$out"
work="$(mktemp -d "${TMPDIR:-/tmp}/promtech-backup-work.XXXXXX")"
trap 'rm -rf "$work"' EXIT

psql_db() { dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$user" -d "$db" -At -F $'\t' "$@" | tr -d '\r'; }
fingerprint() { psql_db < scripts/sql/db-fingerprint.sql; }
sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
has_table() { [ "$(psql_db -c "SELECT to_regclass('public.$1') IS NOT NULL")" = "t" ]; }
app_db=false
if has_table users && has_table migrations; then app_db=true; fi
refs=false
if has_table project_files; then refs=true; fi

# pg_dump reads one snapshot; the fingerprint of the database and the state of the files are taken before and after the
# copy and must not change, otherwise something was written during the copy and it is taken again. The files, the links
# of project_files and the answers of the application are taken inside the same window.
for attempt in 1 2 3; do
    info "Copy of database ${db} and of ${files} (attempt ${attempt})"
    fingerprint > "$out/fingerprint.tsv"
    python3 scripts/backup_files.py state "$files" > "$work/files-before"
    dc exec -T db pg_dump -U "$user" -d "$db" -Fc --no-owner --no-privileges > "$out/db.dump"
    python3 scripts/backup_files.py archive "$files" "$out/files.tar" "$out/files.tsv" > "$work/files.json" \
        || fail "files not copied: $(cat "$work/files.json")"
    : > "$work/refs.tsv"
    if $refs; then
        psql_db -c 'COPY (SELECT storage_path, size FROM project_files ORDER BY storage_path) TO STDOUT' > "$work/refs.tsv"
    fi
    python3 scripts/backup_files.py links "$out/files.tsv" "$work/refs.tsv" > "$work/links.json"
    if $app_db; then
        # the application reads exactly the files being copied (read-only)
        dc run --rm --no-deps -T -e DB_DATABASE="$db" -v "${files_abs}:/var/www/html/storage/app:ro" \
            app php -d display_errors=stderr artisan app:view-digest --at="$at" \
            > "$out/views.tsv" 2> "$work/views.log" || { cat "$work/views.log" >&2; fail "the application did not answer on ${db}"; }
    fi
    if fingerprint | cmp -s - "$out/fingerprint.tsv" && python3 scripts/backup_files.py state "$files" | cmp -s - "$work/files-before"; then
        break
    fi
    [ "$attempt" -lt 3 ] || fail "the database or the files kept changing during the copy; stop writing to them and repeat"
    warn "the database or the files changed during the copy — repeating"
done
$app_db || rm -f "$out/views.tsv"

version="$(psql_db -c 'SHOW server_version')"
python3 - "$out" "$db" "$commit" "$version" "$(sha256 "$out/db.dump")" "$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)" \
    "$(git status --porcelain 2>/dev/null | head -c1 | wc -c | tr -d ' ')" "$files" "$(sha256 "$out/files.tar")" "$work" "$at" "$app_db" <<'PY'
import datetime, json, os, sys
out, db, commit, version, digest, branch, dirty, files_root, files_digest, work, at, app_db = sys.argv[1:13]
lines = [l.split('\t') for l in open(os.path.join(out, 'fingerprint.tsv'), encoding='utf-8').read().splitlines() if l]
tables = [l for l in lines if l[0].startswith('table:')]
files = json.load(open(os.path.join(work, 'files.json')))
links = json.load(open(os.path.join(work, 'links.json')))
views = sum(1 for l in open(os.path.join(out, 'views.tsv'), encoding='utf-8') if l.strip()) if app_db == 'true' else None
manifest = {
    'schema': 'promtech.db-backup/2',
    'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'),
    'database': db,
    'app': {'commit': commit, 'branch': branch, 'dirty': dirty != '0'},
    'postgres': {'server_version': version},
    'dump': {'file': 'db.dump', 'format': 'custom', 'bytes': os.path.getsize(os.path.join(out, 'db.dump')), 'sha256': digest},
    'fingerprint': {'file': 'fingerprint.tsv', 'tables': len(tables), 'rows': sum(int(l[1]) for l in tables),
                    'sequences': sum(1 for l in lines if l[0].startswith('sequence:')), 'schema_md5': next(l[2] for l in lines if l[0] == 'schema')},
    # paths of missing files stay here (owner-only): file names may be personal
    'files': {'root': files_root, 'archive': 'files.tar', 'list': 'files.tsv', 'count': files['count'], 'bytes': files['bytes'],
              'sha256': files_digest, 'links': links},
    'views': None if views is None else {'file': 'views.tsv', 'at': at, 'answers': views},
}
with open(os.path.join(out, 'manifest.json'), 'w', encoding='utf-8') as f:
    json.dump(manifest, f, ensure_ascii=False, indent=1)
print(f"{manifest['fingerprint']['tables']} tables, {manifest['fingerprint']['rows']} rows, dump {manifest['dump']['bytes']} bytes, sha256 {digest[:16]}…; "
      f"files {files['count']} ({files['bytes']} bytes), project_files {links['referenced']}"
      + (f"; application: {views} answers recorded" if views is not None else ''))
if links['missing'] or links['size_differs']:
    print(f"WARNING: {len(links['missing'])} project file(s) have no file and {len(links['size_differs'])} a file of another size already in the database (see manifest.json)")
PY
chmod 600 "$out"/*
ok "backup: $out"
