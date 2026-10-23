# Модель данных новой системы (PostgreSQL 17)

Для разработчика и проверяющего. Что лежит в базе новой системы, как таблицы связаны и откуда пришли данные legacy.
Состояние на 22.10.2026, коммиты срезов ADR-0001…0041. Схема создаётся миграциями
`database/migrations` (`make setup`), без ручных изменений. Фактическая схема legacy — `docs/legacy-schema.md`.

## 1. Общие правила

- **Деньги и количества** — `numeric` (Laravel `decimal`), без float. Суммы, которые legacy считал в браузере, хранятся
  как есть, до 12 знаков (зарплата, рабочие листы). Деньги считает сервер, в транзакции.
- **Даты.** Календарные даты — `date`. Моменты — `timestamptz` (до микросекунд, как в Supabase). Бизнес-пояс —
  Asia/Almaty: по нему считаются «сегодня», месяц и период.
- **Текст для сортировки и поиска** — `COLLATE "en-US-x-icu"`, как в Supabase. Тогда порядок строк тот же, что в legacy.
- **Правка одной записи двумя пользователями** — `lock_version`. Сохраняется только та правка, что видела последнюю
  версию.
- **Журнал аудита** — `audit_events`: кто, когда, что сделал и что изменилось. В том числе импорт из legacy и удаление
  строк, которых в legacy больше нет: удалённые значения остаются в журнале.
- **Каналы** (офис, салоны, дилеры) — коды legacy в `sales_channels`. Записи с каналом выбирает и меняет только
  `App\Support\ChannelScope`.

## 2. Таблицы по разделам

«Только просмотр» — данные перенесены и показываются, а изменение ждёт решений (`docs/decisions-request.md`).

| Раздел | Таблица | Что хранит | Ссылается на | Источник в legacy |
|---|---|---|---|---|
| Доступ | `users` | учётные записи: логин, роль, канал, активность | `sales_channels` | роли из кода legacy; пароли не переносятся (D-13) |
| Доступ | `sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` | служебные таблицы Laravel | — | — |
| Общее | `audit_events` | журнал аудита | `users` | — (новое) |
| Общее | `legacy_id_map` | ключ legacy → запись новой системы | — | все импорты |
| M13 Каналы | `sales_channels` | офис, салоны, дилеры: код, группа, регион | `users` | константы кода legacy |
| M14 Статьи | `expense_categories` | статьи расходов, иерархия по родителю | — | `expense_categories` |
| M14 Статьи | `expense_category_merges` | объединения статей: что во что, счётчики ссылок | `expense_categories`, `users` | — (новое) |
| M07 Каталог | `products` | товары, цены розница и опт | `users` | `products` |
| M08 Покупатели | `customers` | покупатели и дилеры | `sales_channels`, `users` | `customers` |
| M06 Поставщики | `suppliers` | поставщики, БИН, статья расхода | `expense_categories`, `users` | `suppliers` |
| M06 Поставщики | `supplier_merges` | объединения поставщиков (дубли не удаляются) | `suppliers`, `users` | — (новое) |
| M03 Склад | `warehouses` | физические склады | — | константы кода legacy |
| M03 Склад | `materials` | материалы: количество, цена, минимум | `warehouses`, `users` | `warehouse` |
| M03 Склад | `material_aliases` | названия материалов у поставщиков | `suppliers`, `users` | `material_aliases` |
| M04 Закупки | `material_requests` | заявки на материалы | `users` | `material_requests` |
| M05 ЭСФ | `incoming_invoices` | входящие ЭСФ | `suppliers`, `users` | `incoming_invoices` |
| M05 ЭСФ | `incoming_invoice_items` | строки ЭСФ (естественный порядок legacy) | `incoming_invoices`, `warehouses` | `incoming_invoice_items` |
| M05 ЭСФ | `esf_payment_links` | связь ЭСФ с оплатами | `incoming_invoices` | `esf_payment_links` |
| M10 CRM | `leads` | лиды | `sales_channels`, `users` | `leads` |
| M10 CRM | `crm_tasks` | задачи | `leads`, `users` | `tasks` |
| M10 CRM | `lead_communications` | история общения с лидом | `leads`, `users` | — (в legacy только в памяти страницы) |
| M10 Проекты | `projects`, `project_calcs`, `project_comments`, `project_files` | проекты, расчёты, сообщения, файлы | `projects`, `sales_channels`, `users` | одноимённые таблицы |
| M09 Продажи | `orders` | заказы: канал, покупатель, даты, оплата, статус | `sales_channels`, `users` | `orders` |
| M09 Продажи | `order_items` | позиции заказа; `line_no` — место в заказе (номер позиции −1) | `orders` | `order_items`, естественный порядок |
| M09 Продажи | `realizations` | реестр «Реализация» | `orders` | `realizations` |
| M09 Продажи | `sales_worksheet_rows` | строки накладных рабочего листа реализации (только просмотр, D-08) | `orders`, `realizations` | `sales_worksheet` |
| M02 Производство | `production_progress` | этапы, исполнители, материалы позиции | `order_items` | `production_progress` |
| M02 Производство | `production_worksheet_rows` | позиции на рабочем листе производства (только просмотр, D-05/D-06) | `order_items` | `production_worksheet`, естественный порядок |
| M12 Деньги | `money_accounts` | кассы и счета (без номеров счетов) | — | `bankAccounts` в коде legacy |
| M12 Деньги | `money_transactions` | денежные операции | `money_accounts`, `expense_categories`, `orders` | `money_tx` |
| M12 Деньги | `order_payments` | разнесение поступления по заказам | `money_transactions`, `orders` | `order_payments` |
| M12 Деньги | `advance_reports` | подотчётные суммы | `money_accounts`, `money_transactions` | `advance_reports` |
| M12 Деньги | `bank_statement_rows` | строки банковских выписок | `money_accounts`, `money_transactions` | `bank_statement_raw` |
| M15 Касса салона | `salon_worksheet_rows` | рабочий лист салона | `users` | `salon_worksheet` |
| M17 Сотрудники | `employees` | карточки сотрудников (без PIN) | — | `staff` |
| M17 Сотрудники | `employee_salary_history` | история окладов и процентов | `employees` | `staff_salary_history` |
| M17 Сотрудники | `employee_changes` | изменения условий и удержаний | `employees` | `staff_changes` |
| M18 Сдельная | `piece_work_items` | выполненные операции | — (сотрудник по имени, как в legacy) | `wage_items` |
| M20 Начисления | `salary_accruals` | начисления, удержания, к выплате | — (сотрудник по имени) | `salary_accruals` |
| M20 Начисления | `salary_accrual_changes` | прежние версии начислений | `salary_accruals` | `salary_accruals_history` |
| M21 Салоны | `salon_payroll_rows` | ведомость салонов по месяцам | — (сотрудник по имени) | `salon_payroll` |

