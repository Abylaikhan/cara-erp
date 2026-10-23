<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Control totals of the migration (ERP-069, ТЗ MIG): for every migrated module the number of records and the sums of its
 * amounts and quantities, by period (month of the business date) or by the module's own breakdown, computed once from the
 * loaded legacy snapshot (schema legacy) and once from the application tables, and compared exactly. Independent of the
 * importers: the legacy side is plain SQL over the copied JSON, with the importers' documented conversions only (defaults,
 * rounding, collapsed copies). Meant for a database freshly filled by legacy:import (the migration rehearsal): rows created
 * in the application itself would count as differences.
 *
 * The console shows counts and statuses only; the sums (real money) go to --out, a git-ignored file readable by the owner.
 * Legacy tables no importer reads are listed with the reason. Non-zero exit when any module differs.
 */
class LegacyControlTotals extends Command
{
    protected $signature = 'legacy:control-totals {--snapshot= : Snapshot id (default: the latest loaded)} {--out= : JSON with every period and sum (keep it in .local-audit)}';

    protected $description = 'Compare control totals (counts, sums by period) of the legacy snapshot with the imported application tables';

    /** Legacy tables no importer reads yet, and why. */
    private const NOT_MIGRATED = [
    ];

    public function handle(): int
    {
        $snapshot = (string) ($this->option('snapshot') ?: DB::table('legacy.snapshots')->max('id'));
        if (! DB::table('legacy.snapshots')->where('id', $snapshot)->exists()) {
            $this->error('No loaded snapshot: run legacy:snapshot and legacy:load first.');

            return self::FAILURE;
        }
        $result = ['snapshot' => $snapshot, 'checked_at' => now()->toIso8601String(), 'modules' => [], 'not_migrated' => []];
        $rows = [];
        $failed = 0;
        foreach ($this->checks(DB::getPdo()->quote($snapshot)) as [$module, $tables, $legacySql, $targetSql]) {
            [$measures, $legacy] = $this->read($legacySql);
            [, $target] = $this->read($targetSql);
            $diff = [];
            foreach (array_unique([...array_keys($legacy), ...array_keys($target)]) as $p) {
                if (($legacy[$p] ?? null) !== ($target[$p] ?? null)) {
                    $diff[] = ['period' => $p, 'legacy' => $legacy[$p] ?? null, 'target' => $target[$p] ?? null];
                }
            }
            $count = fn (array $set) => array_sum(array_map(fn (array $v) => (int) $v[0], $set));
            $status = $diff ? 'DIFFERS' : 'MATCH';
            $failed += $diff ? 1 : 0;
            $result['modules'][] = ['module' => $module, 'tables' => $tables, 'status' => $status, 'legacy_rows' => $count($legacy), 'target_rows' => $count($target),
                'periods' => count($legacy), 'measures' => $measures, 'legacy' => $legacy, 'target' => $target, 'differences' => $diff];
            $rows[] = [$module, $tables, $count($legacy), $count($target), count($legacy), $diff ? 'расходится ('.count($diff).' периодов)' : 'совпадает'];
        }

        $covered = collect($result['modules'])->flatMap(fn (array $m) => explode(', ', $m['tables']))->all();
        $manifest = json_decode(DB::table('legacy.snapshots')->where('id', $snapshot)->value('manifest'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['tables'] as $t) {
            if (in_array($t['table'], $covered, true)) {
                continue;
            }
            $reason = (int) $t['rows'] === 0 ? 'в legacy нет записей' : (self::NOT_MIGRATED[$t['table']] ?? null);
            $failed += $reason === null ? 1 : 0;
            $result['not_migrated'][] = ['table' => $t['table'], 'rows' => (int) $t['rows'], 'reason' => $reason ?? 'НЕ ОБЪЯСНЕНО: нет импортёра'];
        }

        $this->table(['Модуль', 'Таблицы legacy', 'Записей legacy', 'Записей в ERP', 'Периодов', 'Итоги'], $rows);
        $this->table(['Не перенесено', 'Записей', 'Причина'], array_map(fn (array $t) => array_values($t), $result['not_migrated']));
        if ($out = $this->option('out')) {
            file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            chmod($out, 0600);
            $this->line("Details (with sums — keep out of Git): {$out}");
        }
        if ($failed) {
            $this->error("{$failed} module(s) or table(s) not reconciled");

            return self::FAILURE;
        }
        $this->info('All migrated modules reconcile with the legacy snapshot '.$snapshot);

        return self::SUCCESS;
    }

    /** @return array{list<string>, array<string, list<string|null>>} measure names, period → [n, sums…] (sums normalised by trim_scale) */
    private function read(string $sql): array
    {
        // by position: the application queries do not name their columns, and PDO would merge equal names
        $statement = DB::getPdo()->query($sql);
        $names = array_map(fn (int $i) => $statement->getColumnMeta($i)['name'], range(1, $statement->columnCount() - 1));
        $set = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $values) {
            $set[(string) array_shift($values)] = array_map(fn ($v) => $v === null ? null : (string) $v, $values);
        }
        ksort($set);

        return [$names, $set];
    }

