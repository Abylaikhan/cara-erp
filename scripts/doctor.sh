#!/usr/bin/env bash
# make doctor — diagnostics without secrets. Exit code != 0 if a required check fails.
source "$(dirname "$0")/lib.sh"
status=0
check() { # check "label" command...
    local label="$1"; shift
    if out="$("$@" 2>&1)"; then ok "$label${out:+: $out}"; else warn "$label FAILED${out:+: $out}"; status=1; fi
}

info "Host tools"
check "docker"          docker version --format '{{.Server.Version}} ({{.Server.Os}}/{{.Server.Arch}})'
check "docker compose"  docker compose version --short
check ".env present"    test -f .env
[ -f .env ] || { warn "run 'make setup'"; exit 1; }
check "compose config"  sh -c 'docker compose config --quiet'

info "Services"
dc ps --format 'table {{.Service}}\t{{.Status}}\t{{.Ports}}'
for svc in app web node db db-test; do
    check "service $svc running" sh -c "docker compose ps --status running --services | grep -qx $svc"
done

info "Ports (127.0.0.1)"
for key in APP_PORT VITE_PORT DB_FORWARD_PORT; do
    port="$(env_value "$key")"
    check "$key=$port listening" sh -c "nc -z 127.0.0.1 $port"
done

info "Runtime versions"
check "php"      dc exec -T app php -r 'echo PHP_VERSION;'
check "php ext"  dc exec -T app php -r 'foreach (["pdo_pgsql","intl","mbstring","bcmath","zip"] as $e) { if (!extension_loaded($e)) { echo "missing $e"; exit(1);} } echo "pdo_pgsql intl mbstring bcmath zip";'
check "laravel"  dc exec -T app php artisan --version
check "node"     dc exec -T node node --version

info "PostgreSQL"
check "db healthcheck"       dc exec -T db sh -c 'pg_isready -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" && postgres --version'
check "db-test healthcheck"  dc exec -T db-test sh -c 'pg_isready -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" && postgres --version'
check "laravel → db"         dc exec -T app php artisan app:db-check
check "laravel → db-test"    dc exec -T app php artisan app:db-check --connection=pgsql_test

info "HTTP"
url="$(app_url)"
check "GET $url/up"  sh -c "curl -fsS -o /dev/null -w '%{http_code}' $url/up"
check "GET $url/"    sh -c "curl -fsS -o /dev/null -w '%{http_code}' $url/"

[ "$status" -eq 0 ] && ok "doctor: all checks passed" || warn "doctor: some checks failed"
exit "$status"