Записи зарплаты называют сотрудника текстом, как в legacy. Связь с карточкой по ключу — вместе с правилами зарплаты
(D-06, D-13).

## 3. Карта ID legacy

- **`legacy_id_map`** — для каждой перенесённой записи: таблица и ключ legacy → таблица и `id` новой системы, снимок
  и время. Повторный импорт находит запись по ней и не создаёт дубль.
- **Ключ legacy в самой записи.** Таблицы, скопированные как есть, хранят ключ legacy и в своей строке: `uuid` или
  `legacy_id` (числовой). Это сотрудники, зарплата, рабочие листы реализации и производства (`MirrorsLegacyTable`),
  выписки и рабочий лист салона. У первых и у выписок строка, которой больше нет в legacy, удаляется при повторном
  импорте; её значения остаются в `audit_events`.
- **Позиция заказа** в legacy — «номер заказа|индекс». Здесь это `order_items.order_id` + `line_no`. По этому ключу к
  позиции привязаны прогресс производства и строки рабочего листа.
- **Порядок строк.** Если legacy показывает строки без сортировки, порядок читается отдельно
  (`legacy:natural-order`, только GET) и хранится: `line_no`, `legacy_position`.
- **Сверка.** Каждый импорт перечитывает записанное и сравнивает с legacy поле за полем. `legacy:control-totals`
  сверяет количества и суммы по модулям и месяцам.

## 4. Что не перенесено и что не известно

- **`app_users`.** Учётные записи заводятся заново: пароли и PIN не копируются (D-13).
- **Пустые таблицы legacy:** `fixed_costs`, `goods_receipts`, `material_name_aliases`, `payment_history`,
  `purch_orders`, `restorations`, `restoration_files`, `tech_cards`, `wh_documents`. Строк нет, поэтому структуру через
  REST не определить; таблиц для них пока нет. Документы склада (D-04, D-05), реставрации (ERP-080) и техкарты (D-12)
  получат свои таблицы со своими разделами.
- **Не известно:** DDL Supabase — ограничения, индексы, внешние ключи, триггеры, RLS, функции. Через REST их не видно,
  нужен DDL от владельца (вопрос № 6). Типы legacy в `docs/legacy-schema.md` — наблюдаемые по данным.

## 5. Как посмотреть схему

```sh
make psql          # \dt — таблицы, \d <таблица> — столбцы, ключи и индексы
```
