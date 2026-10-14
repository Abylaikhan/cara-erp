<?php

namespace Tests\Feature\Money;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M12 «Деньги», part 1: accounts and operations as the page shows them (view only), advance debt, access. */
class MoneyJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_lists_accounts_operations_with_orders_esf_and_the_advance_debt(): void
    {
        $cash = DB::table('money_accounts')->insertGetId(['name' => 'Касса Демо', 'type' => 'cash', 'currency' => 'KZT', 'icon' => '💵', 'opening_balance' => 0, 'sort_order' => 0]);
        $bank = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'bank' => 'АО «Демо»', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => '100.50', 'sort_order' => 1]);
        $item = DB::table('expense_categories')->insertGetId(['code' => 'demo_item', 'name' => 'Демо статья']);
        $itemName = 'Демо статья';
        $older = DB::table('money_transactions')->insertGetId(['account_id' => $cash, 'tx_date' => '2026-10-01', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '1000.25', 'order_num' => 'МО-1', 'counterparty' => 'Демо']);
        $newer = DB::table('money_transactions')->insertGetId(['account_id' => $bank, 'tx_date' => '2026-10-02', 'flow' => 'expense', 'category' => 'supplier', 'expense_category_id' => $item, 'amount' => '300', 'amount_fx' => '0.6', 'fx_currency' => 'USD']);
        DB::table('order_payments')->insert(['transaction_id' => $older, 'order_num' => 'МО-2', 'amount' => '500']);
        DB::table('legacy_id_map')->insert(['source_table' => 'money_tx', 'source_key' => 'tx-legacy-1', 'target_table' => 'money_transactions', 'target_id' => $newer, 'snapshot_id' => 'test']);
        $invoice = DB::table('incoming_invoices')->insertGetId(['num' => 'Демо-1', 'date' => '2026-10-01', 'status' => 'Принят', 'supplier_name' => 'Демо', 'total_amount' => '300', 'total_nds' => '0', 'total_with_nds' => '300']);
        DB::table('esf_payment_links')->insert(['invoice_id' => $invoice, 'source_type' => 'money_tx', 'source_legacy_id' => 'tx-legacy-1', 'amount' => '120', 'created_at' => now()]);
        foreach ([['open', '1000', '400'], ['open', '100', '150'], ['closed', '900', '0']] as $i => [$status, $issued, $spent]) {
            DB::table('advance_reports')->insert(['employee_name' => 'Демо '.$i, 'report_date' => '2026-10-01', 'issued' => $issued, 'spent' => $spent, 'status' => $status]);
        }

        $this->actingAs(User::factory()->role('accountant')->create())->get('/money')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Money')
            ->where('accounts', fn ($a) => collect($a)->pluck('name')->all() === ['Касса Демо', 'Банк Демо'] && $a[1]['opening'] == 100.5 && ! isset($a[1]['iin']))
            ->where('transactions.0.id', $newer)
            ->where('transactions.0.item', $itemName)
            ->where('transactions.0.fx', 0.6)
            ->where('transactions.0.esf', 120)
            ->where('transactions.1.orders', ['МО-1', 'МО-2'])
            ->where('transactions.1.order', 'МО-1')
            ->where('transactions.1.amount', 1000.25)
            ->where('advanceDebt', 600)
            ->where('categories.income.order_payment', ['📦', 'От покупателя']));
    }

    public function test_roles_without_the_page_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->role('manager')->create())->get('/money')->assertForbidden();
        $this->actingAs(User::factory()->role('director')->create())->get('/money')->assertOk();
    }

    public function test_advances_load_with_their_tab_spent_by_the_lines(): void
    {
        $acc = DB::table('money_accounts')->insertGetId(['name' => 'Касса Демо', 'type' => 'cash', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        $tx = DB::table('money_transactions')->insertGetId(['account_id' => $acc, 'tx_date' => '2026-10-01', 'flow' => 'expense', 'category' => 'advance_out', 'amount' => '5000']);
        DB::table('advance_reports')->insert([
            ['doc_num' => 'АО-1', 'employee_name' => 'Демо Первый', 'report_date' => '2026-10-01', 'issued' => '5000', 'spent' => '100', 'returned' => '0', 'status' => 'reported',
                'transaction_id' => $tx, 'lines' => json_encode([['amount' => 1200.5, 'esfInvoiceId' => 7], ['amount' => 300]])],
            ['doc_num' => 'АО-2', 'employee_name' => 'Демо Второй', 'report_date' => '2026-10-05', 'issued' => '1000', 'spent' => '400', 'returned' => '0', 'status' => 'open',
                'transaction_id' => null, 'lines' => '[]'],
        ]);

        $this->actingAs(User::factory()->role('director')->create())->get('/money')->assertInertia(fn (Assert $page) => $page
            ->missing('advances')
            ->reloadOnly('advances', fn (Assert $reload) => $reload
                ->where('advances.0.doc_num', 'АО-2')
                ->where('advances.0.spent', 400)
                ->where('advances.1.spent', 1500.5)
                ->where('advances.1.esf', 1)
                ->where('advances.1.issue_doc', ['date' => '2026-10-01', 'amount' => 5000])));
    }
}
