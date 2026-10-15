#!/usr/bin/env bash
# make check — code style, types, build, backend tests on PostgreSQL. Non-zero exit on failure.
# Usage: scripts/check.sh [step ...]
#   default steps: pint types build tests dbcheck
#   extra steps (used by the audit harness on its isolated project): deps migrate backup http
# One source of truth for check commands: `make check` and tools/harness both call these steps.
source "$(dirname "$0")/lib.sh"
require_docker
require_env

step_deps() {
    info "Dependencies from lock files (composer.lock, package-lock.json)"
    dc build app
    dc run --rm --no-deps app composer install --no-interaction --prefer-dist
    dc run --rm --no-deps node npm ci --no-audit --no-fund
}
step_pint() {
    info "PHP code style (pint --test)"
    dc run --rm --no-deps app vendor/bin/pint --test
}
step_types() {
    info "TypeScript / Vue types (vue-tsc)"
    dc run --rm --no-deps node npm run typecheck
}
step_build() {
    info "Production build (vite build)"
    dc run --rm --no-deps node npm run build
}
step_tests() {
    info "Backend tests on isolated PostgreSQL (db-test)"
    dc up -d --wait db db-test
    if [ -n "${JUNIT:-}" ]; then
        dc run --rm app php artisan test --log-junit "$JUNIT"
    else
        dc run --rm app php artisan test
    fi
}
step_migrate() {
    info "Migrations and idempotent synthetic seed"
    dc up -d --wait db
    dc run --rm app php artisan migrate --force
    dc run --rm app php artisan db:seed --force
}
step_dbcheck() {
    info "Connections are PostgreSQL (dev and test)"
    dc up -d --wait db db-test
    dc run --rm app php artisan app:db-check
    dc run --rm app php artisan app:db-check --connection=pgsql_test
}
step_backup() {
    info "Backup → restore-check: database, files and the application on the copy (the database itself is not changed)"
    local dir
    dir="$(mktemp -d "${TMPDIR:-/tmp}/promtech-backup.XXXXXX")"
    bash scripts/db-backup.sh --out "$dir/copy"
    bash scripts/db-restore-check.sh "$dir/copy"
    rm -rf "$dir"
}
step_http() {
    info "HTTP smoke through nginx"
    dc up -d --wait web
    local port code path
    port="$(dc port web 80 | awk -F: '{print $NF}')"
    [ -n "$port" ] || fail "web port is not published"
    for path in /up /login; do
        code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${port}${path}")"
        [ "$code" = "200" ] || fail "GET ${path} -> HTTP ${code}"
        ok "GET ${path} -> HTTP ${code}"
    done
}

steps=("$@")
[ ${#steps[@]} -gt 0 ] || steps=(pint types build tests dbcheck)
for step in "${steps[@]}"; do
    if ! declare -F "step_${step}" >/dev/null; then
        printf 'unknown check step: %s\n' "$step" >&2
        exit 64
    fi
done
for step in "${steps[@]}"; do
    "step_${step}"
done
ok "check passed: ${steps[*]}"
