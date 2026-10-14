#!/usr/bin/env bash
# make db-backup — a copy of the dev database: pg_dump (custom format) from the db container and a manifest with the app
# commit, the PostgreSQL version, the SHA-256 of the dump and the fingerprint of every table, sequence and of the schema.
# Stored in .local-audit/backups/<UTC time>-<commit>/ — git-ignored, readable by the owner only: the dev database holds the
# read-only copy of the real legacy data.
# Usage: scripts/db-backup.sh [--db NAME] [--out DIR]
source "$(dirname "$0")/lib.sh"
require_docker
require_env

db="" out=""
while [ $# -gt 0 ]; do
    case "$1" in
        --db) db="$2"; shift 2 ;;
        --out) out="$2"; shift 2 ;;
        *) fail "unknown argument: $1" ;;
    esac
done

dc up -d --wait db >/dev/null
user="$(dc exec -T db printenv POSTGRES_USER | tr -d '\r')"
[ -n "$db" ] || db="$(dc exec -T db printenv POSTGRES_DB | tr -d '\r')"
commit="$(git rev-parse --short=10 HEAD 2>/dev/null || echo unknown)"
[ -n "$out" ] || out=".local-audit/backups/$(date -u +%Y%m%dT%H%M%SZ)-${commit}"
umask 077
mkdir -p "$out"
chmod 700 "$out"

fingerprint() { dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$user" -d "$db" -At -F $'\t' < scripts/sql/db-fingerprint.sql | tr -d '\r'; }
sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }

# pg_dump reads one snapshot; the fingerprint is taken before and after it and must not change, otherwise the database was
# written to during the copy and it is taken again.
for attempt in 1 2 3; do
    info "Dump of database ${db} (attempt ${attempt})"
    fingerprint > "$out/fingerprint.tsv"
    dc exec -T db pg_dump -U "$user" -d "$db" -Fc --no-owner --no-privileges > "$out/db.dump"
    if fingerprint | cmp -s - "$out/fingerprint.tsv"; then
        break
    fi
    [ "$attempt" -lt 3 ] || fail "the database kept changing during the dump; stop writing to it and repeat"
    warn "the database changed during the dump — repeating"
done

version="$(dc exec -T db psql -X -U "$user" -d "$db" -At -c 'SHOW server_version' | tr -d '\r')"
python3 - "$out" "$db" "$commit" "$version" "$(sha256 "$out/db.dump")" "$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)" "$(git status --porcelain 2>/dev/null | head -c1 | wc -c | tr -d ' ')" <<'PY'
import datetime, json, os, sys
out, db, commit, version, digest, branch, dirty = sys.argv[1:8]
lines = [l.split('\t') for l in open(os.path.join(out, 'fingerprint.tsv'), encoding='utf-8').read().splitlines() if l]
tables = [l for l in lines if l[0].startswith('table:')]
manifest = {
    'schema': 'promtech.db-backup/1',
    'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'),
    'database': db,
    'app': {'commit': commit, 'branch': branch, 'dirty': dirty != '0'},
    'postgres': {'server_version': version},
    'dump': {'file': 'db.dump', 'format': 'custom', 'bytes': os.path.getsize(os.path.join(out, 'db.dump')), 'sha256': digest},
    'fingerprint': {'file': 'fingerprint.tsv', 'tables': len(tables), 'rows': sum(int(l[1]) for l in tables),
                    'sequences': sum(1 for l in lines if l[0].startswith('sequence:')), 'schema_md5': next(l[2] for l in lines if l[0] == 'schema')},
}
with open(os.path.join(out, 'manifest.json'), 'w', encoding='utf-8') as f:
    json.dump(manifest, f, ensure_ascii=False, indent=1)
print(f"{manifest['fingerprint']['tables']} tables, {manifest['fingerprint']['rows']} rows, dump {manifest['dump']['bytes']} bytes, sha256 {digest[:16]}…")
PY
chmod 600 "$out"/*
ok "backup: $out"
