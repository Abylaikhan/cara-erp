# Фактическая схема таблиц legacy (по данным, без DDL)

Источник: копия только чтением `legacy:snapshot` от 14.10.2026 (ADR-0004). Типы — наблюдаемые в данных JSON, а не объявленные в БД:
ограничения, индексы, FK, триггеры, RLS и функции через REST недоступны и **не известны** (нужен DDL от владельца, вопрос №6).
Значения и объёмы данных здесь не приводятся: репозиторий публичный.

| Таблица | Столбцы (наблюдаемый тип; «?» — встречается null) | Ключ для порядка |
|---|---|---|
| `advance_reports` | `id`: uuid; `doc_num`: text; `emp_name`: text; `date`: date; `purpose`: text; `issued`: integer; `spent`: integer/number; `returned`: integer; `status`: text; `account_id`: uuid; `tx_ids`: null?; `created_at`: timestamp; `tx_id`: uuid?; `lines`: json array? | `id` |
| `app_users` | пусто — столбцы не определить через REST | — |
| `bank_statement_raw` | `id`: uuid; `created_at`: timestamp; `account_id`: uuid; `bank_format`: text; `date`: date?; `flow`: text; `amount`: integer/number; `counterparty`: text; `bin`: text; `description`: text; `knp`: text; `doc_num`: text; `transferred_to_db`: boolean; `transferred_tx_id`: null?; `transferred_at`: null? | `id` |
| `customers` | `id`: uuid; `created_at`: timestamp; `name`: text; `type`: text; `phone`: text; `email`: text?; `address`: text?; `inn`: text?; `contact`: null?; `comment`: text?; `channel`: text; `turnover`: integer | `id` |
| `esf_payment_links` | `id`: uuid; `esf_invoice_id`: uuid; `source_type`: text; `source_id`: uuid; `amount`: integer/number; `created_at`: timestamp | `id` |
| `expense_categories` | `id`: uuid; `code`: text; `name`: text; `parent_code`: text?; `is_fixed`: boolean; `sort_order`: integer; `active`: boolean; `created_at`: timestamp | `id` |
| `fixed_costs` | пусто — столбцы не определить через REST | — |
| `goods_receipts` | пусто — столбцы не определить через REST | — |
| `incoming_invoice_items` | `id`: uuid; `invoice_id`: uuid; `description`: text; `qty`: integer/number; `unit_price`: integer/number; `amount`: integer/number; `nds_rate`: integer/number; `nds_amount`: integer/number; `total`: integer/number; `warehouse_id`: text? | `id` |
| `incoming_invoices` | `id`: uuid; `reg_num`: text; `num`: text; `date`: date; `status`: text; `supplier_name`: text; `supplier_bin`: text; `buyer_name`: text?; `buyer_bin`: text?; `currency`: text; `total_amount`: integer/number; `total_nds`: integer/number; `total_with_nds`: integer/number; `created_at`: timestamp; `posted_at`: null?; `invoice_type`: text | `id` |
| `leads` | `id`: uuid; `created_at`: timestamp; `name`: text; `phone`: text; `status`: text; `interest`: text?; `budget`: integer; `source`: text?; `comment`: text; `channel`: text; `manager`: text? | `id` |
| `material_aliases` | `id`: uuid; `created_at`: timestamp; `material_name`: text; `alias_name`: text; `category`: text | `id` |
| `material_name_aliases` | пусто — столбцы не определить через REST | — |
| `material_requests` | `id`: integer; `material_name`: text; `qty`: integer; `unit`: text; `order_num`: null?; `item_name`: text; `workshop`: null?; `requested_by`: null?; `status`: text; `purchase_note`: null?; `created_at`: timestamp; `updated_at`: timestamp | `id` |
| `money_tx` | `id`: uuid; `created_at`: timestamp; `date`: date; `account_id`: uuid; `flow`: text; `category`: text; `amount`: integer/number; `description`: text; `order_num`: text?; `counterparty`: text?; `subcategory`: text?; `amount_fx`: integer?; `fx_currency`: text?; `bin`: text? | `id` |
| `order_items` | `id`: integer; `created_at`: timestamp; `name`: text; `category`: text?; `qty`: integer; `price`: integer/number; `progress`: integer?; `status`: text?; `order_num`: text; `item_type`: text?; `resp`: text?; `date`: date/text?; `attributes`: json array/json object | `id` |
| `order_payments` | `id`: uuid; `tx_id`: uuid; `order_num`: text; `amount`: integer; `created_at`: timestamp | `id` |
| `orders` | `id`: integer; `created_at`: timestamp; `num`: text; `channel`: text; `customer`: text; `status`: text; `date`: date/text; `payment`: integer; `comment`: text?; `seller`: text; `delivery_date`: date/text?; `created_date`: date/text?; `payment_due`: text?; `end_client`: text | `id` |
| `payment_history` | пусто — столбцы не определить через REST | — |
| `production_progress` | `id`: integer; `item_key`: text; `order_num`: text; `item_idx`: integer; `item_name`: null?; `stages`: json array; `materials`: json array; `completed`: boolean; `updated_at`: timestamp | `id` |
| `production_worksheet` | `id`: integer; `order_num`: text; `item_idx`: integer; `added_at`: timestamp | `id` |
| `products` | `id`: uuid; `created_at`: timestamp; `name`: text; `category`: text; `price_retail`: integer; `price_wholesale`: integer; `unit`: text; `active`: boolean; `created_by`: null?; `updated_by`: null?; `updated_at`: timestamp; `delete_requested`: boolean; `delete_requested_by`: null? | `id` |
| `purch_orders` | пусто — столбцы не определить через REST | — |
| `realizations` | `id`: uuid; `doc_num`: text?; `doc_date`: date; `order_num`: text?; `customer`: text; `name`: text; `unit`: text?; `qty`: integer/number; `revenue`: integer/number; `created_at`: timestamp | `id` |
| `salary_accruals` | `id`: uuid; `emp_name`: text; `date`: date; `amount`: integer/number; `type`: text; `comment`: text?; `created_at`: timestamp; `deducted`: integer/number; `net`: integer/number; `details`: json object? | `id` |
| `salary_accruals_history` | `id`: uuid; `accrual_id`: uuid; `emp_name`: text; `date`: date; `amount`: integer; `deducted`: integer; `net`: integer; `details`: json object; `comment`: text; `changed_at`: timestamp; `changed_by`: text | `id` |
| `sales_worksheet` | `id`: uuid; `doc_id`: uuid; `doc_num`: text; `doc_date`: date; `line_no`: integer; `order_num`: text?; `customer`: text; `item_name`: text; `attrs`: text; `attrs_short`: boolean; `unit`: text; `qty`: integer; `price`: integer/number; `amount`: integer/number; `driver`: text?; `note`: text?; `delivered`: boolean; `delivered_at`: null?; `delivered_by`: null?; `posted`: boolean; `posted_at`: null?; `realization_id`: null?; `created_by`: text; `created_by_name`: text; `created_at`: timestamp | `id` |
| `salon_payroll` | `id`: uuid; `employee`: text; `month`: integer; `year`: integer; `salary`: integer; `bonus`: integer; `commission`: integer; `vacation`: integer; `total`: integer; `advance`: integer; `remainder`: integer; `created_at`: timestamp | `id` |
| `salon_worksheet` | `id`: uuid; `created_at`: timestamp; `salon`: text; `manager`: text?; `order_date`: date?; `order_num`: text?; `balance_before`: integer/number?; `payment_date`: date?; `cash_amount`: integer?; `card_amount`: integer?; `balance_after`: integer/number?; `note`: text?; `delivery_date`: date?; `cod_amount`: integer/number?; `expense_amount`: integer?; `transferred_to_office`: integer?; `recipient_desc`: text?; `transferred_to_db`: boolean; `transferred_at`: timestamp?; `transferred_tx_ids`: json array? | `id` |
| `staff` | `id`: uuid; `created_at`: timestamp; `name`: text; `pos`: text; `type`: text; `ws`: text?; `salary`: integer; `phone`: text?; `iin`: text?; `hired`: date/text?; `comment`: text?; `pin`: null? (не копируется); `commission_pct`: integer; `commission_method`: text; `active`: boolean; `fired`: null?; `base_salary`: integer; `deduction_meal`: integer; `deduction_opv_pct`: integer; `deduction_osms_pct`: integer; `deduction_so_pct`: number; `status`: text; `fired_date`: null?; `deduction_opv_fixed`: integer; `deduction_osms_fixed`: integer; `piece_goal`: integer | `id` |
| `staff_changes` | `id`: uuid; `staff_id`: uuid; `change_date`: date; `change_type`: text; `base_salary`: integer; `deduction_meal`: integer; `deduction_opv_pct`: integer; `deduction_osms_pct`: integer; `deduction_so_pct`: number; `comment`: text; `created_at`: timestamp | `id` |
| `staff_salary_history` | `id`: uuid; `staff_id`: uuid; `salary`: integer; `commission_pct`: integer/number; `valid_from`: date; `note`: text; `created_at`: timestamp | `id` |
| `suppliers` | `id`: uuid; `created_at`: timestamp; `name`: text; `category`: text?; `phone`: text?; `email`: text?; `contact`: text?; `inn`: text?; `comment`: text?; `status`: text; `bin`: text?; `expense_category`: text?; `expense_subcategory`: text?; `expense_source`: text | `id` |
| `tasks` | `id`: uuid; `title`: text; `date`: date; `time`: text; `manager`: text; `priority`: text; `status`: text; `lead_id`: uuid?; `created_at`: timestamp | `id` |
| `tech_cards` | пусто — столбцы не определить через REST | — |
| `wage_items` | `id`: uuid; `created_at`: timestamp; `emp_name`: text; `op`: text; `qty`: integer; `rate`: integer; `date`: date/text; `order_num`: text; `workshop`: text | `id` |
| `warehouse` | `id`: uuid; `created_at`: timestamp; `name`: text; `category`: text; `unit`: text; `qty`: integer/number; `price`: integer/number; `min_qty`: integer; `supplier`: text; `warehouse_id`: text? | `id` |
| `wh_documents` | пусто — столбцы не определить через REST | — |

Таблицы без строк для ключа доступа приложения: их структура и содержимое не определены — для них нужен DDL/выгрузка владельца.
