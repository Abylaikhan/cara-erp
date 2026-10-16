#!/usr/bin/env bash
# make e2e — browser acceptance scenarios (tests/e2e/acceptance_*.py) on a throwaway database with the synthetic seed only,
# served by a separate instance of the application (PHP's built-in server with Laravel's router, 127.0.0.1:E2E_PORT).
# Not «php artisan serve»: it drops the process environment and rereads .env, so the instance would use the dev database.
# Two guards: the instance must show the marker of its environment before any scenario runs, and the dev database (the copy
# of the real legacy data) must have exactly the same fingerprint after the run. The throwaway database is dropped at the
# end (KEEP=1 keeps it). E2E_SEED=users seeds the users only: a database without business data, for the empty states.
# Needs host Python with Playwright and Chrome, as make visual-compare. Usage: scripts/e2e.sh [scenario file …]
source "$(dirname "$0")/lib.sh"
require_docker
require_env

port="${E2E_PORT:-8091}"
dc up -d --wait db app >/dev/null
user="$(dc exec -T db printenv POSTGRES_USER | tr -d '\r')"
devdb="$(dc exec -T db printenv POSTGRES_DB | tr -d '\r')"
db="promtech_e2e_$(date -u +%Y%m%d%H%M%S)"
name="${COMPOSE_PROJECT_NAME:-promtech-erp}-e2e-$$"
psql_admin() { dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$user" -d "$devdb" "$@"; }
# The dev database without the tables of sessions, cache and queue (a browser on the dev application may touch those).
dev_fingerprint() {
    dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$user" -d "$devdb" -At -F $'\t' < scripts/sql/db-fingerprint.sql | tr -d '\r' \
        | grep -v -e '^table:public\.\(sessions\|cache\|cache_locks\|jobs\|failed_jobs\|job_batches\)	' -e '^sequence:'
}
cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    if [ "${KEEP:-0}" = "1" ]; then
        warn "KEEP=1: the database ${db} is kept"
    else
        psql_admin -c "DROP DATABASE IF EXISTS ${db} WITH (FORCE)" >/dev/null 2>&1 || warn "could not drop ${db}"
    fi
}
trap cleanup EXIT

seeder=DatabaseSeeder
[ "${E2E_SEED:-full}" = "users" ] && seeder=UsersSeeder
info "Throwaway database ${db}: migrations and the synthetic seed (${seeder})"
psql_admin -c "CREATE DATABASE ${db}"
dc run --rm --no-deps -T -e DB_DATABASE="$db" app php artisan migrate --force >/dev/null
dc run --rm --no-deps -T -e DB_DATABASE="$db" app php artisan db:seed --force --class="Database\\Seeders\\${seeder}" >/dev/null
info "Application on http://127.0.0.1:${port} (its own process, the throwaway database only)"
marker="PromTech ERP E2E ${db}"
dc run -d --no-deps --name "$name" -p "127.0.0.1:${port}:${port}" -w /var/www/html/public -e DB_DATABASE="$db" -e APP_URL="http://127.0.0.1:${port}" \
    -e APP_NAME="$marker" app php -S "0.0.0.0:${port}" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >/dev/null
for _ in $(seq 1 60); do
    curl -fs "http://127.0.0.1:${port}/up" >/dev/null 2>&1 && break
    sleep 0.5
done
curl -fs "http://127.0.0.1:${port}/up" >/dev/null || fail "the application did not start"
curl -fs "http://127.0.0.1:${port}/login" | grep -q "$marker" || fail "the instance does not use its own environment (the throwaway database): nothing is run"
ok "the instance runs with the environment of ${db}"
dev_fingerprint > "${TMPDIR:-/tmp}/promtech-e2e-dev-$$.tsv"

status=0
scenarios=("$@")
[ ${#scenarios[@]} -gt 0 ] || scenarios=(tests/e2e/acceptance_*.py)
for s in "${scenarios[@]}"; do
    info "Scenarios: ${s}"
    E2E_BASE="http://127.0.0.1:${port}" E2E_DB="$db" "${PYTHON:-python3}" "$s" || status=1
done
if ! dev_fingerprint | cmp -s - "${TMPDIR:-/tmp}/promtech-e2e-dev-$$.tsv"; then
    rm -f "${TMPDIR:-/tmp}/promtech-e2e-dev-$$.tsv"
    fail "THE DEV DATABASE CHANGED during the run — the scenarios must touch only ${db}"
fi
rm -f "${TMPDIR:-/tmp}/promtech-e2e-dev-$$.tsv"
ok "the dev database is unchanged"
[ "$status" -eq 0 ] && ok "browser acceptance scenarios passed" || fail "browser acceptance scenarios failed"
