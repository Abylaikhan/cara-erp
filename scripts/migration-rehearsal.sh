#!/usr/bin/env bash
# make migration-rehearsal — rehearsal of the legacy migration (ERP-069, ТЗ MIG) on a fresh, temporary database next to the
# dev one (the dev database is only read: its schema `legacy` with the loaded read-only snapshot is copied over):
#   1. empty database → all migrations (reference channels and warehouses come with them; no demo seeder);
#   2. legacy:references — the references of the snapshot the import cannot resolve or resolve uniquely, and its rule;
#      legacy:import all --dry-run — the whole import rolled back: the business tables must stay as the migrations left them;
#   3. every legacy:import in dependency order, timed (each importer validates the whole set and compares row by row; a
#      refused set leaves every problem in errors-<entity>.txt);
#   4. ANALYZE, legacy:control-totals — counts and sums by period, legacy snapshot against the application tables;
#   5. the same imports again: the business tables must not change (fingerprint before and after; only the audit trail and
#      the import times of legacy_id_map may);
#   6. the temporary database is dropped (KEEP=1 keeps it for inspection).
# Report and details with sums: .local-audit/rehearsal/<UTC time>/ (git-ignored, owner-only). Non-zero exit on any failure.
# Usage: scripts/migration-rehearsal.sh [--snapshot ID]
source "$(dirname "$0")/lib.sh"
require_docker
require_env

snapshot=""
while [ $# -gt 0 ]; do
    case "$1" in
        --snapshot) snapshot="$2"; shift 2 ;;
        *) fail "unknown argument: $1" ;;
    esac
done

# The order of legacy:import (its IMPORTERS list): each importer finds what it refers to through legacy_id_map.
ENTITIES="expense_categories products customers suppliers warehouse material_aliases incoming_invoices leads tasks material_requests orders realizations production_progress money projects salon_worksheet bank_statements staff salary_accruals wage_items salon_payroll"

dc up -d --wait db app >/dev/null
user="$(dc exec -T db printenv POSTGRES_USER | tr -d '\r')"
devdb="$(dc exec -T db printenv POSTGRES_DB | tr -d '\r')"
[ -n "$snapshot" ] || snapshot="$(dc exec -T db psql -X -U "$user" -d "$devdb" -At -c 'SELECT max(id) FROM legacy.snapshots' | tr -d '\r')"
[ -n "$snapshot" ] || fail "no loaded legacy snapshot (make legacy-copy first)"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
db="promtech_rehearsal_$(date -u +%Y%m%d%H%M%S)"
out=".local-audit/rehearsal/${stamp}"
umask 077
mkdir -p "$out"
chmod 700 "$out"

