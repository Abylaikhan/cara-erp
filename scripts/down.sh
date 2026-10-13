#!/usr/bin/env bash
# make down — stop containers. Volumes (dev and test databases) are kept.
source "$(dirname "$0")/lib.sh"
require_docker
dc down --remove-orphans
rm -f public/hot
ok "Stopped. Database volumes are preserved (use 'docker volume ls | grep promtech-erp')."
