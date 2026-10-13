#!/usr/bin/env bash
# make check — style, types, build, backend tests on PostgreSQL and smoke. Non-zero exit on failure.
source "$(dirname "$0")/lib.sh"
require_docker
require_env
dc up -d --wait db db-test

info "PHP code style (pint --test)"
dc run --rm --no-deps app vendor/bin/pint --test
info "TypeScript / Vue types (vue-tsc)"
dc run --rm --no-deps node npm run typecheck
info "Production build (vite build)"
dc run --rm --no-deps node npm run build
info "Backend tests on isolated PostgreSQL (db-test)"
dc run --rm app php artisan test
ok "make check passed"
