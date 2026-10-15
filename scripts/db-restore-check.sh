#!/usr/bin/env bash
# make db-restore-check — restores a copy made by db-backup in isolation and checks it (the dev database and files are not
# touched):
#   1. the SHA-256 of the dump and of the files archive against the manifest, before anything is restored;
#   2. the database into a temporary PostgreSQL container of the same image (data in memory, no published port): schema,
#      every table's rows and checksum and every sequence against the manifest;
#   3. the files into a temporary directory: every file against its SHA-256 in the list, no file more or less; every
#      project_files row of the restored database has its file of the same size (as many missing as at the copy);
#   4. the application on the copy (when the copy recorded it): a one-off container of the app image, connected only to the
#      restored database and files, opens every page and file for every user and must answer exactly as recorded at the copy
#      (php artisan app:view-digest) — links, sums and access as the users see them. Needs the code of the copy's commit.
# Result: restore-check.json next to the copy; non-zero exit on any difference.
# Usage: scripts/db-restore-check.sh [BACKUP_DIR]   (default: the latest in .local-audit/backups)
source "$(dirname "$0")/lib.sh"
require_docker
require_env

dir="${1:-}"
if [ -z "$dir" ]; then
    dir="$(ls -1d .local-audit/backups/*/ 2>/dev/null | sort | tail -n1)"
    dir="${dir%/}"
fi
[ -n "$dir" ] && [ -f "$dir/manifest.json" ] || fail "no backup found (make db-backup first)"
sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
field() { # field KEY… — a value of the manifest, empty when absent
    python3 -c 'import json, sys
v = json.load(open(sys.argv[1]))
for k in sys.argv[2:]:
    v = (v or {}).get(k)
print("" if v is None else v)' "$dir/manifest.json" "$@"
}
[ "$(sha256 "$dir/db.dump")" = "$(field dump sha256)" ] || fail "dump file does not match its SHA-256 in the manifest"
ok "dump SHA-256 matches the manifest"
has_files=false
if [ -n "$(field files archive)" ]; then
    has_files=true
    [ -f "$dir/files.tar" ] && [ "$(sha256 "$dir/files.tar")" = "$(field files sha256)" ] || fail "files archive does not match its SHA-256 in the manifest"
    ok "files archive SHA-256 matches the manifest"
fi
at="$(field views at)"
[ -z "$at" ] || [ -f "$dir/views.tsv" ] || fail "the manifest records the application's answers, but views.tsv is missing"

image="$(dc config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["db"]["image"])')"
name="${COMPOSE_PROJECT_NAME:-promtech-erp}-restore-check-$$"
password="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
work="$(mktemp -d "${TMPDIR:-/tmp}/promtech-restore.XXXXXX")"
chmod 700 "$work"
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT
# The application check needs the copy on the project's network (no published port); otherwise no network at all.
network=none
if [ -n "$at" ]; then
    dc up -d --wait db >/dev/null
    network="$(dc config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["networks"]["default"]["name"])')"
fi
info "Temporary PostgreSQL ${image} (${name}, network ${network}, data in memory)"
docker run -d --rm --name "$name" --network "$network" --tmpfs /var/lib/postgresql/data \
    -e POSTGRES_USER=restore -e POSTGRES_DB=restore_check -e POSTGRES_PASSWORD="$password" "$image" >/dev/null
for _ in $(seq 1 120); do
    if docker logs "$name" 2>&1 | grep -q 'PostgreSQL init process complete' && docker exec "$name" pg_isready -U restore -d restore_check >/dev/null 2>&1; then
        break
    fi
    sleep 0.5
done
docker exec "$name" pg_isready -U restore -d restore_check >/dev/null 2>&1 || fail "temporary PostgreSQL did not start"
restored() { docker exec -i "$name" psql -X -q -v ON_ERROR_STOP=1 -U restore -d restore_check -At -F $'\t' "$@" | tr -d '\r'; }

info "Restore the database into the temporary PostgreSQL"
docker exec -i "$name" pg_restore -U restore -d restore_check --no-owner --no-privileges --exit-on-error < "$dir/db.dump"
restored < scripts/sql/db-fingerprint.sql > "$dir/restored.tsv"
chmod 600 "$dir/restored.tsv"
# pg_restore does not collect planner statistics; a restore runs ANALYZE before the application uses the database.
restored -c 'ANALYZE'

echo '{}' > "$work/files.json"
echo '{}' > "$work/links.json"
if $has_files; then
    info "Restore the files into a temporary directory"
    mkdir -m 700 "$work/files"
    python3 scripts/backup_files.py verify "$dir/files.tar" "$dir/files.tsv" "$work/files" > "$work/files.json" || true
    : > "$work/refs.tsv"
    if [ "$(restored -c "SELECT to_regclass('public.project_files') IS NOT NULL")" = "t" ]; then
        restored -c 'COPY (SELECT storage_path, size FROM project_files ORDER BY storage_path) TO STDOUT' > "$work/refs.tsv"
    fi
    python3 scripts/backup_files.py links "$dir/files.tsv" "$work/refs.tsv" > "$work/links.json"
