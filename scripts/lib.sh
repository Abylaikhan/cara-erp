#!/usr/bin/env bash
# Shared helpers for the make targets. Requires only Docker (with Compose v2) on the host.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

export HOST_UID="${HOST_UID:-$(id -u)}"
export HOST_GID="${HOST_GID:-$(id -g)}"

info()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()    { printf '\033[1;32m OK\033[0m %s\n' "$*"; }
warn()  { printf '\033[1;33m !!\033[0m %s\n' "$*"; }
fail()  { printf '\033[1;31mERR\033[0m %s\n' "$*" >&2; exit 1; }

dc() { docker compose "$@"; }

require_docker() {
    command -v docker >/dev/null 2>&1 || fail "docker is not installed"
    docker compose version >/dev/null 2>&1 || fail "docker compose v2 is not available"
    docker info >/dev/null 2>&1 || fail "Docker daemon is not running (start Docker Desktop)"
}

require_env() {
    [ -f .env ] || fail ".env not found — run 'make setup' first"
}

env_value() { # env_value KEY -> value from .env without surrounding quotes
    sed -n "s/^$1=//p" .env | tail -n1 | sed -e 's/^"//' -e 's/"$//'
}

app_url() { local port; port="$(env_value APP_PORT)"; echo "http://localhost:${port:-8080}"; }
