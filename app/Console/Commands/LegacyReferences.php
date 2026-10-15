<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The references of the legacy snapshot and what the import does with each (ТЗ MIG-02: links by stable ids; ambiguous
 * matches of names and numbers go to the report, nothing is chosen at random). For every reference of a migrated table:
 * how many there are, how many find exactly one target, none (unresolved) or several (ambiguous), and the import's rule —
 * refuse the set, keep the value as text without a link, or link only a single match. Also the repeated names and numbers
 * the import keeps apart. Read-only; the console shows counts, --out keeps the legacy keys (owner-only, outside Git).
 */
class LegacyReferences extends Command
{
    protected $signature = 'legacy:references {--snapshot= : Snapshot id (default: the latest loaded)} {--out= : JSON with the unresolved and ambiguous keys}';

    protected $description = 'Report the references of the legacy snapshot: resolved, unresolved, ambiguous, and how the import treats them';

    private const REFUSE = 'набор отклоняется целиком — исправить в legacy до переноса';

    private const TEXT = 'переносится без связи, значение сохранено текстом';

    private const NONE = 'переносится без связи';

    private const SINGLE = 'связь только при единственном совпадении; при нескольких — без связи (не выбирается)';

    /** [name, child table, child value SQL, child filter SQL, parent table, parent key SQL, rule] */
    private const REFERENCES = [
        ['Строка заказа → заказ', 'order_items', "data->>'order_num'", null, 'orders', "data->>'num'", self::REFUSE],
        ['Реализация → заказ', 'realizations', "data->>'order_num'", null, 'orders', "data->>'num'", self::TEXT],
        ['Прогресс производства → заказ', 'production_progress', "data->>'order_num'", null, 'orders', "data->>'num'", self::REFUSE],
        ['Операция → заказ', 'money_tx', "data->>'order_num'", null, 'orders', "data->>'num'", self::TEXT],
        ['Оплата заказа → заказ', 'order_payments', "data->>'order_num'", null, 'orders', "data->>'num'", self::TEXT],
        ['Оплата заказа → операция', 'order_payments', "data->>'tx_id'", null, 'money_tx', "data->>'id'", self::REFUSE],
        ['Авансовый отчёт → операция выдачи', 'advance_reports', "data->>'tx_id'", null, 'money_tx', "data->>'id'", self::NONE],
        ['Операция → статья расходов', 'money_tx', "data->>'subcategory'", null, 'expense_categories', "data->>'code'", self::REFUSE],
        ['Поставщик → статья расходов', 'suppliers', "coalesce(nullif(data->>'expense_subcategory', ''), data->>'expense_category')", null, 'expense_categories', "data->>'code'", self::REFUSE],
        ['Задача → лид', 'tasks', "data->>'lead_id'", null, 'leads', "data->>'id'", self::REFUSE],
        ['Строка ЭСФ → ЭСФ', 'incoming_invoice_items', "data->>'invoice_id'", null, 'incoming_invoices', "data->>'id'", self::REFUSE],
        ['Оплата ЭСФ → ЭСФ', 'esf_payment_links', "data->>'esf_invoice_id'", null, 'incoming_invoices', "data->>'id'", self::REFUSE],
        ['Оплата ЭСФ → операция', 'esf_payment_links', "data->>'source_id'", "data->>'source_type' = 'money_tx'", 'money_tx', "data->>'id'", self::TEXT],
        ['Оплата ЭСФ → авансовый отчёт', 'esf_payment_links', "data->>'source_id'", "data->>'source_type' = 'advance_report'", 'advance_reports', "data->>'id'", self::TEXT],
        ['ЭСФ → поставщик по БИН', 'incoming_invoices', "data->>'supplier_bin'", null, 'suppliers', "data->>'bin'", self::SINGLE],
        ['Заказ → покупатель по названию', 'orders', "data->>'customer'", null, 'customers', "data->>'name'", 'заказ хранит покупателя текстом, как legacy; оборот на «Покупателях» — по названию'],
        ['Строка выписки → операция журнала', 'bank_statement_raw', "data->>'transferred_tx_id'", null, 'money_tx', "data->>'id'", self::NONE],
        ['Рабочий лист салона → заказ', 'salon_worksheet', "data->>'order_num'", null, 'orders', "data->>'num'", self::TEXT],
        ['Синоним → материал склада', 'material_aliases', "data->>'material_name'", null, 'warehouse', "data->>'name'", self::TEXT],
    ];

