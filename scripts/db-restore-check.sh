#!/usr/bin/env bash
# make db-restore-check — restores a copy made by db-backup into a separate, temporary PostgreSQL container of the same image
# (the dev database is not touched) and compares the schema, every table's rows and checksum and every sequence with the
# manifest. Non-zero exit on any difference.
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
expected="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["dump"]["sha256"])' "$dir/manifest.json")"
[ "$(sha256 "$dir/db.dump")" = "$expected" ] || fail "dump file does not match its SHA-256 in the manifest"
ok "dump SHA-256 matches the manifest"

image="$(dc config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["db"]["image"])')"
name="${COMPOSE_PROJECT_NAME:-promtech-erp}-restore-check-$$"
password="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT
info "Temporary PostgreSQL ${image} (${name}, no network, data in memory)"
docker run -d --rm --name "$name" --network none --tmpfs /var/lib/postgresql/data \
    -e POSTGRES_USER=restore -e POSTGRES_DB=restore_check -e POSTGRES_PASSWORD="$password" "$image" >/dev/null
for _ in $(seq 1 120); do
    if docker logs "$name" 2>&1 | grep -q 'PostgreSQL init process complete' && docker exec "$name" pg_isready -U restore -d restore_check >/dev/null 2>&1; then
        break
    fi
    sleep 0.5
done
docker exec "$name" pg_isready -U restore -d restore_check >/dev/null 2>&1 || fail "temporary PostgreSQL did not start"

info "Restore into the temporary database"
docker exec -i "$name" pg_restore -U restore -d restore_check --no-owner --no-privileges --exit-on-error < "$dir/db.dump"
docker exec -i "$name" psql -X -q -v ON_ERROR_STOP=1 -U restore -d restore_check -At -F $'\t' < scripts/sql/db-fingerprint.sql | tr -d '\r' > "$dir/restored.tsv"

python3 - "$dir" <<'PY'
import datetime, json, os, sys
d = sys.argv[1]
read = lambda f: {l.split('\t')[0]: l.split('\t')[1:] for l in open(os.path.join(d, f), encoding='utf-8').read().splitlines() if l}
src, dst = read('fingerprint.tsv'), read('restored.tsv')
problems = [f'{k}: missing after restore' for k in src if k not in dst] + [f'{k}: not in the backup' for k in dst if k not in src]
problems += [f'{k}: {src[k][0]} → {dst[k][0]}' + (' (checksum differs)' if src[k][1] != dst[k][1] else '') for k in src if k in dst and src[k] != dst[k]]
tables = [k for k in src if k.startswith('table:')]
result = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'), 'status': 'FAIL' if problems else 'PASS',
          'tables': len(tables), 'rows': sum(int(src[k][0]) for k in tables), 'sequences': sum(1 for k in src if k.startswith('sequence:')),
          'schema': 'same' if src.get('schema') == dst.get('schema') else 'differs', 'problems': problems[:50]}
with open(os.path.join(d, 'restore-check.json'), 'w', encoding='utf-8') as f:
    json.dump(result, f, ensure_ascii=False, indent=1)
os.chmod(os.path.join(d, 'restore-check.json'), 0o600)
print(f"schema {result['schema']}; {result['tables']} tables, {result['rows']} rows, {result['sequences']} sequences compared")
for p in problems[:20]:
    print('  ' + p)
sys.exit(1 if problems else 0)
PY
ok "restore-check passed: $dir"
