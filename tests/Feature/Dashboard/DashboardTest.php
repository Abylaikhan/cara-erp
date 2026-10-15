<?php

namespace Tests\Feature\Dashboard;

use App\Models\Material;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M01 «Дашборд»: monthly sums of the migrated data, counted exactly; the legacy fixes (orders in work, tenge on a dollar account). */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_sums_counts_and_critical_stock(): void
    {
        $kzt = DB::table('money_accounts')->insertGetId(['name' => 'Касса Демо', 'type' => 'cash', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        $usd = DB::table('money_accounts')->insertGetId(['name' => 'Счёт USD Демо', 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => 0, 'sort_order' => 1]);
        $tx = fn (int $acc, string $date, string $flow, ?string $cat, string $amount, ?string $fx = null) => DB::table('money_transactions')->insert(
            ['account_id' => $acc, 'tx_date' => $date, 'flow' => $flow, 'category' => $cat, 'amount' => $amount, 'amount_fx' => $fx]);
        $tx($kzt, '2026-09-05', 'expense', 'supplier', '100.10');
        $tx($kzt, '2026-09-20', 'expense', 'supplier', '0.20');
        $tx($usd, '2026-10-01', 'expense', 'supplier', '2', '1000.50'); // dollars; the tenge equivalent is counted
        $tx($kzt, '2026-10-02', 'expense', 'salary_prod_piece', '300', '0.6'); // tenge; amount_fx are dollars for reference
        $tx($kzt, '2026-10-03', 'expense', 'salary_transfer', '50');
        $tx($kzt, '2026-10-04', 'expense', 'advance_out', '5000');
        $tx($kzt, '2026-10-05', 'expense', 'transfer_out', '70');
        $tx($kzt, '2026-10-06', 'income', 'order_payment', '900');
        $tx($kzt, '2026-10-07', 'income', 'transfer_in', '70');
        $tx($kzt, '2026-10-08', 'expense', null, '5');
        DB::table('expense_categories')->insert(['code' => 'demo_item', 'name' => 'Демо статья', 'sort_order' => 1]);
        DB::table('advance_reports')->insert(['doc_num' => 'АО-1', 'employee_name' => 'Демо', 'report_date' => '2026-10-10', 'issued' => '5000', 'spent' => '0', 'returned' => '0', 'status' => 'open',
            'lines' => json_encode([['type' => 'expense', 'category' => 'demo_item', 'amount' => 1200.5], ['type' => 'supplier', 'amount' => '300'], ['type' => 'expense', 'category' => 'demo_item', 'amount' => 0.25]])]);
        DB::table('incoming_invoices')->insert(['num' => 'Демо-1', 'date' => '2026-09-01', 'status' => 'Принят', 'supplier_name' => 'Демо', 'total_amount' => '2000', 'total_nds' => '0', 'total_with_nds' => '2000']);
        foreach ([['МО-1', 'progress'], ['МО-2', 'queue'], ['МО-3', 'queue'], ['МО-4', 'done']] as [$num, $status]) {
            (new Order(['num' => $num, 'channel' => 'office', 'customer' => 'Демо', 'status' => $status, 'payment_pct' => 0]))->save();
        }
        DB::table('realizations')->insert([['doc_date' => '2026-09-30', 'name' => 'Демо', 'qty' => 1, 'revenue' => '100.25'], ['doc_date' => '2026-10-01', 'name' => 'Демо', 'qty' => 1, 'revenue' => '200.50'],
            ['doc_date' => '2026-10-31', 'name' => 'Демо', 'qty' => 1, 'revenue' => '0.25']]);
        foreach ([['Б-критично', '5', '10'], ['А-мало', '7', '10'], ['В-без нормы', '0', '0']] as [$name, $qty, $min]) {
            Material::query()->forceCreate(['name' => $name, 'category' => 'Демо', 'unit' => 'шт', 'qty' => $qty, 'price' => 0, 'min_qty' => $min]);
        }

        $this->actingAs(User::factory()->role('director')->create())->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('revenue', ['2026-09' => '100.25', '2026-10' => '200.75'])
            ->where('ordersInWork', 1)
            ->where('ordersQueue', 2)
            ->where('stockCount', 3)
            ->where('criticalCount', 1)
            ->where('critical', [['name' => 'Б-критично', 'qty' => '5.000', 'unit' => 'шт']])
            ->where('supplierPaid', ['2026-09' => '100.30', '2026-10' => '1000.50'])
            ->where('supplierDebt', '899.20')
            ->where('fotPaid', ['2026-10' => '300.00'])
            ->where('cashIn', ['2026-10' => '900.00'])
            ->where('cashOut', ['2026-09' => '100.30', '2026-10' => '6355.50'])
            ->where('expenses.2026-10', fn ($e) => collect($e)->all() == ['🏭 Поставщику' => '1000.50', 'salary_prod_piece' => '300.00', '💼 Перевод на кассу ЗП' => '50.00',
                '↔️ На другой счёт' => '70.00', 'Прочее' => '5.00', 'Демо статья (аванс)' => '1200.75', 'Оплата поставщику (аванс)' => '300.00'])
            ->has('today'));
    }

    public function test_roles_without_the_dashboard_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->role('dealer_astana')->create())->get('/dashboard')->assertForbidden();
    }
}
