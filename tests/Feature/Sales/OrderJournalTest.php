<?php

namespace Tests\Feature\Sales;

use App\Models\AuditEvent;
use App\Models\Order;
use App\Models\Realization;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M09 «Продажи», part 1: the order journal (view only) by channel, totals of the lines, the «Завершить» rule. */
class OrderJournalTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->director = User::factory()->role('director')->create(['name' => 'Демо Директор']);
    }

    /** @param list<array{0: string, 1: string, 2?: int}> $lines qty, price, progress */
    private function order(string $num, string $channel, array $lines = [['1', '1000']], array $attrs = []): Order
    {
        $order = new Order($attrs + ['num' => $num, 'channel' => $channel, 'customer' => 'Демо Покупатель', 'status' => 'queue', 'payment_pct' => 0]);
        $order->save();
        foreach ($lines as $i => $l) {
            $order->items()->create(['line_no' => $i, 'name' => 'Демо изделие', 'qty' => $l[0], 'price' => $l[1], 'progress' => $l[2] ?? 0, 'status' => 'queue', 'attrs' => []]);
        }

        return $order;
    }

    private function realize(Order $order, string $revenue): void
    {
        $r = new Realization(['order_num' => $order->num, 'doc_date' => '2026-10-01', 'name' => 'Демо', 'qty' => '1', 'revenue' => $revenue]);
        $r->order_id = $order->id;
        $r->save();
    }

    public function test_journal_shows_totals_progress_and_dates_of_the_orders(): void
    {
        $older = $this->order('МО-1', 'office', [['2', '1500.50', 40], ['1', '1000', 70]], ['payment_pct' => 50, 'order_date' => '2026-03-04', 'due_date' => '2026-04-01', 'seller' => 'Демо Продавец']);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $this->order('МО-2', 'dealer_astana', [], ['status' => 'done', 'end_client' => 'Демо Клиент']);

        $this->actingAs($this->director)->get('/sales')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sales')
            ->where('orders.0.num', 'МО-2')
            ->where('orders.0.total', 0)
            ->where('orders.0.progress', 0)
            ->where('orders.0.end_client', 'Демо Клиент')
            ->where('orders.1.num', 'МО-1')
            ->where('orders.1.total', 4001)
            ->where('orders.1.progress', 55)
            ->where('orders.1.payment', 50)
            ->where('orders.1.created', '04.03.2026')
            ->where('orders.1.date', '01.04.2026')
            ->where('orders.1.seller', 'Демо Продавец')
            ->where('channels.dealer_astana', 'Дилер Астана'));
    }

    public function test_orders_follow_the_channel_scope(): void
    {
        $this->order('МО-1', 'office');
        $this->order('МО-2', 'salon_armada');
        $this->order('МО-3', 'salon_nazarbayeva');
        $this->order('МО-4', 'dealer_astana');
        $nums = fn (User $u) => collect($this->actingAs($u)->get('/sales')->viewData('page')['props']['orders'])->pluck('num')->sort()->values()->all();

        $this->assertSame(['МО-1', 'МО-2', 'МО-3', 'МО-4'], $nums($this->director));
        $this->assertSame(['МО-2'], $nums(User::factory()->role('salon_armada')->create()));
        $this->assertSame(['МО-2', 'МО-3'], $nums(User::factory()->role('salon_armada_mgr')->create()));
        $this->assertSame(['МО-4'], $nums(User::factory()->role('dealer_astana')->create()));
        $this->actingAs(User::factory()->role('storekeeper')->create())->get('/sales')->assertForbidden();
    }

    public function test_a_dealer_order_realized_to_99_percent_is_completed_by_hand(): void
    {
        $ready = $this->order('МО-1', 'dealer_astana', [['2', '500']]);
        $this->realize($ready, '990.00');
        $short = $this->order('МО-2', 'dealer_astana', [['1', '1000']]);
        $this->realize($short, '989.99');
        $office = $this->order('МО-3', 'office', [['1', '1000']]);
        $this->realize($office, '1000');

        $this->actingAs($this->director)->get('/sales')->assertInertia(fn (Assert $page) => $page
            ->where('orders', fn ($orders) => collect($orders)->pluck('can_complete', 'num')->all() === ['МО-3' => false, 'МО-2' => false, 'МО-1' => true]));

        $this->from('/sales');
        $this->post("/sales/orders/{$short->id}/complete", ['lock_version' => 1])->assertSessionHasErrors(['order' => OrderService::NOT_REALIZED]);
        $this->post("/sales/orders/{$office->id}/complete", ['lock_version' => 1])->assertSessionHasErrors(['order' => OrderService::NOT_REALIZED]);
        $this->post("/sales/orders/{$ready->id}/complete", ['lock_version' => 2])->assertSessionHasErrors(['lock_version' => OrderService::STALE]);
        $this->post("/sales/orders/{$ready->id}/complete", ['lock_version' => 1])->assertRedirect('/sales')->assertSessionHas('toast', '✅ МО-1 завершён');

        $this->assertSame(['done', 2], [$ready->fresh()->status, $ready->fresh()->lock_version]);
        $this->assertEquals(['status' => ['from' => 'queue', 'to' => 'done']], AuditEvent::where('action', 'order.completed')->sole()->changes);
        $this->post("/sales/orders/{$ready->id}/complete", ['lock_version' => 2])->assertSessionHasErrors(['order' => OrderService::NOT_REALIZED]);
        $this->assertSame(['queue', 'queue'], [$short->fresh()->status, $office->fresh()->status]);
    }

    public function test_another_channel_cannot_complete_an_order(): void
    {
        $order = $this->order('МО-1', 'dealer_astana', [['1', '100']]);
        $this->realize($order, '100');

        $this->actingAs(User::factory()->role('dealer_aktau')->create())->from('/sales')
            ->post("/sales/orders/{$order->id}/complete", ['lock_version' => 1])->assertSessionHasErrors(['order' => OrderService::NOT_FOUND]);
        $this->actingAs(User::factory()->role('storekeeper')->create())->post("/sales/orders/{$order->id}/complete", ['lock_version' => 1])->assertForbidden();
        $this->assertSame('queue', $order->fresh()->status);
    }
}
