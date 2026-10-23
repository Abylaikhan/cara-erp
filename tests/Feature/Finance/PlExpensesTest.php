<?php

namespace Tests\Feature\Finance;

use App\Models\IncomingInvoice;
use App\Models\Supplier;
use App\Services\Finance\PlExpenses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** «📊 P&L» → «Расходы по категориям и статьям» (legacy renderExpensesByMonth; ADR-0052). */
class PlExpensesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expenses_by_category_and_article_dividends_apart(): void
    {
        $acc = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        $cat = fn (string $code, string $name, ?string $parent = null) => DB::table('expense_categories')->insertGetId(['code' => $code, 'name' => $name, 'sort_order' => 0,
            'parent_id' => $parent ? DB::table('expense_categories')->where('code', $parent)->value('id') : null]);
        $cat('household', 'Хозяйственные');
        $rent = $cat('rent_demo', 'Аренда демо', 'household');
        $cat('services', 'Услуги демо');
        $svc = $cat('svc_cleaning', 'Демосервис', 'services');
        $tx = fn (string $date, string $category, string $amount, ?int $sub = null, ?string $ctr = null) => DB::table('money_transactions')->insert(['account_id' => $acc,
            'tx_date' => $date, 'flow' => 'expense', 'category' => $category, 'amount' => $amount, 'expense_category_id' => $sub, 'counterparty' => $ctr]);
        $tx('2031-01-05', 'household', '1000', $rent);
        $tx('2031-02-05', 'household', '1000', $rent);
        $tx('2031-01-06', 'supplier', '300', null, 'Неизвестный Демо');      // no article — «Поставщику (статья не указана)»
        Supplier::query()->forceCreate(['name' => 'ТОО Демосервис', 'status' => 'active', 'expense_category_id' => $svc, 'expense_source' => 'esf']);
        $tx('2031-01-07', 'supplier', '999', null, 'Демосервис');            // counted by its ESF, not by the payment
        IncomingInvoice::create(['num' => '1', 'date' => '2031-01-20', 'status' => 'DELIVERED', 'invoice_type' => 'service', 'supplier_name' => 'ТОО Демосервис',
            'total_amount' => 0, 'total_nds' => 0, 'total_with_nds' => '450.5']);
        $tx('2031-01-08', 'transfer_out', '5000');                           // not an expense
        $tx('2031-01-09', 'advance_out', '7000');                            // the issue of an advance: its lines are
        $tx('2031-03-09', 'dividend', '2000');                               // a row of its own
        $tx('2030-12-31', 'household', '1', $rent);                          // another year
        DB::table('advance_reports')->insert(['doc_num' => 'АО-1', 'employee_name' => 'Демо', 'report_date' => '2031-01-15', 'issued' => 0, 'spent' => 0, 'returned' => 0, 'status' => 'closed',
            'lines' => json_encode([['type' => 'material', 'amount' => 70], ['type' => 'supplier', 'amount' => 30], ['type' => 'expense', 'category' => 'household', 'subcategory' => 'rent_demo', 'amount' => 5]])]);

        $r = (new PlExpenses)->build('2031');
        $this->assertSame([
            ['household', 'Хозяйственные', 2005.0, [['rent_demo', 'Аренда демо', 2005.0]]],
            ['services', 'Услуги демо', 450.5, [['svc_cleaning', 'Демосервис', 450.5]]],
            ['supplier', 'Поставщику (статья не указана)', 300.0, []],
            ['adv_material', 'Материалы (аванс)', 70.0, []],
            ['adv_supplier', 'Оплата поставщику (аванс)', 30.0, []],
        ], array_map(fn ($row) => [$row['code'], $row['label'], array_sum($row['monthly']), array_map(fn ($s) => [$s['code'], $s['label'], array_sum($s['monthly'])], $row['subs'])], $r['rows']));
        $this->assertSame([1005.0, 1000.0], array_slice($r['rows'][0]['monthly'], 0, 2));
        $this->assertSame(2000.0, $r['dividends'][2]);
    }
}
