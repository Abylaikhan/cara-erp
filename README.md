# PromTech ERP — приложение

Laravel 13 + Inertia 3 + Vue 3 (TypeScript) + PrimeVue 4 + PostgreSQL 17, локально полностью в Docker Compose.
ТЗ и аналитика (пакет PromTech-ERP-Harness 1.1) хранятся вне репозитория, локально в папке `docs/` рядом с `application/`: `HARNESS-DEVELOPMENT-SPEC.md`, `TECHNICAL-SPEC.md`.

**Состояние:** каркас окружения. Модули ERP ещё не перенесены.

## Требования

Только Docker Desktop (или Docker Engine) с Compose v2, Make и Git. PHP, Composer, Node и PostgreSQL локально не нужны. Работает на Apple Silicon и Linux x86_64/arm64.

## Команды

```sh
make setup    # первый запуск и безопасный повтор: .env, секреты, APP_KEY, зависимости из lock, миграции, seed, старт
make up       # запустить (данные БД сохраняются)
make down     # остановить (тома БД сохраняются)
make doctor   # версии, сервисы, порты, подключение PostgreSQL dev/test, HTTP — без вывода секретов
make check    # pint, vue-tsc, vite build, PHPUnit на изолированной PostgreSQL, проверка подключения к PostgreSQL
make audit-daily        # ежедневный аудит: legacy + целевой код, отчёт в docs/daily/, журнал docs/DAILY-CHANGELOG.md
make harness-selftest   # самопроверки harness (сценарии H-xx)
make help     # все команды (test, build, logs, shell, psql, ps)
```

Устройство аудита — [`tools/harness/README.md`](tools/harness/README.md). Для `make audit-daily` и `make harness-selftest`
дополнительно нужен `python3` ≥ 3.9 (на macOS он есть вместе с git).

Повторный `make setup` не перезаписывает `.env` и `APP_KEY` и не удаляет данные. Удаление томов (`docker compose down -v`) в команды не входит.

## Адреса (только 127.0.0.1)

| Что | Адрес | Переменная в `.env` |
|---|---|---|
| Приложение | http://localhost:8080 | `APP_PORT` |
| Vite dev server (HMR) | http://localhost:5173 | `VITE_PORT` |
| PostgreSQL dev с хоста | 127.0.0.1:5433 | `DB_FORWARD_PORT` |

Вход: синтетический администратор, `DEV_ADMIN_EMAIL` / `DEV_ADMIN_PASSWORD` из `.env` (пароль генерируется при первом `make setup`).

## Сервисы

| Сервис | Образ | Назначение |
|---|---|---|
| `app` | `php:8.4.26-fpm-bookworm` + Composer 2.10.3 | PHP-FPM, artisan; `pdo_pgsql intl mbstring bcmath zip pcntl` |
| `web` | `nginx:1.28.3-alpine` | HTTP → PHP-FPM, healthcheck `/up` |
| `node` | `node:24.21.0-bookworm-slim` | Vite dev server, сборка, `vue-tsc` |
| `db` | `postgres:17.11-alpine` | dev-БД, том `promtech-erp_pgdata` |
| `db-test` | `postgres:17.11-alpine` | изолированная тестовая БД: своя роль, пароль, том `promtech-erp_pgdata-test`, маркер `promtech:test-database` |

Контейнеры `app` и `node` работают под UID/GID пользователя хоста, поэтому файлы в рабочей папке остаются его собственностью.

## Тесты и защита БД

PHPUnit использует только подключение `pgsql_test`. Перед каждым тестом `Tests\Support\GuardsTestDatabase` проверяет `APP_ENV=testing`, имя подключения, отличие БД и роли от dev и комментарий-маркер базы. При несовпадении тест останавливается до первого изменяющего SQL. SQLite не используется.

## Ещё не реализовано

`queue`, `browser-tests` (Playwright), `make test-e2e`, `make test-visual`, `make db-backup`, `make db-restore-check`, `make audit-replay`, `make tasks-pull/sync/check`, запись в Google Sheets, роли и права, модули ERP, CI и расписание. Порядок — в [`docs/agent-state.md`](docs/agent-state.md).
