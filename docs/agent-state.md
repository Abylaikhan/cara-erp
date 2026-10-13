# Состояние работ агента

Обновлено: 2026-10-13 (Asia/Almaty).

## Текущий этап — 1. Локальное окружение и каркас приложения

Сделано и проверено (репозиторий https://github.com/Abylaikhan/cara-erp, [README](../README.md)):

- Docker Compose: `app` (PHP 8.4.26-FPM), `web` (nginx 1.28.3), `node` (Node 24.21.0, Vite 8), `db` и `db-test` (PostgreSQL 17.11, раздельные роли/пароли/тома, healthcheck `pg_isready`, зависимости через `service_healthy`). Порты только на 127.0.0.1 и настраиваются в `.env`.
- Laravel 13.36.0, inertiajs/inertia-laravel 3.5.1, @inertiajs/vue3 3.9.1, Vue 3.5.44 + TypeScript 5.9.3, PrimeVue 4.5.5 (тема Aura временно). Зафиксированы `composer.lock` и `package-lock.json`.
- `make setup / up / down / doctor / check`. Повторный setup сохраняет `.env`, `APP_KEY`, данные; данные переживают `down`/`up` (проверено пробной записью).
- Серверная сессия (database driver), вход/выход, ограничение попыток входа, идемпотентный синтетический seed администратора.
- Защита тестовой БД (APP_ENV, подключение, отличие БД/роли, маркер в комментарии базы) + тесты защиты. `make check`: pint, vue-tsc, vite build, 9 тестов PHPUnit на PostgreSQL — проходят.

Не сделано на этом этапе: `queue`, `browser-tests`, backup/restore-check.

## Следующие шаги

1. Baseline источника: fetch `index.html` по точному пути/SHA, сравнение с `c5e745c…`, `observed_source_ref` / `accepted_source_ref`.
2. `docs/coverage/requirements.csv`, реестр рисков.
3. Google Sheets-трекер (ссылка у владельца; ID в репозиторий не записывать — репозиторий публичный): 13.10 через браузер (анонимный редактор, доступ по ссылке «Редактор») добавлена вкладка «Что нужно от заказчика» — 30 пунктов (8 срочных вводных, DEC-01…16, 6 материалов), формула дат от ячейки C2; read-back через CSV-экспорт совпал, формула проверена тестовой датой (затем очищена). Вкладка «Список задач» пустая. API-ключ Google (хранится вне Git: `~/.config/promtech/google-sheets-api-key`, 600) даёт только чтение: metadata и значения/формулы читаются (HTTP 200), запись отклонена (HTTP 401, ключи API не поддерживаются для записи). Автоматическая запись — `SHEETS_BLOCKED`: нужен service account; после него вернуть доступ по ссылке на «Читатель». 210 задач для импорта — `../docs/planning/cara-erp-tracker-import.xlsx`.
4. Роли и права, первый вертикальный срез модуля, Playwright-сервис.
5. Harness: `make audit-daily`, selftests H-01…H-25, GS-01…GS-12, CI и расписание 07:17 Asia/Almaty.

## Блокеры и вопросы

- Доступ к Google Sheets для чтения/записи и дата старта плана.
- Схема/обезличенный dump для миграции данных; ответственный за бизнес-решения.
