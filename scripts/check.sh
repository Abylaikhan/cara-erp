#!/usr/bin/env bash
# make check — code style, types, build, backend tests on PostgreSQL. Non-zero exit on failure.
# Usage: scripts/check.sh [step ...]
#   default steps: pint types build tests dbcheck
#   extra steps (used by the audit harness on its isolated project): deps migrate backup http prod
#   prod — the production mode on a throwaway database (make prod-check, ADR-0031); needs the build step before it
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

# The production mode, next to whatever runs (the dev server too): a throwaway database, a one-off instance of the image
# with APP_ENV=production and its caches in its own /tmp, the hot file of the dev server out of the way. Nothing of the
# dev database or the dev caches is touched; the database and the instance are removed at the end.
PROD_NAME="" PROD_DB="" PROD_USER="" PROD_DEVDB="" PROD_TMP=""
prod_cleanup() {
    if [ -n "$PROD_NAME" ]; then docker rm -f "$PROD_NAME" >/dev/null 2>&1 || true; fi
    if [ -n "$PROD_DB" ]; then
        dc exec -T db psql -X -q -U "$PROD_USER" -d "$PROD_DEVDB" -c "DROP DATABASE IF EXISTS ${PROD_DB} WITH (FORCE)" >/dev/null 2>&1 || true
    fi
    if [ -n "$PROD_TMP" ]; then rm -rf "$PROD_TMP"; fi
}
no_leak() { # no_leak FILE WHAT — an error page must not show the code, paths, SQL or stack traces
    if grep -qiE 'stack trace|whoops|vendor/laravel|/var/www/html|sqlstate|illuminate\\|app_key' "$1"; then
        fail "$2: the page shows details of the application"
    fi
}
step_prod() {
    info "Production mode on a throwaway database: PHP settings, refusals, caches, error pages, built assets, no secrets in the build"
    [ -f public/build/manifest.json ] || fail "no production build (public/build): run the build step first"
    trap prod_cleanup EXIT
    PROD_TMP="$(mktemp -d "${TMPDIR:-/tmp}/promtech-prod.XXXXXX")"
    local tmp="$PROD_TMP" ini port host base code key value
    local leaked=()

    ini="$(dc run --rm --no-deps -T app php -r 'echo ini_get("display_errors"), "|", ini_get("log_errors"), "|", ini_get("expose_php");' | tr -d '\r')"
    case "$ini" in
        "|1|"|"0|1|"|"|1|0"|"0|1|0") ok "PHP: errors are logged, not shown; the version is not sent" ;;
        *) fail "PHP settings for production (display_errors|log_errors|expose_php): ${ini}" ;;
    esac

    dc up -d --wait db
    PROD_USER="$(dc exec -T db printenv POSTGRES_USER | tr -d '\r')"
    PROD_DEVDB="$(dc exec -T db printenv POSTGRES_DB | tr -d '\r')"
    PROD_DB="promtech_prod_$(date -u +%Y%m%d%H%M%S)"
    PROD_NAME="${COMPOSE_PROJECT_NAME:-promtech-erp}-prod-$$"
    port=8090
    local env=(-e APP_ENV=production -e APP_DEBUG=false -e LOG_LEVEL=warning -e DB_DATABASE="$PROD_DB" -e VITE_HOT_FILE=/tmp/no-dev-server
        -e APP_CONFIG_CACHE=/tmp/config.php -e APP_ROUTES_CACHE=/tmp/routes.php -e APP_EVENTS_CACHE=/tmp/events.php
        -e APP_SERVICES_CACHE=/tmp/services.php -e APP_PACKAGES_CACHE=/tmp/packages.php -e VIEW_COMPILED_PATH=/tmp/views)
    dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$PROD_USER" -d "$PROD_DEVDB" -c "CREATE DATABASE ${PROD_DB}" >/dev/null
    dc run --rm --no-deps -T "${env[@]}" app php artisan migrate --force >"$tmp/migrate" 2>&1 \
        || { tail -5 "$tmp/migrate" >&2; fail "migrations in production mode"; }
    ok "migrations run in production mode"
    if dc run --rm --no-deps -T "${env[@]}" app php artisan db:seed --force >"$tmp/seed" 2>&1; then fail "the synthetic seed ran in production"; fi
    grep -q "must not run in production" "$tmp/seed" || { tail -3 "$tmp/seed" >&2; fail "the synthetic seed failed for another reason"; }
    ok "the synthetic seed refuses production"
    if dc run --rm --no-deps -T "${env[@]}" app php artisan legacy:import expense_categories >"$tmp/import" 2>&1; then fail "legacy:import ran in production"; fi
    grep -q "into production" "$tmp/import" || { tail -3 "$tmp/import" >&2; fail "legacy:import failed for another reason"; }
    ok "legacy:import refuses production"

    dc run -d --no-deps --name "$PROD_NAME" -p "127.0.0.1::${port}" "${env[@]}" -w /var/www/html/public app sh -c \
        "mkdir -p /tmp/views && php ../artisan config:cache >/dev/null && php ../artisan route:cache >/dev/null && php ../artisan view:cache >/dev/null && exec php -S 0.0.0.0:${port} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" >/dev/null
    host="$(docker port "$PROD_NAME" "${port}/tcp" | head -n1 | sed 's/.*://')"
    [ -n "$host" ] || fail "the production instance publishes no port"
    base="http://127.0.0.1:${host}"
    for _ in $(seq 1 120); do
        if curl -fs "$base/up" >/dev/null 2>&1; then break; fi
        sleep 0.5
    done
    curl -fs "$base/up" >/dev/null || { docker logs "$PROD_NAME" 2>&1 | tail -5 >&2; fail "the production instance did not start"; }
    docker exec "$PROD_NAME" php /var/www/html/artisan about --json >"$tmp/about" 2>/dev/null || fail "artisan about in the instance"
    python3 -c '
