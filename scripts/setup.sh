#!/usr/bin/env bash
# make setup — first run and safe re-run. Never overwrites .env, APP_KEY or database volumes.
source "$(dirname "$0")/lib.sh"
require_docker

if [ ! -f .env ]; then
    info "Creating .env from .env.example with generated local secrets"
    cp .env.example .env
    while grep -q '__GENERATE__' .env; do
        secret="$(od -An -tx1 -N24 /dev/urandom | tr -d ' \n')"
        # replace only the first remaining placeholder so every secret is different
        awk -v s="$secret" '!done && /__GENERATE__/ { sub(/__GENERATE__/, s); done=1 } { print }' .env > .env.tmp
        mv .env.tmp .env
    done
    chmod 600 .env
    ok ".env created"
else
    ok ".env already exists — kept unchanged"
    grep -q '__GENERATE__' .env && fail ".env still contains __GENERATE__ placeholders; set those values manually"
fi

info "Building application image"
dc build app

info "Starting PostgreSQL (dev + test) and waiting for healthchecks"
dc up -d --wait db db-test

info "Installing PHP dependencies from composer.lock"
dc run --rm --no-deps app composer install --no-interaction --prefer-dist

info "Installing JS dependencies from package-lock.json"
dc run --rm --no-deps node npm ci --no-audit --no-fund

if [ -z "$(env_value APP_KEY)" ]; then
    info "Generating APP_KEY"
    dc run --rm --no-deps app php artisan key:generate --force
else
    ok "APP_KEY already set — kept unchanged"
fi

info "Running migrations and idempotent synthetic seed"
dc run --rm app php artisan migrate --force
dc run --rm app php artisan db:seed --force

info "Building frontend assets (used when the Vite dev server is not running)"
dc run --rm --no-deps node npm run build

info "Starting all services"
"$ROOT/scripts/up.sh"
