<?php

namespace Tests\Feature\Finance;

use App\Legacy\Importers\ChannelCustomerNameImporter;
use App\Models\User;
use App\Services\Finance\PlRevenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** «📊 P&L» → «Выручка по направлениям» and «по категориям продукции» (legacy renderRevenueByChannel/ByCategory; ADR-0052). */
class PlRevenueTest extends TestCase
{
    use RefreshDatabase;

    private const HTML = <<<'JS'
        <script>
        const CUSTOMER_NAME_TO_CHANNEL = {
          'демо-салон': 'salon_armada',
          'частник демо': 'office',
        };
        </script>
        JS;

    private function order(string $num, ?string $channel, string $customer): void
    {
        DB::table('orders')->insert(['num' => $num, 'channel' => $channel, 'customer' => $customer, 'status' => 'done', 'payment_pct' => 0, 'order_date' => '2031-01-01', 'created_at' => now()]);
    }

    private function realization(string $date, ?string $orderNum, ?string $customer, string $name, string $revenue): void
    {
        DB::table('realizations')->insert(['doc_date' => $date, 'order_num' => $orderNum, 'customer' => $customer, 'name' => $name, 'qty' => 1, 'revenue' => $revenue]);
    }

    public function test_the_channel_of_a_realization_as_legacy_finds_it(): void
    {
        $stats = (new ChannelCustomerNameImporter(self::HTML))->import('S1');
        $this->assertSame(2, $stats['names']);
        $this->order('МО-100', 'dealer_astana', 'Дилер Демо');
        $this->order('МО-200', 'salon_armada', 'ТОО «Демо-Салон»');
        $this->realization('2031-01-10', ' мо-100 ', 'X', 'Стол', '1000');        // the channel of its order (number trimmed, upper case)
        $this->realization('2031-01-11', '200', 'X', 'Стол', '200');               // an order found by the end of its number
        $this->realization('2031-02-01', null, 'Частник демо', 'Стул', '50.5');     // no order: the customer of the realization
        $this->realization('2031-02-02', 'МО-999', 'Никто', 'Стул', '7');            // nothing — «Без канала / прочее»
        $this->realization('2030-12-31', 'МО-100', 'X', 'Стол', '9');               // another year

        $r = (new PlRevenue)->byChannel('2031');
        $this->assertSame([
            [DB::table('sales_channels')->where('code', 'salon_armada')->value('name'), [200.0, 0.0]],
            [DB::table('sales_channels')->where('code', 'dealer_astana')->value('name'), [1000.0, 0.0]],
            ['Офис', [0.0, 50.5]],
            ['Без канала / прочее', [0.0, 7.0]],
        ], array_map(fn ($row) => [$row['label'], array_slice($row['data'], 0, 2)], $r['rows']));
        $this->assertSame([3, 1, [['orderNum' => 'МО-999', 'customer' => 'Никто']]], [$r['matched'], $r['other'], $r['samples']]);
    }

    public function test_the_category_of_a_realization_by_the_catalog(): void
    {
        DB::table('products')->insert([['name' => 'Стол демо обеденный', 'category' => 'Столы'], ['name' => 'Стул демо', 'category' => 'Стулья']]);
        $this->realization('2031-03-01', null, null, 'стол демо обеденный', '100'); // exactly, without case
        $this->realization('2031-03-02', null, null, 'Стул демо венский', '300');   // the product name inside
        $this->realization('2031-03-03', null, null, 'Кровать', '40');            // none

        $r = (new PlRevenue)->byCategory('2031');
        $this->assertSame([['Стулья', 300.0], ['Столы', 100.0], ['Без категории', 40.0]], array_map(fn ($row) => [$row['label'], $row['data'][2]], $r['rows']), 'by the sum of the year, largest first');
        $this->assertSame([2, 1], [$r['matched'], $r['other']]);
    }

    public function test_the_tree_of_direction_category_and_product(): void
    {
        $this->order('МО-1', 'dealer_astana', 'Дилер Демо');
        DB::table('products')->insert([['name' => 'Стол демо', 'category' => 'Столы'], ['name' => 'Стул демо', 'category' => 'Стулья']]);
        $this->realization('2031-01-10', 'МО-1', 'X', 'Стол демо', '100');
        $this->realization('2031-02-10', 'МО-1', 'X', 'Стул демо', '300');
        $this->realization('2031-02-11', 'МО-1', 'X', 'Стул демо', '50');
        $this->realization('2031-03-01', null, 'Никто', '', '20');           // no channel, no product
        $this->realization('2031-03-02', null, 'Никто', 'Стол демо', '0');   // no amount — not in the tree

        $tree = (new PlRevenue)->tree('2031');
        $astana = DB::table('sales_channels')->where('code', 'dealer_astana')->value('name');
        $this->assertSame([[$astana, 450.0], ['Без канала / прочее', 20.0]], array_map(fn ($c) => [$c['label'], array_sum($c['monthly'])], $tree));
        $this->assertSame([['Стулья', 350.0, [['Стул демо', 350.0]]], ['Столы', 100.0, [['Стол демо', 100.0]]]],
            array_map(fn ($c) => [$c['label'], array_sum($c['monthly']), array_map(fn ($i) => [$i['label'], array_sum($i['monthly'])], $c['items'])], $tree[0]['cats']));
        $this->assertSame(['ch:other|cat:Без категории', 'Без названия'], [$tree[1]['cats'][0]['key'], $tree[1]['cats'][0]['items'][0]['label']]);
    }

    public function test_the_page_gets_each_table_for_its_own_year(): void
    {
        $this->realization('2031-03-01', null, null, 'Стол', '100');
        $this->realization('2030-03-01', null, null, 'Стол', '5');
        $this->actingAs(User::factory()->role('director')->create());
        $version = $this->get('/finance')->viewData('page')['version'];
        $h = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Finance', 'X-Inertia-Partial-Data' => 'plRevChannel,plRevCategory'];
        $r = $this->get('/finance?pl_rch_year=2030', $h);
        $this->assertSame(['2030', '2031', ['2031', '2030']], [$r->json('props.plRevChannel.year'), $r->json('props.plRevCategory.year'), $r->json('props.plRevChannel.years')]);
        $this->get('/finance')->assertInertia(fn (Assert $page) => $page->missing('plRevChannel')->missing('plRevCategory'));
    }
}
