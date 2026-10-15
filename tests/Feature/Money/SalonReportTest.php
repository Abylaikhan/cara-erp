<?php

namespace Tests\Feature\Money;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M21 «Отчёт салонов»: the salon orders the account may see, with totals and dates; other roles have no page. */
class SalonReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_of_the_salons_gets_the_salon_orders_only(): void
    {
        foreach ([['МО-1', 'salon_armada'], ['МО-2', 'salon_nazarbayeva'], ['МО-3', 'office'], ['МО-4', 'dealer_astana']] as [$num, $channel]) {
            $o = new Order(['num' => $num, 'channel' => $channel, 'customer' => 'Демо', 'end_client' => 'Демо Клиент', 'seller' => 'Демо Продавец', 'status' => 'progress',
                'payment_pct' => 30, 'order_date' => '2026-10-02']);
            $o->save();
            $o->items()->create(['line_no' => 0, 'name' => 'Демо', 'qty' => '2', 'price' => '100.25', 'progress' => 0, 'status' => 'progress', 'attrs' => []]);
        }

        $this->actingAs(User::factory()->role('salon_armada_mgr')->create())->get('/salon_report')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SalonReport')
            ->where('salonFixed', null)
            ->where('orders', fn ($o) => collect($o)->pluck('num')->sort()->values()->all() === ['МО-1', 'МО-2'])
            ->where('orders.0.total', '200.500000')
            ->where('orders.0.created', '2026-10-02'));
        $this->actingAs(User::factory()->role('director')->create())->get('/salon_report')->assertForbidden();
    }
}