    /** [name, table, key SQL, rule] — values several rows share */
    private const REPEATS = [
        ['Номер заказа', 'orders', "data->>'num'", self::REFUSE],
        ['Название покупателя', 'customers', "data->>'name'", 'покупатели остаются отдельными записями (каналы могут отличаться)'],
        ['Название поставщика', 'suppliers', "data->>'name'", 'поставщики остаются отдельными записями; объединение — вручную (ADR-0007)'],
        ['БИН поставщика', 'suppliers', "data->>'bin'", 'ЭСФ с этим БИН не связываются с поставщиком'],
        ['Название товара', 'products', "data->>'name'", 'товары остаются отдельными записями'],
    ];

    public function handle(): int
    {
        $snapshot = (string) ($this->option('snapshot') ?: DB::table('legacy.snapshots')->max('id'));
        if (! DB::table('legacy.snapshots')->where('id', $snapshot)->exists()) {
            $this->error('No loaded snapshot: run legacy:snapshot and legacy:load first.');

            return self::FAILURE;
        }
        $result = ['snapshot' => $snapshot, 'checked_at' => now()->toIso8601String(), 'references' => [], 'repeats' => []];
        $rows = [];
        foreach (self::REFERENCES as [$name, $child, $value, $filter, $parent, $key, $rule]) {
            $refs = DB::select("
                WITH parent AS (SELECT nullif(trim({$key}), '') AS key, count(*) AS n FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? GROUP BY 1),
                child AS (SELECT row_no, data->>'id' AS id, nullif(trim({$value}), '') AS ref FROM legacy.rows
                    WHERE snapshot_id = ? AND table_name = ?".($filter ? " AND {$filter}" : '').')
                SELECT c.id, c.ref, coalesce(p.n, 0) AS n FROM child c LEFT JOIN parent p ON p.key = c.ref WHERE c.ref IS NOT NULL ORDER BY c.row_no',
                [$snapshot, $parent, $snapshot, $child]);
            $unresolved = array_values(array_filter($refs, fn ($r) => (int) $r->n === 0));
            $ambiguous = array_values(array_filter($refs, fn ($r) => (int) $r->n > 1));
            $result['references'][] = ['name' => $name, 'from' => $child, 'to' => $parent, 'rule' => $rule, 'references' => count($refs),
                'resolved' => count($refs) - count($unresolved) - count($ambiguous), 'unresolved' => count($unresolved), 'ambiguous' => count($ambiguous),
                'unresolved_keys' => array_map(fn ($r) => ['id' => $r->id, 'ref' => $r->ref], $unresolved),
                'ambiguous_keys' => array_map(fn ($r) => ['id' => $r->id, 'ref' => $r->ref, 'matches' => (int) $r->n], $ambiguous)];
            $rows[] = [$name, count($refs), count($refs) - count($unresolved) - count($ambiguous), count($unresolved), count($ambiguous), $rule];
        }
        $repeatRows = [];
        foreach (self::REPEATS as [$name, $table, $key, $rule]) {
            $groups = DB::select("SELECT nullif(trim({$key}), '') AS key, count(*) AS n FROM legacy.rows WHERE snapshot_id = ? AND table_name = ?
                GROUP BY 1 HAVING count(*) > 1 AND nullif(trim({$key}), '') IS NOT NULL ORDER BY 2 DESC, 1", [$snapshot, $table]);
            $result['repeats'][] = ['name' => $name, 'table' => $table, 'rule' => $rule, 'values' => count($groups), 'rows' => array_sum(array_map(fn ($g) => (int) $g->n, $groups)),
                'keys' => array_map(fn ($g) => ['value' => $g->key, 'rows' => (int) $g->n], $groups)];
            $repeatRows[] = [$name, count($groups), array_sum(array_map(fn ($g) => (int) $g->n, $groups)), $rule];
        }

        $this->table(['Ссылка', 'Всего', 'Найдено', 'Не найдено', 'Неоднозначно', 'Как переносится'], $rows);
        $this->table(['Повторяется', 'Значений', 'Строк', 'Как переносится'], $repeatRows);
        if ($out = $this->option('out')) {
            file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            chmod($out, 0600);
            $this->line("Keys of the unresolved and ambiguous references (keep out of Git): {$out}");
        }
        $refused = collect($result['references'])->filter(fn (array $r) => $r['rule'] === self::REFUSE && ($r['unresolved'] + $r['ambiguous']) > 0)->pluck('name');
        $refused = $refused->merge(collect($result['repeats'])->filter(fn (array $r) => $r['rule'] === self::REFUSE && $r['values'] > 0)->pluck('name'));
        if ($refused->isNotEmpty()) {
            $this->error('The import will refuse: '.$refused->implode(', '));

            return self::FAILURE;
        }
        $this->info('Every reference is resolved or has its rule; the import will not refuse the snapshot '.$snapshot);

        return self::SUCCESS;
    }
}
