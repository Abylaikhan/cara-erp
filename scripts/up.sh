#!/usr/bin/env bash
# make up — start the environment; data in named volumes is preserved.
source "$(dirname "$0")/lib.sh"
require_docker
require_env
[ -d vendor ] && [ -d node_modules ] || fail "dependencies are not installed — run 'make setup'"

rm -f public/hot  # stale marker from an interrupted Vite run would break asset URLs
dc up -d --wait
ok "PromTech ERP is running: $(app_url)"
echo "    Vite dev server: http://localhost:$(env_value VITE_PORT)  ·  PostgreSQL (host): 127.0.0.1:$(env_value DB_FORWARD_PORT)"