psql_admin() { dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$user" -d "$devdb" "$@"; }
artisan() { dc exec -T -e DB_DATABASE="$db" app php artisan "$@"; }
now_ms() { python3 -c 'import time; print(int(time.time() * 1000))'; }
# Fingerprint of the business tables: the audit trail grows with every import and legacy_id_map keeps the time of the last one.
business_fingerprint() {
    dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$user" -d "$db" -At -F $'\t' < scripts/sql/db-fingerprint.sql | tr -d '\r' \
        | grep -v -e '^table:public\.audit_events	' -e '^table:public\.legacy_id_map	' -e '^sequence:public\.audit_events_id_seq	' \
            -e '^sequence:public\.legacy_id_map_id_seq	' -e '^table:public\.\(sessions\|cache\|cache_locks\|jobs\|failed_jobs\|job_batches\)	'
}
# Every table, the audit trail and legacy_id_map included: a dry run must leave no trace at all.
table_fingerprint() {
    dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$user" -d "$db" -At -F $'\t' < scripts/sql/db-fingerprint.sql | tr -d '\r' | grep '^table:'
}
cleanup() {
    if [ "${KEEP:-0}" = "1" ]; then
        warn "KEEP=1: the database ${db} is kept (drop it with: DROP DATABASE ${db})"
    else
        psql_admin -c "DROP DATABASE IF EXISTS ${db} WITH (FORCE)" >/dev/null 2>&1 || warn "could not drop ${db}"
    fi
}
trap cleanup EXIT

steps="$out/steps.tsv"
: > "$steps"
step() { # step NAME COMMAND… — runs, times and logs one step; the output goes to the log only (it may name real records)
    local name="$1" start end; shift
    start="$(now_ms)"
    if ! "$@" >> "$out/rehearsal.log" 2>&1; then
        printf '%s\t%s\tFAIL\n' "$name" "$(( $(now_ms) - start ))" >> "$steps"
        fail "${name} failed — see ${out}/rehearsal.log"
    fi
    end="$(now_ms)"
    printf '%s\t%s\tOK\n' "$name" "$(( end - start ))" >> "$steps"
    ok "${name} ($(( end - start )) ms)"
}

info "Temporary database ${db}, legacy snapshot ${snapshot}"
step "create database" psql_admin -c "CREATE DATABASE ${db}"
step "copy legacy snapshot" bash -c "set -o pipefail; docker compose exec -T db pg_dump -U '$user' -d '$devdb' -n legacy --no-owner --no-privileges \
    | docker compose exec -T db psql -X -q -v ON_ERROR_STOP=1 -U '$user' -d '$db'"
step "migrate" artisan migrate --force
step "references" artisan legacy:references --snapshot="$snapshot" --out="$out/references.json"
table_fingerprint > "$out/fingerprint-0.tsv"
step "dry run" artisan legacy:import all --dry-run --snapshot="$snapshot"
table_fingerprint | cmp -s - "$out/fingerprint-0.tsv" || fail "the dry run left rows behind"
ok "the dry run wrote nothing"
for e in $ENTITIES; do
    step "import ${e}" artisan legacy:import "$e" --snapshot="$snapshot" --errors="/var/www/html/${out}/errors-${e}.txt"
done
# A bulk load leaves the planner without statistics until autovacuum comes; the cut-over runs ANALYZE before the users do.
step "analyze" dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$user" -d "$db" -c 'ANALYZE'
step "control totals" artisan legacy:control-totals --snapshot="$snapshot" --out="$out/control-totals.json"
business_fingerprint > "$out/fingerprint-1.tsv"
for e in $ENTITIES; do
    step "repeat ${e}" artisan legacy:import "$e" --snapshot="$snapshot"
done
business_fingerprint > "$out/fingerprint-2.tsv"
if cmp -s "$out/fingerprint-1.tsv" "$out/fingerprint-2.tsv"; then
    idempotent=true
    ok "repeated import changed nothing"
else
    idempotent=false
    warn "repeated import changed: $(diff "$out/fingerprint-1.tsv" "$out/fingerprint-2.tsv" | grep '^>' | cut -f1 | tr '\n' ' ')"
fi

python3 - "$out" "$snapshot" "$(git rev-parse --short=10 HEAD 2>/dev/null || echo unknown)" "$idempotent" <<'PY'
import json, os, sys
out, snapshot, commit, idempotent = sys.argv[1:5]
steps = [dict(zip(('step', 'ms', 'status'), l.split('\t'))) for l in open(os.path.join(out, 'steps.tsv'), encoding='utf-8').read().splitlines() if l]
totals = json.load(open(os.path.join(out, 'control-totals.json'), encoding='utf-8'))
references = json.load(open(os.path.join(out, 'references.json'), encoding='utf-8'))
report = {
    'schema': 'promtech.migration-rehearsal/1', 'snapshot': snapshot, 'app_commit': commit,
    'first_run_ms': sum(int(s['ms']) for s in steps if s['step'] == 'migrate' or s['step'].startswith('import ')),
    'repeat_run_ms': sum(int(s['ms']) for s in steps if s['step'].startswith('repeat ')),
    'idempotent': idempotent == 'true',
    'modules': [{k: m[k] for k in ('module', 'tables', 'status', 'legacy_rows', 'target_rows', 'periods')} for m in totals['modules']],
    'not_migrated': totals['not_migrated'],
    'references': [{k: r[k] for k in ('name', 'references', 'resolved', 'unresolved', 'ambiguous', 'rule')} for r in references['references']],
    'repeats': [{k: r[k] for k in ('name', 'values', 'rows', 'rule')} for r in references['repeats']],
    'dry_run': 'wrote nothing',
    'steps': steps,
}
report['status'] = 'PASS' if report['idempotent'] and all(m['status'] == 'MATCH' for m in report['modules']) \
    and all(not t['reason'].startswith('НЕ ОБЪЯСНЕНО') for t in report['not_migrated']) else 'FAIL'
with open(os.path.join(out, 'report.json'), 'w', encoding='utf-8') as f:
    json.dump(report, f, ensure_ascii=False, indent=1)
os.chmod(os.path.join(out, 'report.json'), 0o600)
print(f"{report['status']}: {sum(m['status'] == 'MATCH' for m in report['modules'])}/{len(report['modules'])} modules match, "
      f"{sum(m['legacy_rows'] for m in report['modules'])} legacy records, first run {report['first_run_ms'] / 1000:.0f} s, "
      f"repeat {report['repeat_run_ms'] / 1000:.0f} s, idempotent: {report['idempotent']}")
sys.exit(0 if report['status'] == 'PASS' else 1)
PY
chmod 600 "$out"/*
ok "migration rehearsal passed: ${out}/report.json"
