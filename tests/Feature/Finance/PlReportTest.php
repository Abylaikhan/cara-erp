<?php

namespace Tests\Feature\Finance;

use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceItem;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\PlReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** «Финансы» → «📊 P&L»: the statement of legacy buildPL on synthetic data (№ 36 — ADR-0052). */
class PlReportTest extends TestCase
{
    use RefreshDatabase;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        // the expense tree: Производственные (бук → материалы, станок → ОПР, прочее → ни туда ни сюда), Коммерческие, Хозяйственные
        $cat = fn (string $code, string $name, ?string $parent = null) => DB::table('expense_categories')->insertGetId(['code' => $code, 'name' => $name, 'sort_order' => 0,
            'parent_id' => $parent ? DB::table('expense_categories')->where('code', $parent)->value('id') : null]);
        $cat('production', 'Производственные');
        $cat('prod_wood', 'Бук демо', 'production');
        $cat('prod_tool', 'Станок демо', 'production');
        $cat('prod_misc', 'Разное демо', 'production');
        $cat('commercial', 'Коммерческие');
        $cat('ads', 'Реклама демо', 'commercial');
        $cat('household', 'Хозяйственные');
        $cat('rent', 'Аренда демо', 'household');
    }

    private function tx(string $date, string $category, string $amount, ?string $sub = null, ?string $ctr = null): void
    {
        DB::table('money_transactions')->insert(['account_id' => $this->account, 'tx_date' => $date, 'flow' => 'expense', 'category' => $category, 'amount' => $amount,
            'expense_category_id' => $sub ? DB::table('expense_categories')->where('code', $sub)->value('id') : null, 'counterparty' => $ctr]);
    }

    private function accrual(string $date, string $amount, ?array $details): void
    {
        DB::table('salary_accruals')->insert(['uuid' => fake()->uuid(), 'emp_name' => 'Демо', 'type' => 'salary', 'accrual_date' => $date, 'amount' => $amount, 'deducted' => 0, 'net' => 0,
            'details' => $details === null ? null : json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_statement_of_a_period_as_legacy_builds_it(): void
    {
        DB::table('realizations')->insert([['doc_date' => '2031-03-05', 'name' => 'Демо', 'qty' => 1, 'revenue' => '100000.50'], ['doc_date' => '2031-04-01', 'name' => 'Демо', 'qty' => 1, 'revenue' => '999']]);
        // ESF goods: a material line counts, a tool line is an overhead, a service invoice and another month do not count
        $m = new Material(['name' => 'Плита демо', 'category' => 'ЛДСП/МДФ', 'unit' => 'лист', 'price' => 1, 'min_qty' => 0, 'warehouse_code' => 'wh_plates']);
        $m->forceFill(['qty' => 0])->save();
        $t = new Material(['name' => 'Шлифкруг демо', 'category' => 'Инструмент/Абразивы', 'unit' => 'шт', 'price' => 1, 'min_qty' => 0, 'warehouse_code' => 'wh_main']);
        $t->forceFill(['qty' => 0])->save();
        foreach ([['2031-03-10', 'goods', [['Плита демо 16мм', '20000']]], ['2031-03-11', 'goods', [['ШЛИФКРУГ ДЕМО', '700']]], ['2031-03-12', 'service', [['Доставка', '500']]], ['2031-04-10', 'goods', [['Плита демо', '1']]]] as $k => [$date, $type, $lines]) {
            $inv = IncomingInvoice::create(['num' => (string) $k, 'date' => $date, 'status' => 'DELIVERED', 'invoice_type' => $type, 'supplier_name' => 'ТОО Демо', 'total_amount' => 0, 'total_nds' => 0, 'total_with_nds' => 0]);
            foreach ($lines as $i => [$d, $total]) {
                IncomingInvoiceItem::query()->create(['invoice_id' => $inv->id, 'line_no' => $i + 1, 'description' => $d, 'qty' => 1, 'unit_price' => $total, 'amount' => $total, 'nds_rate' => 0, 'nds_amount' => 0, 'total' => $total]);
            }
        }
        // money: production by article, a supplier by the article of its card, commercial, household, salary
        $this->tx('2031-03-01', 'production', '3000', 'prod_wood');
        $this->tx('2031-03-02', 'production', '1500', 'prod_tool');
        $this->tx('2031-03-03', 'production', '250', 'prod_misc');
        Supplier::query()->forceCreate(['name' => 'ТОО «Реклама Демо»', 'status' => 'active', 'expense_category_id' => DB::table('expense_categories')->where('code', 'ads')->value('id')]);
        $this->tx('2031-03-04', 'supplier', '800', null, 'Реклама Демо');
        $this->tx('2031-03-04', 'supplier', '50', null, 'Неизвестный Демо');
        $this->tx('2031-03-05', 'household', '1200', 'rent');
        $this->tx('2031-03-06', 'salary', '9999');
        $this->tx('2031-05-01', 'household', '1', 'rent');
        // the ledger: details by kind; an accrual without details is «Оклад»
        $this->accrual('2031-03-31', '0', ['accruals' => [['type' => 'Сдельная', 'amount' => 7000], ['type' => 'Оклад', 'amount' => 4000], ['type' => 'Премия', 'amount' => 500]]]);
        $this->accrual('2031-03-31', '2500', null);
        // an advance report: a commercial line and a supplier line with no article anywhere
        DB::table('advance_reports')->insert(['doc_num' => 'АО-1', 'employee_name' => 'Демо', 'report_date' => '2031-03-20', 'issued' => 0, 'spent' => 0, 'returned' => 0, 'status' => 'closed',
            'lines' => json_encode([['type' => 'expense', 'category' => 'commercial', 'subcategory' => 'ads', 'amount' => 300], ['type' => 'supplier', 'supplier' => 'Никто Демо', 'amount' => 40], ['type' => 'material', 'amount' => 999]])]);

        $pl = (new PlReport)->build('2031-03-01', '2031-03-31');

        $this->assertSame([100000.5, 23000.0, 20000.0, 7000.0, 1500.0], [$pl['revenue'], $pl['materialCost'], $pl['esfMaterialEstimate'], $pl['wageCost'], $pl['oprCost']]);
        $this->assertSame([3000.0, 250.0], [$pl['prodToMaterials'], $pl['prodUnclassified']]);
        $this->assertSame(68500.5, $pl['grossMargin']);
        $this->assertSame([7000.0, 0.0, 1100.0, 1200.0, 300.0, 40.0], [$pl['aupSalary'], $pl['prodTaxes'], $pl['commercialCost'], $pl['otherFixed'], $pl['advCommercialCost'], $pl['advUndetermined']]);
        $this->assertSame(59200.5, $pl['ebit']);
        $this->assertEqualsWithDelta(0.685, $pl['grossMarginPct'], 0.0001);

        // without ledger the salary payments are the salaries of the staff
        DB::table('salary_accruals')->delete();
        $this->assertSame([0.0, 9999.0], [(new PlReport)->build('2031-03-01', '2031-03-31')['wageCost'], (new PlReport)->build('2031-03-01', '2031-03-31')['aupSalary']]);
    }

    public function test_the_page_gets_the_statement_of_its_period_only_when_asked(): void
    {
        DB::table('realizations')->insert(['doc_date' => '2031-03-05', 'name' => 'Демо', 'qty' => 1, 'revenue' => '10']);
        $this->actingAs(User::factory()->role('director')->create());
        $this->get('/finance')->assertInertia(fn (Assert $page) => $page->missing('pl'));
        $version = $this->get('/finance')->viewData('page')['version'];
        $r = $this->get('/finance?pl_from=2031-03-01&pl_to=2031-03-31', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Finance', 'X-Inertia-Partial-Data' => 'pl']);
        $this->assertSame(['2031-03-01', '2031-03-31', 10.0], [$r->json('props.pl.from'), $r->json('props.pl.to'), (float) $r->json('props.pl.revenue')]);
        $r = $this->get('/finance?pl_from=bad', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Finance', 'X-Inertia-Partial-Data' => 'pl']);
        $this->assertSame([null, 10.0], [$r->json('props.pl.from'), (float) $r->json('props.pl.revenue')], 'a date that is not one means no bound');
    }
}
