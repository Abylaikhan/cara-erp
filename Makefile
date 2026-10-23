# PromTech ERP — local environment commands. Only Docker (Compose v2) is required on the host.
SHELL := /bin/bash
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

.DEFAULT_GOAL := help
.PHONY: help setup up down restart doctor check test build ps logs shell psql audit-daily audit-replay harness-selftest visual-compare legacy-copy legacy-import db-backup db-restore-check migration-rehearsal reliability-check e2e test-e2e test-visual visual-empty visual-filters registry data-scan prod-check perf

help: ## Show available commands
	@grep -E '^[a-z0-9-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-20s %s\n", $$1, $$2}'

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

audit-replay: ## Repeat a recorded run (RUN_ID=…) on its source and target refs without publication; compare with the record
	@python3 tools/harness/run.py replay $(RUN_ID)

perf: ## Response time and size of every migrated page and tab on the running app (read only; OPS-01, ADR-0032)
	@python3 scripts/perf.py

prod-check: ## Production mode on a throwaway database: build, caches, refusals, error pages, assets, no secrets in the build (ADR-0031)
	@bash scripts/check.sh build prod

harness-selftest: ## Self-tests of the audit harness (H-xx scenarios on local fixtures)
	@python3 tools/harness/selftest.py

data-scan: ## Before a commit: values of the real legacy data in the changed files (masked); non-zero if a new one appears
	@python3 scripts/data_scan.py

registry: ## Registry traceability (AC-09): a status for every page, window, control and function of the accepted legacy → docs/coverage/registry.md
	@python3 tools/harness/registry.py

visual-compare: ## Screenshots legacy vs target (login, M14, M07, M08, M06, M03, M05, M10, M04, M09, M02, M12) and % of differing pixels; needs host playwright + Chrome
	@python3 tests/visual/legacy_compare.py

test-visual: ## Visual comparison at every size of the spec (1440×900, 1920×1080, 900×900, 899×900, 390×844), both themes
	@bash scripts/visual-matrix.sh

visual-filters: ## Visual comparison plus every filter, sort and their totals of the migrated pages, legacy ↔ target (AC-08)
	@FILTERS=1 $${PYTHON:-python3} tests/visual/legacy_compare.py

visual-empty: ## Visual comparison of the empty states (AC-07): a throwaway database with the users only, via make e2e
	@E2E_SEED=users bash scripts/e2e.sh tests/visual/legacy_compare.py

test-e2e: e2e ## Same as make e2e (name of the harness spec)

e2e: ## Browser acceptance scenarios on a throwaway database + separate app instance (synthetic seed; the dev DB is verified unchanged)
	@bash scripts/e2e.sh

reliability-check: ## Browser check of failures (server error, no connection, expired session, double click); writes are intercepted, data untouched
	@python3 tests/e2e/reliability_check.py

db-backup: ## Copy of the dev database and uploaded files + manifest and the app's answers (checksums) → .local-audit/backups/ (owner-only)
	@bash scripts/db-backup.sh

db-restore-check: ## Restore the latest copy (or BACKUP=dir) in isolation: schema, rows, files, then the app on the copy must answer the same
	@bash scripts/db-restore-check.sh $(BACKUP)

legacy-copy: ## Read-only copy of the legacy Supabase (GET only) → .local-audit/supabase → local schema `legacy`, verified
	@docker compose run --rm app php artisan legacy:snapshot
	@docker compose run --rm app php artisan legacy:load
	@docker compose run --rm app php artisan legacy:natural-order incoming_invoice_items
	@docker compose run --rm app php artisan legacy:natural-order order_items
	@docker compose run --rm app php artisan legacy:natural-order advance_reports --order=date.desc
	@docker compose run --rm app php artisan legacy:natural-order salon_payroll --order=year.desc,month.desc
	@docker compose run --rm app php artisan legacy:natural-order production_worksheet

migration-rehearsal: ## Rehearsal of the legacy migration on a temporary database: migrate, every import, control totals, repeat (no change)
	@bash scripts/migration-rehearsal.sh $(if $(SNAPSHOT),--snapshot $(SNAPSHOT))

legacy-import: ## Move a legacy table into a migrated module, e.g. make legacy-import ENTITY=expense_categories
	@docker compose run --rm app php artisan legacy:import $(ENTITY)

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
