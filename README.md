# PromTech ERP — приложение

Laravel 13 + Inertia 3 + Vue 3 (TypeScript) + PrimeVue 4 + PostgreSQL 17, локально полностью в Docker Compose.
ТЗ и аналитика (пакет PromTech-ERP-Harness 1.1) хранятся вне репозитория, локально в папке `docs/` рядом с `application/`: `HARNESS-DEVELOPMENT-SPEC.md`, `TECHNICAL-SPEC.md`.

**Состояние:** оболочка 1:1 (меню, шапка, обе темы), вход и серверные права по ролям. Перенесены 16 из 23 страниц —
каталог, покупатели, поставщики, склад, ЭСФ, закупки, продажи, производство, CRM, деньги, финансы, дашборд, статьи
расходов, касса и отчёт салонов, сверка — в основном просмотр и справочники, с реальными данными из копии legacy.
Проведение денег, склада, производства, правка заказов и зарплата ждут решений заказчика —
[`docs/decisions-request.md`](docs/decisions-request.md). Неперенесённые страницы честно об этом сообщают.

Эксплуатация (запуск, обновление, резервная копия, восстановление, откат) — [`docs/OPERATIONS.md`](docs/OPERATIONS.md).
Состояние работ — [`docs/agent-state.md`](docs/agent-state.md), решения и отклонения — `docs/adr/`, трассировка
требований — `docs/coverage/requirements.csv`.

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
make db-backup          # копия БД и загруженных файлов (вне Git); make db-restore-check — изолированная проверка восстановления
make migration-rehearsal  # репетиция переноса legacy на временной БД с контрольными итогами
make visual-compare     # скриншоты legacy ↔ новая версия и % отличий (нужны python3-playwright и Chrome на хосте)
make reliability-check  # сбои в браузере: 500, обрыв связи, истёкшая сессия, двойное нажатие
make e2e                # сценарии приёмки в браузере на временной БД (dev-БД не трогается)
make help     # все команды (test, build, logs, shell, psql, ps)
```

## Данные legacy (только чтение)

```sh
make legacy-copy                              # копия всех таблиц Supabase (только GET) и загрузка в схему legacy со сверкой
make legacy-import ENTITY=products            # перенос таблицы в перенесённый раздел; ENTITY=all — все 17 по порядку
docker compose run --rm app php artisan legacy:import all --dry-run   # пробный прогон: ничего не записывает
docker compose run --rm app php artisan legacy:references             # ссылки, которые не найдены или неоднозначны
```

Нужны `LEGACY_SUPABASE_URL` и `LEGACY_SUPABASE_ANON_KEY` в `.env`. Снимок с персональными данными хранится только локально
в `.local-audit/supabase/` (вне Git); пароли и PIN не копируются. Подробности — [`docs/adr/0004-legacy-data-copy.md`](docs/adr/0004-legacy-data-copy.md).

Устройство аудита — [`tools/harness/README.md`](tools/harness/README.md). Для `make audit-daily` и `make harness-selftest`
дополнительно нужен `python3` ≥ 3.9 (на macOS он есть вместе с git).

Повторный `make setup` не перезаписывает `.env` и `APP_KEY` и не удаляет данные. Удаление томов (`docker compose down -v`) в команды не входит.

## Адреса (только 127.0.0.1)

| Что | Адрес | Переменная в `.env` |
|---|---|---|
| Приложение | http://localhost:8080 | `APP_PORT` |
| Vite dev server (HMR) | http://localhost:5173 | `VITE_PORT` |
| PostgreSQL dev с хоста | 127.0.0.1:5433 | `DB_FORWARD_PORT` |

Вход: синтетические пользователи всех 15 ролей legacy, логин = ключ роли (`director`, `manager`, `storekeeper`, `accountant`,
`salon_armada`, `dealer_astana`, …), пароль — `DEV_ADMIN_PASSWORD` из `.env` (генерируется при первом `make setup`).

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

## Что дальше

Оставшиеся разделы и проведение операций ждут решений заказчика: опросник с вариантами и рекомендациями —
[`docs/decisions-request.md`](docs/decisions-request.md). Порядок и состояние — [`docs/agent-state.md`](docs/agent-state.md).
