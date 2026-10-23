<?php

namespace Tests\Feature\Sales;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M09 «Продажи» → «Рабочий лист реализации»: the lines of the delivery notes, loaded with the tab; channel accounts get none. */
class SalesWorksheetTabTest extends TestCase
{
    use RefreshDatabase;

    private function line(string $uuid, string $doc, string $num, string $date, int $no, ?int $orderId, string $item, ?int $itemNo = null, ?string $driver = null, string $author = 'director'): void
    {
        DB::table('sales_worksheet_rows')->insert(['uuid' => $uuid, 'doc_id' => $doc, 'doc_num' => $num, 'doc_date' => $date, 'line_no' => $no, 'line_kind' => 'sale',
            'order_num' => $orderId ? 'ТЕСТ-1' : null, 'order_id' => $orderId, 'customer' => 'Демо', 'item_name' => $item, 'item_no' => $itemNo, 'unit' => 'шт',
            'qty' => 1, 'price' => '10.5', 'amount' => '10.5', 'driver' => $driver, 'posted' => false, 'delivered' => false, 'created_by' => $author,
            'created_at' => '2031-04-02 10:00:00+05', 'updated_at' => '2031-04-02 10:00:00+05']);
    }

    private function partial(string $only)
    {
        $version = $this->get('/sales')->viewData('page')['version'];

        return $this->get('/sales', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Sales', 'X-Inertia-Partial-Data' => $only]);
    }

    public function test_the_lines_come_in_the_order_of_legacy_with_the_product_number(): void
    {
        $order = new Order(['num' => 'ТЕСТ-1', 'channel' => 'office', 'customer' => 'Демо', 'status' => 'queue', 'payment_pct' => 0]);
        $order->save();
        $order->items()->create(['line_no' => 0, 'name' => 'Стол', 'qty' => 1, 'price' => 1, 'progress' => 0, 'status' => 'queue', 'attrs' => []]);
        $order->items()->create(['line_no' => 1, 'name' => ' Стул ', 'qty' => 1, 'price' => 1, 'progress' => 0, 'status' => 'queue', 'attrs' => []]);
        $docA = 'aaaaaaaa-0000-4000-8000-aaaaaaaaaaaa';
        $docB = 'bbbbbbbb-0000-4000-8000-bbbbbbbbbbbb';
        $this->line('11111111-aaaa-4aaa-8aaa-aaaaaaaaaaa1', $docA, '7', '2031-04-01', 0, $order->id, 'стул');
        $this->line('11111111-aaaa-4aaa-8aaa-aaaaaaaaaaa2', $docB, '12', '2031-04-02', 1, null, 'Без заказа');
        $this->line('11111111-aaaa-4aaa-8aaa-aaaaaaaaaaa3', $docB, '12', '2031-04-02', 0, $order->id, 'Стол', 5);
        $this->actingAs(User::factory()->role('director')->create(['login' => 'director']));

        // No login reaches the page: whether the account edits any note and which lines it wrote come as flags.
        $this->get('/sales')->assertInertia(fn (Assert $page) => $page->missing('worksheet')->missing('login')->has('today')->where('swAdmin', true));
        $rows = $this->partial('worksheet')->assertOk()->json('props.worksheet');
        // doc_date desc, doc_num desc, line_no asc
        $this->assertSame(['12', '12', '7'], array_column($rows, 'doc_num'));
        $this->assertSame([0, 1, 0], array_column($rows, 'line_no'));
        // saved number first; else the only line of the order with the same name (trimmed, any case)
        $this->assertSame([5, null, 2], array_column($rows, 'item_no'));
        $this->assertSame('2031-04-02', $rows[0]['created_day']);
        $this->assertEquals(10.5, $rows[0]['amount']);
        $this->assertSame([true, true, true], array_column($rows, 'mine'));
        $this->assertArrayNotHasKey('created_by', $rows[0]);
    }

    public function test_a_driver_account_gets_only_the_lines_of_its_notes(): void
    {
        // Legacy swIsDriverUser: the role or the name says «водитель»; swDriverMatches: one name within the other.
        $this->line('11111111-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'aaaaaaaa-0000-4000-8000-aaaaaaaaaaaa', '7', '2031-04-01', 0, null, 'Стол', null, ' Тестов ', 'manager');
        $this->line('11111111-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'bbbbbbbb-0000-4000-8000-bbbbbbbbbbbb', '8', '2031-04-01', 0, null, 'Стул', null, 'Другой', 'manager');
        $this->line('11111111-aaaa-4aaa-8aaa-aaaaaaaaaaa3', 'cccccccc-0000-4000-8000-cccccccccccc', '9', '2031-04-01', 0, null, 'Шкаф', null, null, 'manager');
        $this->actingAs(User::factory()->role('manager')->create(['name' => 'Водитель Тестов']));

        $rows = $this->partial('worksheet')->assertOk()->json('props.worksheet');
        $this->assertSame(['7'], array_column($rows, 'doc_num'));
        $this->assertSame([false], array_column($rows, 'mine'));
        $this->get('/sales')->assertInertia(fn (Assert $page) => $page->where('swAdmin', false));
    }

    public function test_a_channel_account_gets_no_lines(): void
    {
        $this->line('11111111-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'aaaaaaaa-0000-4000-8000-aaaaaaaaaaaa', '7', '2031-04-01', 0, null, 'Стол');
        $this->actingAs(User::factory()->role('salon_armada')->create());
        $this->assertSame([], $this->partial('worksheet')->assertOk()->json('props.worksheet'));
    }
}