fi

app_status=SKIPPED app_note="the copy has no recorded answers of the application"
if [ -n "$at" ]; then
    copy_commit="$(field app commit)"
    head_commit="$(git rev-parse --short=10 HEAD 2>/dev/null || echo unknown)"
    if [ "$copy_commit" != "$head_commit" ]; then
        app_note="the copy was made by ${copy_commit}, the code is ${head_commit}: check out ${copy_commit} to compare the application"
        warn "application check skipped: ${app_note}"
    else
        info "Application on the restored copy (one-off app container: restored database and files only)"
        mkdir -p -m 700 "$work/files"
        if dc run --rm --no-deps -T -e DB_HOST="$name" -e DB_PORT=5432 -e DB_DATABASE=restore_check -e DB_USERNAME=restore \
            -e DB_PASSWORD="$password" -v "$(cd "$work" && pwd)/files:/var/www/html/storage/app:ro" \
            app php -d display_errors=stderr artisan app:view-digest --at="$at" --compare=- < "$dir/views.tsv" > "$work/app.log" 2>&1; then
            app_status=PASS
        else
            app_status=FAIL
        fi
        app_note="$(grep -v -e '^ *Container ' -e '^time=' "$work/app.log" | tail -n 40)"
        printf '%s\n' "$app_note" | sed 's/^/  /'
    fi
fi

python3 - "$dir" "$work" "$app_status" "$app_note" <<'PY'
import datetime, json, os, sys
d, work, app_status, app_note = sys.argv[1:5]
manifest = json.load(open(os.path.join(d, 'manifest.json'), encoding='utf-8'))
read = lambda f: {l.split('\t')[0]: l.split('\t')[1:] for l in open(os.path.join(d, f), encoding='utf-8').read().splitlines() if l}
src, dst = read('fingerprint.tsv'), read('restored.tsv')
problems = [f'{k}: missing after restore' for k in src if k not in dst] + [f'{k}: not in the backup' for k in dst if k not in src]
problems += [f'{k}: {src[k][0]} → {dst[k][0]}' + (' (checksum differs)' if src[k][1] != dst[k][1] else '') for k in src if k in dst and src[k] != dst[k]]
files, links = json.load(open(os.path.join(work, 'files.json'))), json.load(open(os.path.join(work, 'links.json')))
private = []  # file paths may be personal: only in the owner-only result, not on the console
if manifest.get('files'):
    private += files.get('problems', [])
    if files.get('problems'):
        problems.append(f"files: {len(files['problems'])} problem(s) in the archive (paths in restore-check.json)")
    was = manifest['files'].get('links', {})
    for key in ('missing', 'size_differs'):
        if sorted(links.get(key, [])) != sorted(was.get(key, [])):
            problems.append(f"project_files: {len(links.get(key, []))} {key.replace('_', ' ')} after restore, {len(was.get(key, []))} at the copy")
    if links.get('referenced') != was.get('referenced'):
        problems.append(f"project_files: {links.get('referenced')} rows after restore, {was.get('referenced')} at the copy")
if app_status == 'FAIL':
    problems.append('application: answers differ from the copy (see app.problems)')
tables = [k for k in src if k.startswith('table:')]
result = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'), 'status': 'FAIL' if problems else 'PASS',
          'tables': len(tables), 'rows': sum(int(src[k][0]) for k in tables), 'sequences': sum(1 for k in src if k.startswith('sequence:')),
          'schema': 'same' if src.get('schema') == dst.get('schema') else 'differs', 'problems': problems[:50],
          'files': None if not manifest.get('files') else {'restored': files.get('files'), 'bytes': files.get('bytes'), 'problems': private[:50],
                    'project_files': links.get('referenced'), 'missing_at_copy': len(manifest['files'].get('links', {}).get('missing', []))},
          'app': {'status': app_status, 'problems': app_note.splitlines()[-40:]}}
with open(os.path.join(d, 'restore-check.json'), 'w', encoding='utf-8') as f:
    json.dump(result, f, ensure_ascii=False, indent=1)
os.chmod(os.path.join(d, 'restore-check.json'), 0o600)
print(f"schema {result['schema']}; {result['tables']} tables, {result['rows']} rows, {result['sequences']} sequences compared"
      + (f"; files {files.get('files')} restored, project_files {links.get('referenced')}" if result['files'] else '') + f"; application {app_status}")
if result['files'] and result['files']['missing_at_copy']:
    print(f"  WARNING: {result['files']['missing_at_copy']} project file(s) had no file already at the copy")
for p in problems[:20]:
    print('  ' + p)
sys.exit(1 if problems else 0)
PY
ok "restore-check passed: $dir"
