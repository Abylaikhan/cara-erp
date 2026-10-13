# PromTech ERP — local environment commands. Only Docker (Compose v2) is required on the host.
SHELL := /bin/bash
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

.DEFAULT_GOAL := help
.PHONY: help setup up down restart doctor check test build ps logs shell psql audit-daily harness-selftest visual-compare

help: ## Show available commands
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-10s %s\n", $$1, $$2}'

setup: ## First install / safe re-run: build, deps from lock files, .env, APP_KEY, migrate, seed, start
	@scripts/setup.sh

up: ## Start all services (data is preserved)
	@scripts/up.sh

down: ## Stop all services (database volumes are preserved)
	@scripts/down.sh

restart: down up ## Restart all services

doctor: ## Diagnose versions, services, ports, PostgreSQL and HTTP (no secrets)
	@scripts/doctor.sh

check: ## Code style, types, build, backend tests on PostgreSQL, DB connection check
	@scripts/check.sh

audit-daily: ## Daily audit: pin legacy + target refs, diff, checks, MD/JSON report (exit != 0 on failures)
	@python3 tools/harness/run.py daily $(if $(TARGET_REF),--target-ref $(TARGET_REF),)

harness-selftest: ## Self-tests of the audit harness (H-xx scenarios on local fixtures)
	@python3 tools/harness/selftest.py

visual-compare: ## Screenshots legacy vs target (login, M14) and % of differing pixels; needs host playwright + Chrome
	@python3 tests/visual/legacy_compare.py

test: ## Backend tests on the isolated test database
	@docker compose run --rm app php artisan test

build: ## Production frontend build
	@docker compose run --rm --no-deps node npm run build

ps: ## Service status
	@docker compose ps

logs: ## Follow logs (S=service to filter)
	@docker compose logs -f --tail=100 $(S)

shell: ## Shell in the PHP container
	@docker compose exec app bash

psql: ## psql to the development database
	@docker compose exec db sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'