import json, sys
a = json.load(open(sys.argv[1]))
env, cache = a["environment"], a["cache"]
sys.exit(0 if env["environment"] == "production" and env["debug_mode"] is False and all(cache.get(k) is True for k in ("config", "routes", "views")) else 1)
' "$tmp/about" || fail "the instance is not in production mode with its caches"
    ok "production, debug off; configuration, routes and views cached"

    code="$(curl -s -D "$tmp/login.h" -o "$tmp/login" -w '%{http_code}' "$base/login")"
    [ "$code" = "200" ] || fail "GET /login -> HTTP ${code}"
    grep -q '/build/assets/' "$tmp/login" || fail "the login page does not load the built assets"
    if grep -qE '@vite/client|:5173' "$tmp/login"; then fail "the login page loads the Vite dev server"; fi
    if grep -qi '^x-powered-by' "$tmp/login.h"; then fail "the response names the PHP version (X-Powered-By)"; fi
    grep -i '^set-cookie: .*session' "$tmp/login.h" | grep -qi 'httponly' || fail "the session cookie is not HttpOnly"
    ok "GET /login -> 200: built assets, no X-Powered-By, HttpOnly session cookie"
    code="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$base/dashboard")"
    case "$code" in
        "302 "*"/login") ok "a guest is sent to /login" ;;
        *) fail "GET /dashboard as a guest -> ${code}" ;;
    esac
    code="$(curl -s -o "$tmp/404" -w '%{http_code}' "$base/no-such-page-$$")"
    [ "$code" = "404" ] || fail "GET of a missing page -> HTTP ${code}"
    no_leak "$tmp/404" "404"
    code="$(curl -s -o "$tmp/419" -w '%{http_code}' -X POST -d 'login=x&password=y' "$base/login")"
    [ "$code" = "419" ] || fail "POST /login without the CSRF token -> HTTP ${code}"
    no_leak "$tmp/419" "419"
    ok "404 and 419 pages without details"

    for key in APP_KEY DB_PASSWORD DB_TEST_PASSWORD DEV_ADMIN_PASSWORD LEGACY_SUPABASE_URL LEGACY_SUPABASE_ANON_KEY; do
        value="$(env_value "$key")"
        value="${value#base64:}"
        [ "${#value}" -ge 8 ] || continue
        if grep -rqF -- "$value" public/build; then leaked+=("$key"); fi
    done
    if grep -rqE 'eyJhbGciOi|BEGIN [A-Z ]*PRIVATE KEY|service_role' public/build; then leaked+=("a token or key pattern"); fi
    [ ${#leaked[@]} -eq 0 ] || fail "the build carries secrets: ${leaked[*]}"
    ok "the build carries no secret of .env and no token or key"
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
