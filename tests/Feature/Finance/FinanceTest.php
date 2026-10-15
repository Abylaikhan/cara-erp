<?php

namespace Tests\Feature\Finance;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M11 «Финансы», part 1: orders for «Дебиторка», operations in tenge for «ДДС» (loaded with the tab), access. */
class FinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_with_totals_and_operations_in_tenge_with_the_tab(): void
    {
        $order = new Order(['num' => 'МО-1', 'channel' => 'dealer_astana', 'customer' => 'Демо', 'seller' => 'Демо Продавец', 'status' => 'progress', 'payment_pct' => 40,
            'order_date' => '2026-09-01', 'due_date' => '2026-10-20', 'payment_due' => '2026-10-10']);
        $order->save();
        $order->items()->create(['line_no' => 0, 'name' => 'Демо', 'qty' => '2.5', 'price' => '1000.10', 'progress' => 0, 'status' => 'progress', 'attrs' => []]);
        $kzt = DB::table('money_accounts')->insertGetId(['name' => 'Касса Демо', 'type' => 'cash', 'currency' => 'KZT', 'icon' => '💵', 'opening_balance' => '500.50', 'sort_order' => 0]);
        $usd = DB::table('money_accounts')->insertGetId(['name' => 'Счёт USD Демо', 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => 0, 'sort_order' => 1]);
        DB::table('money_transactions')->insert([
            ['account_id' => $kzt, 'tx_date' => '2026-10-01', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '300', 'amount_fx' => '0.6', 'description' => 'Демо приход'],
            ['account_id' => $usd, 'tx_date' => '2026-10-02', 'flow' => 'expense', 'category' => 'supplier', 'amount' => '2', 'amount_fx' => '1000.50', 'description' => null],
        ]);
        DB::table('expense_categories')->insert(['code' => 'demo_item', 'name' => 'Демо статья', 'sort_order' => 1]);

        $this->actingAs(User::factory()->role('accountant')->create())->get('/finance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance')
            ->where('orders.0', ['num' => 'МО-1', 'channel' => 'dealer_astana', 'customer' => 'Демо', 'seller' => 'Демо Продавец', 'date' => '2026-10-20', 'created' => '2026-09-01',
                'payment_due' => '2026-10-10', 'payment' => 40, 'status' => 'progress', 'total' => '2500.250000'])
            ->where('accounts.0.opening', '500.50')
            ->where('categoryNames.demo_item', 'Демо статья')
            ->where('txLabels.supplier', '🏭 Поставщику')
            ->where('dataMonths', ['lo' => '2026-10', 'hi' => '2026-10']) // an order counts by its date, not its creation (legacy)
            ->missing('transactions')
            ->reloadOnly('transactions', fn (Assert $reload) => $reload->where('transactions', fn ($t) => collect($t)->map(fn ($x) => [$x['date'], $x['flow'], $x['amount']])->all()
                == [['2026-10-02', 'expense', '1000.50'], ['2026-10-01', 'income', '300.00']])));
    }

    public function test_roles_without_the_page_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->role('manager')->create())->get('/finance')->assertForbidden();
    }
}
