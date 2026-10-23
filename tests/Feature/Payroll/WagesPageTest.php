<?php

namespace Tests\Feature\Payroll;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M18 «Сдельная оплата», part 1: the production staff and what the posted stages earned them (legacy rule), view only. */
class WagesPageTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $uuid, string $name, ?string $type, ?string $goal = null): void
    {
        DB::table('employees')->insert(['uuid' => $uuid, 'name' => $name, 'type' => $type, 'position' => 'Сборщик', 'workshop' => 'assemble', 'active' => true,
            'piece_goal' => $goal, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_only_roles_with_the_page_open_it(): void
    {
        foreach (['director', 'foreman', 'accountant', 'hr'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/wages')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Wages'));
        }
        foreach (['manager', 'storekeeper', 'salon_armada', 'dealer_astana'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/wages')->assertForbidden();
        }
    }

    public function test_the_posted_stages_are_counted_by_the_rule_of_legacy_and_shared_by_ktu(): void
    {
        $order = new Order(['num' => 'МО-7', 'channel' => 'office', 'customer' => 'Демо', 'status' => 'queue', 'payment_pct' => 0]);
        $order->save();
        $order->items()->create(['line_no' => 0, 'name' => 'Стул Демо', 'qty' => '1', 'price' => '10', 'progress' => 0, 'status' => 'queue', 'attrs' => []]);
        $item = $order->items()->create(['line_no' => 1, 'name' => 'Стол Демо', 'category' => 'Стол', 'qty' => '2', 'price' => '1000.5', 'progress' => 0, 'status' => 'queue', 'attrs' => []]);
        (new ProductionProgress)->forceFill(['order_item_id' => $item->id, 'materials' => [], 'completed' => false, 'stages' => [
            // by the rate: 150 × 3 = 450, shared 2 : 1
            ['stage' => 'Сборка', 'workshop' => 'assemble', 'payMethod' => 'rate', 'rate' => 150, 'qty' => 3, 'posted' => true, 'postedDate' => '2031-03-05',
                'assignees' => [['name' => 'Демо Мастер', 'ktu' => 2], ['name' => 'Демо Помощник', 'ktu' => 1]]],
            // by the percent of the line: 2 × 1000.5 × 2.5 % = 50.025; without executors — «—»
            ['stage' => '', 'workshop' => 'paint', 'payMethod' => 'percent', 'percent' => 2.5, 'posted' => true, 'postedDate' => '2031-03-02', 'assignees' => []],
            // zero quantity and КТУ count as 1 (JavaScript `qty||1`, `ktu||1`)
            ['stage' => 'Упаковка', 'workshop' => 'pack', 'payMethod' => 'rate', 'rate' => 80, 'qty' => 0, 'posted' => true, 'postedDate' => '2031-04-01',
                'assignees' => [['name' => 'Демо Мастер', 'ktu' => 0]]],
            ['stage' => 'Не проведён', 'payMethod' => 'rate', 'rate' => 999, 'qty' => 1, 'posted' => false, 'postedDate' => '2031-03-05', 'assignees' => [['name' => 'Демо Мастер', 'ktu' => 1]]],
            ['stage' => 'Без даты', 'payMethod' => 'rate', 'rate' => 999, 'qty' => 1, 'posted' => true, 'assignees' => [['name' => 'Демо Мастер', 'ktu' => 1]]],
        ]])->save();
        $this->employee('dddddddd-dddd-4ddd-8ddd-ccccccccccc1', 'Демо Мастер', 'prod', '250.5');
        $this->employee('dddddddd-dddd-4ddd-8ddd-ccccccccccc2', 'Демо Помощник', null);
        $this->employee('dddddddd-dddd-4ddd-8ddd-ccccccccccc3', 'Демо Бухгалтер', 'aup');
        foreach ([['Стул Демо', 'Стул', true], ['Шкаф Демо', 'Шкаф', false], ['Стол Демо', 'Стол', true], ['Стул Второй', 'Стул', true]] as [$name, $category, $active]) {
            $p = new Product(['name' => $name, 'category' => $category, 'price_retail' => 1, 'price_wholesale' => 1, 'unit' => 'шт']);
            $p->forceFill(['active' => $active])->save();
        }
        $this->actingAs(User::factory()->role('director')->create());

        $props = $this->get('/wages')->assertOk()->viewData('page')['props'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $props['today']);
        // the staff of type «prod» (an empty type is «prod»), by name; the plan piece_goal||400
        $this->assertSame([['name' => 'Демо Мастер', 'pos' => 'Сборщик', 'ws' => 'assemble', 'goal' => 250.5], ['name' => 'Демо Помощник', 'pos' => 'Сборщик', 'ws' => 'assemble', 'goal' => 400.0]],
            array_map(fn ($s) => [...$s, 'goal' => (float) $s['goal']], $props['staff']));
        $this->assertEqualsCanonicalizing(['Стул', 'Стол'], $props['categories']);

        $entries = $props['entries'];
        $this->assertSame(['Демо Мастер', 'Демо Помощник', '—', 'Демо Мастер'], array_column($entries, 'exec'));
        $this->assertEqualsWithDelta([300, 150, 50.025, 80], array_column($entries, 'amount'), 1e-9);
        $this->assertSame(['Сборка', 'Сборка', '(без названия)', 'Упаковка'], array_column($entries, 'stage'));
        $this->assertSame(['2031-03-05', '2031-03-05', '2031-03-02', '2031-04-01'], array_column($entries, 'date'));
        $this->assertSame(['МО-7', 2, 'Стол Демо', 'Стол', 'paint'], [$entries[2]['num'], $entries[2]['pos'], $entries[2]['name'], $entries[2]['category'], $entries[2]['workshop']]);
    }
}