    /**
     * [module, legacy tables, legacy SQL, application SQL]; the first column is the period, then n, then the sums.
     *
     * @return list<array{string, string, string, string}>
     */
    private function checks(string $s): array
    {
        $from = fn (string $table) => "FROM legacy.rows WHERE snapshot_id = {$s} AND table_name = '{$table}'";
        $num = fn (string $f, string $d = 'data') => "coalesce(nullif(trim({$d}->>'{$f}'), '')::numeric, 0)";
        $sum = fn (string $e) => "trim_scale(coalesce(sum({$e}), 0))";
        // Month of a legacy text date as legacy normDate/parseDMY read it: «YYYY-MM-DD[ time]», «DD.MM.YYYY», «DD.MM.YY».
        $month = fn (string $e) => "CASE WHEN coalesce(trim({$e}), '') = '' THEN '—'
            WHEN trim({$e}) ~ '^\\d{4}-\\d{2}-\\d{2}' THEN left(trim({$e}), 7)
            WHEN trim({$e}) ~ '^\\d{1,2}\\.\\d{1,2}\\.(\\d{2}|\\d{4})$' THEN (CASE WHEN length(split_part(trim({$e}), '.', 3)) = 2 THEN '20' ELSE '' END)
                || split_part(trim({$e}), '.', 3) || '-' || lpad(split_part(trim({$e}), '.', 2), 2, '0')
            ELSE 'не дата' END";
        $tMonth = fn (string $e) => "coalesce(to_char({$e}, 'YYYY-MM'), '—')";
        $almaty = fn (string $e) => "to_char(({$e})::timestamptz AT TIME ZONE 'Asia/Almaty', 'YYYY-MM')";

        return [
            ['M14 Статьи расходов', 'expense_categories',
                "SELECT CASE WHEN data->>'parent_code' IS NULL THEN 'группы' ELSE 'статьи' END p, count(*) n,
                    count(*) FILTER (WHERE coalesce(data->>'active', 'true') <> 'false') active, count(*) FILTER (WHERE data->>'is_fixed' = 'true') fixed {$from('expense_categories')} GROUP BY 1",
                "SELECT CASE WHEN parent_id IS NULL THEN 'группы' ELSE 'статьи' END, count(*), count(*) FILTER (WHERE active), count(*) FILTER (WHERE is_fixed) FROM expense_categories GROUP BY 1"],
            ['M07 Каталог', 'products',
                "SELECT coalesce(nullif(trim(data->>'category'), ''), '—') p, count(*) n, count(*) FILTER (WHERE coalesce(data->>'active', 'true') <> 'false') active,
                    count(*) FILTER (WHERE data->>'delete_requested' = 'true') delete_requested, {$sum("round({$num('price_retail')}, 2)")} retail, {$sum("round({$num('price_wholesale')}, 2)")} wholesale {$from('products')} GROUP BY 1",
                "SELECT coalesce(nullif(category, ''), '—'), count(*), count(*) FILTER (WHERE active), count(*) FILTER (WHERE delete_requested), {$sum('price_retail')}, {$sum('price_wholesale')} FROM products GROUP BY 1"],
            ['M08 Покупатели', 'customers',
                "SELECT coalesce(nullif(data->>'channel', ''), 'office') || ' / ' || coalesce(nullif(data->>'type', ''), 'client') p, count(*) n {$from('customers')} GROUP BY 1",
                "SELECT channel || ' / ' || type, count(*) FROM customers GROUP BY 1"],
            ['M06 Поставщики', 'suppliers',
                "SELECT coalesce(nullif(data->>'status', ''), 'active') p, count(*) n {$from('suppliers')} GROUP BY 1",
                'SELECT status, count(*) FROM suppliers GROUP BY 1'],
            ['M03 Склад', 'warehouse',
                // price: JS Math.round(price * 100) / 100 on doubles (LegacyNumber::round2)
                "SELECT coalesce(nullif(trim(data->>'warehouse_id'), ''), '—') p, count(*) n, {$sum($num('qty'))} qty, {$sum($num('min_qty'))} min_qty,
                    {$sum("((floor((data->>'price')::float8 * 100) + CASE WHEN (data->>'price')::float8 * 100 - floor((data->>'price')::float8 * 100) >= 0.5 THEN 1 ELSE 0 END) / 100)::numeric")} price {$from('warehouse')} GROUP BY 1",
                "SELECT coalesce(warehouse_code, '—'), count(*), {$sum('qty')}, {$sum('min_qty')}, {$sum('price')} FROM materials GROUP BY 1"],
            ['M03 Синонимы материалов', 'material_aliases',
                // copies with the same material, alias and category are one alias (MaterialAliasImporter)
                "SELECT coalesce(nullif(trim(data->>'category'), ''), '—') p, count(DISTINCT (trim(data->>'material_name'), trim(data->>'alias_name'))) n {$from('material_aliases')} GROUP BY 1",
                "SELECT coalesce(nullif(category, ''), '—'), count(*) FROM material_aliases GROUP BY 1"],
            // ESF amounts: half up to kopecks, quantities to 3 decimals (IncomingInvoiceImporter::decimal)
            ['M05 Входящие ЭСФ', 'incoming_invoices',
                "SELECT {$month("data->>'date'")} p, count(*) n, {$sum("round({$num('total_amount')}, 2)")} amount, {$sum("round({$num('total_nds')}, 2)")} nds, {$sum("round({$num('total_with_nds')}, 2)")} with_nds {$from('incoming_invoices')} GROUP BY 1",
                "SELECT {$tMonth('date')}, count(*), {$sum('total_amount')}, {$sum('total_nds')}, {$sum('total_with_nds')} FROM incoming_invoices GROUP BY 1"],
            ['M05 Строки ЭСФ', 'incoming_invoice_items',
                "SELECT {$month("i.data->>'date'")} p, count(*) n, {$sum("round({$num('qty', 'r.data')}, 3)")} qty, {$sum("round({$num('amount', 'r.data')}, 2)")} amount,
                    {$sum("round({$num('nds_amount', 'r.data')}, 2)")} nds, {$sum("round({$num('total', 'r.data')}, 2)")} total FROM legacy.rows r JOIN legacy.rows i ON i.snapshot_id = r.snapshot_id AND i.table_name = 'incoming_invoices' AND i.data->>'id' = r.data->>'invoice_id'
                    WHERE r.snapshot_id = {$s} AND r.table_name = 'incoming_invoice_items' GROUP BY 1",
                "SELECT {$tMonth('i.date')}, count(*), {$sum('r.qty')}, {$sum('r.amount')}, {$sum('r.nds_amount')}, {$sum('r.total')} FROM incoming_invoice_items r JOIN incoming_invoices i ON i.id = r.invoice_id GROUP BY 1"],
            ['M05 Оплаты ЭСФ', 'esf_payment_links',
                "SELECT {$almaty("data->>'created_at'")} || ' ' || coalesce(data->>'source_type', '—') p, count(*) n, {$sum("round({$num('amount')}, 2)")} amount {$from('esf_payment_links')} GROUP BY 1",
                "SELECT {$almaty('created_at')} || ' ' || coalesce(source_type, '—'), count(*), {$sum('amount')} FROM esf_payment_links GROUP BY 1"],
            ['M10 Лиды', 'leads',
                // budget: bcadd(budget, 0.005, 2) — half up to kopecks (LeadImporter)
                "SELECT {$almaty("data->>'created_at'")} p, count(*) n, {$sum("trunc({$num('budget')} + 0.005, 2)")} budget {$from('leads')} GROUP BY 1",
                "SELECT {$almaty('created_at')}, count(*), {$sum('budget')} FROM leads GROUP BY 1"],
            ['M10 Задачи', 'tasks',
                "SELECT {$month("data->>'date'")} p, count(*) n, count(*) FILTER (WHERE data->>'status' IS DISTINCT FROM 'deleted') active {$from('tasks')} GROUP BY 1",
                "SELECT {$tMonth('due_date')}, count(*), count(*) FILTER (WHERE active) FROM crm_tasks GROUP BY 1"],
            ['M04 Заявки на материал', 'material_requests',
                "SELECT coalesce(nullif(trim(data->>'status'), ''), 'requested') p, count(*) n, {$sum($num('qty'))} qty {$from('material_requests')} GROUP BY 1",
                "SELECT status, count(*), {$sum('qty')} FROM material_requests GROUP BY 1"],
            ['M09 Заказы', 'orders',
                "SELECT {$month("data->>'created_date'")} p, count(*) n, {$sum("coalesce(nullif(data->>'payment', '')::int, 0)")} payment_pct {$from('orders')} GROUP BY 1",
                "SELECT {$tMonth('order_date')}, count(*), {$sum('payment_pct')} FROM orders GROUP BY 1"],
            ['M09 Позиции заказов', 'order_items',
                "SELECT {$month("o.data->>'created_date'")} p, count(*) n, {$sum("coalesce(nullif(r.data->>'qty', '')::numeric, 1)")} qty, {$sum($num('price', 'r.data'))} price,
                    {$sum("coalesce(nullif(r.data->>'qty', '')::numeric, 1) * {$num('price', 'r.data')}")} amount
                    FROM legacy.rows r LEFT JOIN legacy.rows o ON o.snapshot_id = r.snapshot_id AND o.table_name = 'orders' AND trim(o.data->>'num') = trim(r.data->>'order_num')
                    WHERE r.snapshot_id = {$s} AND r.table_name = 'order_items' GROUP BY 1",
                "SELECT {$tMonth('o.order_date')}, count(*), {$sum('r.qty')}, {$sum('r.price')}, {$sum('r.qty * r.price')} FROM order_items r JOIN orders o ON o.id = r.order_id GROUP BY 1"],
            ['M09 Реализации', 'realizations',
                "SELECT {$month("data->>'doc_date'")} p, count(*) n, {$sum($num('qty'))} qty, {$sum($num('revenue'))} revenue {$from('realizations')} GROUP BY 1",
                "SELECT {$tMonth('doc_date')}, count(*), {$sum('qty')}, {$sum('revenue')} FROM realizations GROUP BY 1"],
            ['M02 Прогресс производства', 'production_progress',
                "SELECT CASE WHEN data->>'completed' = 'true' THEN 'завершено' ELSE 'в работе' END p, count(*) n {$from('production_progress')} GROUP BY 1",
                "SELECT CASE WHEN completed THEN 'завершено' ELSE 'в работе' END, count(*) FROM production_progress GROUP BY 1"],
            ['M12 Операции по счетам', 'money_tx',
                "SELECT {$month("data->>'date'")} || ' ' || (data->>'flow') p, count(*) n, {$sum($num('amount'))} amount, {$sum("nullif(trim(data->>'amount_fx'), '')::numeric")} amount_fx,
                    count(DISTINCT data->>'account_id') accounts, count(*) FILTER (WHERE nullif(trim(data->>'subcategory'), '') IS NOT NULL) with_item {$from('money_tx')} GROUP BY 1",
                "SELECT {$tMonth('tx_date')} || ' ' || flow, count(*), {$sum('amount')}, {$sum('amount_fx')}, count(DISTINCT account_id), count(expense_category_id) FROM money_transactions GROUP BY 1"],
            ['M12 Оплаты заказов', 'order_payments',
                "SELECT {$almaty("data->>'created_at'")} p, count(*) n, {$sum($num('amount'))} amount {$from('order_payments')} GROUP BY 1",
                "SELECT {$almaty('created_at')}, count(*), {$sum('amount')} FROM order_payments GROUP BY 1"],
            ['M12 Авансовые отчёты', 'advance_reports',
                "SELECT {$month("data->>'date'")} p, count(*) n, {$sum($num('issued'))} issued, {$sum($num('spent'))} spent, {$sum($num('returned'))} returned,
                    {$sum("coalesce(jsonb_array_length(CASE WHEN jsonb_typeof(data->'lines') = 'array' THEN data->'lines' END), 0)")} lines {$from('advance_reports')} GROUP BY 1",
                "SELECT {$tMonth('report_date')}, count(*), {$sum('issued')}, {$sum('spent')}, {$sum('returned')}, {$sum('jsonb_array_length(lines)')} FROM advance_reports GROUP BY 1"],
            ['M12 Рабочий лист салонов', 'salon_worksheet',
                "SELECT (data->>'salon') || ' ' || {$month("data->>'order_date'")} p, count(*) n, {$sum($num('cash_amount'))} cash, {$sum($num('card_amount'))} card, {$sum($num('cod_amount'))} cod,
                    {$sum($num('expense_amount'))} expense, {$sum($num('transferred_to_office'))} to_office, count(*) FILTER (WHERE data->>'transferred_to_db' = 'true') transferred {$from('salon_worksheet')} GROUP BY 1",
                "SELECT salon || ' ' || {$tMonth('order_date')}, count(*), {$sum('cash_amount')}, {$sum('card_amount')}, {$sum('cod_amount')}, {$sum('expense_amount')}, {$sum('transferred_to_office')},
                    count(*) FILTER (WHERE transferred_to_db) FROM salon_worksheet_rows GROUP BY 1"],
            ['M12 Сверка с банком', 'bank_statement_raw',
                "SELECT {$month("data->>'date'")} || ' ' || (data->>'flow') p, count(*) n, {$sum($num('amount'))} amount, count(*) FILTER (WHERE data->>'transferred_to_db' = 'true') transferred {$from('bank_statement_raw')} GROUP BY 1",
                "SELECT {$tMonth('row_date')} || ' ' || flow, count(*), {$sum('amount')}, count(*) FILTER (WHERE transferred_to_db) FROM bank_statement_rows GROUP BY 1"],
            ['M17 Сотрудники', 'staff',
                "SELECT coalesce(nullif(data->>'type', ''), '—') p, count(*) n, {$sum($num('salary'))} salary, {$sum($num('base_salary'))} base_salary,
                    count(*) FILTER (WHERE coalesce(data->>'active', 'true') <> 'false') active {$from('staff')} GROUP BY 1",
                "SELECT coalesce(type, '—'), count(*), {$sum('coalesce(salary, 0)')}, {$sum('coalesce(base_salary, 0)')}, count(*) FILTER (WHERE active) FROM employees GROUP BY 1"],
            ['M17 История окладов, изменения условий', 'staff_salary_history, staff_changes',
                "SELECT table_name p, count(*) n, {$sum("coalesce(nullif(data->>'salary', '')::numeric, nullif(data->>'base_salary', '')::numeric, 0)")} salary
                    FROM legacy.rows WHERE snapshot_id = {$s} AND table_name IN ('staff_salary_history', 'staff_changes') GROUP BY 1",
                "SELECT 'staff_salary_history', count(*), {$sum('coalesce(salary, 0)')} FROM employee_salary_history HAVING count(*) > 0
                    UNION ALL SELECT 'staff_changes', count(*), {$sum('coalesce(base_salary, 0)')} FROM employee_changes HAVING count(*) > 0"],
            ['M20 Начисления и выплаты', 'salary_accruals',
                "SELECT {$month("data->>'date'")} || ' ' || (data->>'type') p, count(*) n, {$sum($num('amount'))} amount, {$sum($num('deducted'))} deducted, {$sum($num('net'))} net,
                    count(*) FILTER (WHERE jsonb_typeof(data->'details') = 'object') details {$from('salary_accruals')} GROUP BY 1",
                "SELECT {$tMonth('accrual_date')} || ' ' || type, count(*), {$sum('amount')}, {$sum('deducted')}, {$sum('net')}, count(details) FROM salary_accruals GROUP BY 1"],
            ['M20 Прежние версии начислений', 'salary_accruals_history',
                "SELECT {$month("data->>'date'")} p, count(*) n, {$sum($num('amount'))} amount, {$sum($num('net'))} net {$from('salary_accruals_history')} GROUP BY 1",
                "SELECT {$tMonth('accrual_date')}, count(*), {$sum('amount')}, {$sum('net')} FROM salary_accrual_changes GROUP BY 1"],
            ['M18 Сдельная оплата', 'wage_items',
                "SELECT {$month("data->>'date'")} p, count(*) n, {$sum($num('qty'))} qty, {$sum($num('qty').' * '.$num('rate'))} amount {$from('wage_items')} GROUP BY 1",
                "SELECT {$month('work_date')}, count(*), {$sum('qty')}, {$sum('qty * rate')} FROM piece_work_items GROUP BY 1"],
            ['M21 Ведомость салонов', 'salon_payroll',
                "SELECT (data->>'year') || '-' || lpad(data->>'month', 2, '0') p, count(*) n, {$sum($num('salary'))} salary, {$sum($num('commission'))} commission, {$sum($num('bonus'))} bonus,
                    {$sum($num('vacation'))} vacation, {$sum($num('advance'))} advance, {$sum($num('total'))} total, {$sum($num('remainder'))} remainder {$from('salon_payroll')} GROUP BY 1",
                "SELECT year || '-' || lpad(month::text, 2, '0'), count(*), {$sum('salary')}, {$sum('commission')}, {$sum('bonus')}, {$sum('vacation')}, {$sum('advance')}, {$sum('total')},
                    {$sum('remainder')} FROM salon_payroll_rows GROUP BY 1"],
            ['M09 Рабочий лист реализации', 'sales_worksheet',
                "SELECT {$month("data->>'doc_date'")} p, count(*) n, count(DISTINCT data->>'doc_id') notes, {$sum($num('amount'))} amount, {$sum($num('qty'))} qty,
                    count(*) FILTER (WHERE data->>'posted' = 'true') posted {$from('sales_worksheet')} GROUP BY 1",
                "SELECT {$tMonth('doc_date')}, count(*), count(DISTINCT doc_id), {$sum('amount')}, {$sum('qty')}, count(*) FILTER (WHERE posted) FROM sales_worksheet_rows GROUP BY 1"],
            // The month a line was put on the worksheet (Asia/Almaty), the lines and the distinct orders.
            ['M02 Рабочий лист производства', 'production_worksheet',
                "SELECT coalesce({$almaty("data->>'added_at'")}, '—') p, count(*) n, count(DISTINCT data->>'order_num') orders {$from('production_worksheet')} GROUP BY 1",
                "SELECT coalesce(to_char(added_at AT TIME ZONE 'Asia/Almaty', 'YYYY-MM'), '—'), count(*), count(DISTINCT order_num) FROM production_worksheet_rows GROUP BY 1"],
            // D-13: the records of app_users laid over the accounts of the built-in roles (active, with pages of their own).
            ['Пользователи (D-13)', 'app_users',
                "SELECT 'записи' p, count(*) n, count(*) FILTER (WHERE (data->>'active')::boolean IS DISTINCT FROM false OR lower(trim(data->>'login')) = 'director') active,
                    count(*) FILTER (WHERE jsonb_typeof(data->'pages') = 'array' AND jsonb_array_length(data->'pages') > 0) with_pages {$from('app_users')} GROUP BY 1",
                "SELECT 'записи', count(*), count(*) FILTER (WHERE u.is_active), count(*) FILTER (WHERE u.pages IS NOT NULL) FROM legacy_id_map m JOIN users u ON u.id = m.target_id
                    WHERE m.source_table = 'app_users' HAVING count(*) > 0", ],
            ['M10 Проекты', 'projects, project_calcs, project_comments, project_files',
                "SELECT table_name p, count(*) n FROM legacy.rows WHERE snapshot_id = {$s} AND table_name IN ('projects', 'project_calcs', 'project_comments', 'project_files') GROUP BY 1",
                "SELECT 'projects', count(*) FROM projects HAVING count(*) > 0 UNION ALL SELECT 'project_calcs', count(*) FROM project_calcs HAVING count(*) > 0
                    UNION ALL SELECT 'project_comments', count(*) FROM project_comments HAVING count(*) > 0 UNION ALL SELECT 'project_files', count(*) FROM project_files HAVING count(*) > 0", ],
        ];
    }
}
