<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Services\Finance\FixedCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** «Финансы» → «Точка безубыточности» → «Постоянные затраты — план и факт» (legacy loadFixedCostsPeriod/getFCFact/saveAllFixedCosts; ADR-0055). */
class FixedCostsTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $code, string $name, bool $fixed, int $sort): int
    {
        return DB::table('expense_categories')->insertGetId(['code' => $code, 'name' => $name, 'is_fixed' => $fixed, 'sort_order' => $sort]);
    }

    private function cost(string $period, string $name, string $category, string $plan, bool $approved = false, ?int $payDay = null): void
    {
        DB::table('fixed_costs')->insert(['period' => $period, 'name' => $name, 'category' => $category, 'plan' => $plan, 'approved' => $approved, 'pay_day' => $payDay]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function save(User $user, string $period, ?string $version, array $rows): TestResponse
    {
        return $this->actingAs($user)->from('/finance')->put("/finance/fixed-costs/{$period}", ['version' => $version, 'rows' => $rows]);
    }

    public function test_a_month_without_a_plan_takes_the_one_before_else_the_fixed_articles(): void
    {
        DB::table('expense_categories')->update(['is_fixed' => false]);
        $this->category('demo_lease', 'Демо-аренда', true, 2);
        $this->category('demo_web', 'Демо-связь', true, 1);
        $this->category('demo_misc', 'Демо-разное', false, 0);
        $costs = new FixedCosts;
        $this->assertSame([['Демо-связь', 'demo_web', 0.0], ['Демо-аренда', 'demo_lease', 0.0]],
            array_map(fn ($r) => [$r['name'], $r['category'], $r['plan']], $costs->period('2031-03')['rows']), 'the articles marked «постоянная», by their order');

        $this->cost('2031-02', 'Склад', 'demo_lease', '700.50', true, 5);
        $this->cost('2031-02', 'Офис демо', 'demo_lease', '300');
        $march = $costs->period('2031-03');
        $this->assertFalse($march['saved']);
        $this->assertSame([[null, 'Офис демо', 300.0, false, null], [null, 'Склад', 700.5, false, 5]],
            array_map(fn ($r) => [$r['id'], $r['name'], $r['plan'], $r['approved'], $r['pay_day']], $march['rows']), 'the month before, by name, not «проведено»');
        $this->assertTrue($costs->period('2031-02')['saved']);
    }

    public function test_the_fact_of_an_article_is_the_expenses_of_its_month(): void
    {
        $lease = $this->category('demo_lease', 'Демо-аренда', true, 1);
        $acc = DB::table('money_accounts')->insertGetId(['name' => 'Касса Демо', 'type' => 'cash', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        $tx = fn (string $date, string $flow, string $category, ?int $sub, string $amount) => DB::table('money_transactions')->insert(['account_id' => $acc,
            'tx_date' => $date, 'flow' => $flow, 'category' => $category, 'expense_category_id' => $sub, 'amount' => $amount]);
        $tx('2031-03-01', 'expense', 'rent', null, '100');
        $tx('2031-03-31', 'expense', 'other_out', $lease, '40');       // by its article, the last day of the month too
        $tx('2031-03-15', 'expense', 'demo_lease', $lease, '7');        // the category and the article are the same: once
        $tx('2031-03-16', 'income', 'rent', null, '1000');              // not an expense
        $tx('2031-04-01', 'expense', 'rent', null, '999');              // another month
        DB::table('advance_reports')->insert(['doc_num' => 'АО-1', 'employee_name' => 'Демо Сотрудник', 'report_date' => '2031-03-12', 'issued' => 0, 'spent' => 0,
            'returned' => 0, 'status' => 'closed', 'lines' => json_encode([['type' => 'expense', 'category' => 'rent', 'amount' => 5], ['type' => 'expense', 'amount' => 3]])]);

        $facts = (new FixedCosts)->facts('2031-03');
        $this->assertSame([105.0, 47.0, 40.0, 7.0], [$facts['rent'], $facts['demo_lease'], $facts['other_out'], $facts['demo_lease'] - $facts['other_out']]);
    }

    public function test_the_month_is_saved_as_a_whole_over_what_was_loaded(): void
    {
        $this->category('demo_lease', 'Демо-аренда', true, 1);
        $user = User::factory()->role('accountant')->create();
        $loaded = (new FixedCosts)->period('2031-03')['version'];
        $this->save($user, '2031-03', $loaded, [['name' => ' Склад ', 'category' => 'demo_lease', 'plan' => '1 200,5', 'approved' => true, 'pay_day' => '10'],
            ['name' => 'Охрана', 'category' => 'demo_lease', 'plan' => '', 'approved' => false, 'pay_day' => '']])
            ->assertRedirect('/finance?fc_period=2031-03')->assertSessionHasNoErrors();
        $this->assertSame([['Охрана', '0.00', false, null], ['Склад', '1200.50', true, 10]],
            DB::table('fixed_costs')->where('period', '2031-03')->orderBy('name')->get()->map(fn ($r) => [$r->name, $r->plan, $r->approved, $r->pay_day])->all());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'fixed_costs.saved')->count());

        $this->save($user, '2031-03', $loaded, [])->assertSessionHasErrors(['rows' => FixedCosts::STALE]);
        $this->assertSame(2, DB::table('fixed_costs')->where('period', '2031-03')->count(), 'a save over a month changed since is refused');

        $this->save($user, '2031-03', (new FixedCosts)->period('2031-03')['version'], [])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('fixed_costs')->where('period', '2031-03')->count(), 'every row may be removed');
    }

    public function test_a_row_needs_a_name_a_plan_not_below_zero_and_a_day_of_the_month(): void
    {
        $user = User::factory()->role('accountant')->create();
        $this->save($user, '2031-03', '', [['name' => ' ', 'category' => 'rent', 'plan' => '-1', 'approved' => false, 'pay_day' => 32]])
            ->assertSessionHasErrors(['rows.0.name', 'rows.0.plan', 'rows.0.pay_day']);
        $this->save($user, '2031-13', '', [])->assertNotFound();
        $this->save(User::factory()->role('storekeeper')->create(), '2031-03', '', [])->assertForbidden();
        $this->assertSame(0, DB::table('fixed_costs')->count());
    }

    public function test_the_page_gets_the_month_asked_for(): void
    {
        $this->cost('2031-02', 'Склад', 'rent', '10');
        $this->actingAs(User::factory()->role('director')->create());
        $version = $this->get('/finance')->viewData('page')['version'];
        $h = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Finance', 'X-Inertia-Partial-Data' => 'fixedCosts'];
        $r = $this->get('/finance?fc_period=2031-02', $h);
        $this->assertSame(['2031-02', true, ['Склад']], [$r->json('props.fixedCosts.period'), $r->json('props.fixedCosts.saved'), array_column($r->json('props.fixedCosts.rows'), 'name')]);
        $this->assertSame(now(config('erp.business_timezone'))->format('Y-m'), $this->get('/finance?fc_period=bad', $h)->json('props.fixedCosts.period'));
    }
}
