<?php

namespace Tests\Feature\Finance;

use App\Models\IncomingInvoice;
use App\Models\User;
use App\Services\Finance\OsvReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** «Финансы» → «📋 ОСВ»: the entries of legacy buildOSV on synthetic data (№ 36 — ADR-0053). */
class OsvReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_trial_balance_of_a_period_as_legacy_posts_it(): void
    {
        $cash = DB::table('money_accounts')->insertGetId(['name' => 'Касса Демо', 'type' => 'cash', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        $bank = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 1]);
        $tx = fn (int $acc, string $date, string $flow, string $category, string $amount) => DB::table('money_transactions')->insert(['account_id' => $acc, 'tx_date' => $date,
            'flow' => $flow, 'category' => $category, 'amount' => $amount, 'order_num' => $category === 'order_payment' ? 'МО-1' : null]);
        $tx($cash, '2031-02-20', 'income', 'order_payment', '400');   // before the period: opening, and once more in the balance of the cash desk
        $tx($bank, '2031-03-05', 'income', 'order_payment', '1000');
        $tx($bank, '2031-03-06', 'expense', 'supplier', '300');
        $tx($bank, '2031-03-07', 'expense', 'transfer_out', '50');    // no entry
        $tx($cash, '2031-03-08', 'expense', 'household', '20');       // any other expense — 91.2
        $tx($bank, '2031-04-01', 'expense', 'salary', '999');         // after the period
        DB::table('realizations')->insert(['doc_date' => '2031-03-10', 'name' => 'Демо', 'qty' => 1, 'revenue' => '900', 'order_num' => 'МО-1']);
        IncomingInvoice::create(['num' => '7', 'date' => '2031-03-11', 'status' => 'DELIVERED', 'invoice_type' => 'service', 'supplier_name' => 'ТОО Демо', 'total_amount' => 0, 'total_nds' => 0, 'total_with_nds' => '120']);
        DB::table('advance_reports')->insert(['doc_num' => 'АО-1', 'employee_name' => 'Демо Сотрудник', 'report_date' => '2031-03-12', 'issued' => 0, 'spent' => 0, 'returned' => 0, 'status' => 'closed',
            'lines' => json_encode([['type' => 'expense', 'amount' => 15], ['type' => 'material', 'amount' => 99]])]);

        $osv = (new OsvReport)->build('2031-03-01', '2031-03-31');
        $rows = collect($osv['rows'])->keyBy('code')->map(fn ($r) => [$r['openDt'], $r['openKt'], $r['dtOb'], $r['ktOb'], $r['closeDt'], $r['closeKt']])->all();
        $this->assertEquals(['50' => [800.0, 0.0, 0.0, 20.0, 780.0, 0.0], '51' => [0.0, 0.0, 1000.0, 300.0, 700.0, 0.0], '60' => [0.0, 0.0, 300.0, 120.0, 180.0, 0.0],
            '62' => [0.0, 400.0, 900.0, 1000.0, 0.0, 500.0], '71' => [0.0, 0.0, 0.0, 15.0, 0.0, 15.0], '90.1' => [0.0, 0.0, 0.0, 900.0, 0.0, 900.0],
            '91.2' => [0.0, 0.0, 35.0, 0.0, 35.0, 0.0], '7210' => [0.0, 0.0, 120.0, 0.0, 120.0, 0.0], '84' => [0.0, 400.0, 0.0, 0.0, 0.0, 400.0]],
            array_intersect_key($rows, array_flip(['50', '51', '60', '62', '71', '90.1', '91.2', '7210', '84'])));
        $this->assertSame(['50', '51', '60', '62', '71', '7210', '84', '90.1', '91.2'], array_column($osv['rows'], 'code'), 'by code, as text');
        $this->assertSame([800.0, 800.0], [$osv['totals']['openDt'], $osv['totals']['openKt']], 'the totals count the balancing of 84');
        $this->assertSame([['51', '62', 1000.0, 'МО-1']], array_map(fn ($e) => [$e['dt'], $e['kt'], $e['amount'], $e['doc']], array_values(array_filter($osv['journal'], fn ($e) => $e['kt'] === '62'))));
    }

    public function test_the_page_gets_the_trial_balance_and_the_entries_of_an_account(): void
    {
        $bank = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        DB::table('money_transactions')->insert(['account_id' => $bank, 'tx_date' => '2031-03-05', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '10']);
        $this->actingAs(User::factory()->role('accountant')->create());
        $version = $this->get('/finance')->viewData('page')['version'];
        $h = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Finance'];
        $r = $this->get('/finance?osv_from=2031-03-01&osv_to=2031-03-31', [...$h, 'X-Inertia-Partial-Data' => 'osv']);
        $this->assertSame(['2031-03-01', ['51', '62'], null], [$r->json('props.osv.from'), array_column($r->json('props.osv.rows'), 'code'), $r->json('props.osv.journal')]);
        $r = $this->get('/finance?osv_account=62&osv_from=2031-03-01&osv_to=2031-03-31', [...$h, 'X-Inertia-Partial-Data' => 'osvAccount']);
        $this->assertSame(['62', 1], [$r->json('props.osvAccount.account'), count($r->json('props.osvAccount.entries'))]);
        $r = $this->get('/finance?osv_account=99', [...$h, 'X-Inertia-Partial-Data' => 'osvAccount']);
        $this->assertSame([], $r->json('props.osvAccount.entries'), 'an account not in the chart has no entries');
    }
}
