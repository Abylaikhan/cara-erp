<?php

/*
 * make pl-compare (ADR-0052): what legacy «📊 P&L» reads, in its own shapes, and what the server counts, for one year — JSON on
 * stdout, run inside the app container by tests/visual/pl_compare.py. Local only: the output holds real data and is written to
 * .local-audit, never to the repository or a log.
 */

use App\Models\Supplier;
use App\Services\Finance\OsvReport;
use App\Services\Finance\PlExpenses;
use App\Services\Finance\PlReport;
use App\Services\Finance\PlRevenue;
use Illuminate\Support\Facades\DB;

$year = getenv('PL_YEAR') ?: now(config('erp.business_timezone'))->format('Y');
$num = fn ($v) => $v === null ? 0 : (float) $v;
$invKey = DB::table('legacy_id_map')->where('source_table', 'incoming_invoices')->pluck('source_key', 'target_id');
$cats = DB::table('expense_categories as c')->leftJoin('expense_categories as p', 'p.id', '=', 'c.parent_id')->where('c.active', true)->orderBy('c.sort_order')->orderBy('c.id')
    ->get(['c.code', 'c.name', 'p.code as parent_code']);
$catById = DB::table('expense_categories')->pluck('code', 'id');
$parentOf = $cats->pluck('parent_code', 'code');

$legacy = [
    'realizations' => DB::table('realizations')->orderByDesc('doc_date')->orderBy('id')->get(['doc_date', 'revenue', 'order_num', 'customer', 'name'])
        ->map(fn ($r) => ['doc_date' => $r->doc_date, 'revenue' => $num($r->revenue), 'order_num' => $r->order_num, 'customer' => $r->customer, 'name' => $r->name]),
    'orders' => DB::table('orders')->orderByDesc('created_at')->orderByDesc('id')->get(['num', 'channel', 'customer', 'status', 'payment_pct'])
        ->map(fn ($o) => ['num' => $o->num, 'channel' => $o->channel, 'customer' => $o->customer, 'status' => $o->status, 'payment' => (int) $o->payment_pct, 'items' => [], 'date' => '', 'createdDate' => '']),
    'moneyTx' => DB::table('money_transactions')->orderByDesc('tx_date')->orderBy('id')->get(['id', 'account_id', 'tx_date', 'flow', 'category', 'expense_category_id', 'counterparty', 'bin', 'amount', 'description', 'order_num'])
        ->map(fn ($t) => ['id' => $t->id, 'accountId' => (string) $t->account_id, 'date' => $t->tx_date, 'flow' => $t->flow, 'category' => $t->category, 'subcategory' => $t->expense_category_id ? ($catById[$t->expense_category_id] ?? '') : '',
            'counterparty' => $t->counterparty ?? '', 'bin' => $t->bin ?? '', 'amount' => $num($t->amount), 'desc' => $t->description ?? '', 'orderNum' => $t->order_num ?? '']),
    'accounts' => DB::table('money_accounts')->orderBy('sort_order')->get(['id', 'type'])->map(fn ($a) => ['id' => (string) $a->id, 'type' => $a->type, 'balance' => 0]),
    'advances' => DB::table('advance_reports')->orderByDesc('report_date')->orderBy('id')->get(['report_date', 'employee_name', 'lines'])
        ->map(fn ($a) => ['date' => $a->report_date, 'emp_name' => $a->employee_name, 'lines' => json_decode((string) $a->lines, true) ?: []]),
    'cats' => $cats->map(fn ($c) => ['code' => $c->code, 'name' => $c->name, 'parent_code' => $c->parent_code]),
    'suppliers' => Supplier::query()->active()->orderBy('name')->orderBy('id')->get(['name', 'bin', 'expense_category_id', 'expense_source'])->map(function ($s) use ($catById, $parentOf) {
        $code = $s->expense_category_id ? ($catById[$s->expense_category_id] ?? null) : null;
        $parent = $code ? ($parentOf[$code] ?? null) : null;

        return ['name' => $s->name, 'bin' => $s->bin, 'expense_category' => $parent ?? $code, 'expense_subcategory' => $parent ? $code : null, 'expense_source' => $s->expense_source];
    }),
    'invoices' => DB::table('incoming_invoices')->orderByDesc('date')->orderBy('id')->get(['id', 'num', 'date', 'invoice_type', 'supplier_name', 'supplier_bin', 'total_with_nds'])
        ->map(fn ($i) => ['id' => $invKey[$i->id] ?? 'inv-'.$i->id, 'num' => $i->num, 'date' => $i->date, 'invoice_type' => $i->invoice_type, 'supplier_name' => $i->supplier_name,
            'supplier_bin' => $i->supplier_bin, 'total_with_nds' => $num($i->total_with_nds)]),
    'items' => DB::table('incoming_invoice_items')->orderBy('invoice_id')->orderBy('line_no')->get(['invoice_id', 'description', 'total', 'amount'])
        ->map(fn ($l) => ['invoice_id' => $invKey[$l->invoice_id] ?? 'inv-'.$l->invoice_id, 'description' => $l->description, 'total' => $num($l->total), 'amount' => $num($l->amount)]),
    'warehouse' => DB::table('materials')->where('active', true)->orderBy('name')->orderBy('id')->get(['name', 'category', 'warehouse_code'])
        ->map(fn ($m) => ['name' => $m->name, 'category' => $m->category, 'warehouse_id' => $m->warehouse_code]),
    'accruals' => DB::table('salary_accruals')->where('type', 'salary')->orderBy('accrual_date')->orderBy('emp_name')->get(['accrual_date', 'amount', 'details'])
        ->map(fn ($a) => ['date' => $a->accrual_date, 'amount' => $num($a->amount), 'details' => json_decode((string) $a->details, true)]),
    'catalog' => DB::table('products')->where('active', true)->orderBy('category')->orderBy('name')->orderBy('id')->get(['name', 'category']),
];
$report = new PlReport;
$revenue = new PlRevenue;
$target = [
    'year' => $year,
    'pl' => $report->build("{$year}-01-01", "{$year}-12-31"),
    'monthly' => $report->monthly($year, now(config('erp.business_timezone'))->format('Y-m')),
    'revChannel' => $revenue->byChannel($year),
    'revCategory' => $revenue->byCategory($year),
    'tree' => $revenue->tree($year),
    'cost' => $revenue->costEstimate($year),
    'expenses' => (new PlExpenses)->build($year),
    'osv' => (new OsvReport)->build("{$year}-01-01", "{$year}-12-31"),
    'catMargin' => $revenue->categoryMargin($year),
];
echo json_encode(['legacy' => $legacy, 'target' => $target], JSON_UNESCAPED_UNICODE);
