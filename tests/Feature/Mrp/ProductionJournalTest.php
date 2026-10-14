<?php

namespace Tests\Feature\Mrp;

use App\Models\Order;
use App\Models\ProductionProgress;
use App\Models\Realization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M02 «Производство», part 1: every order line with its effective status (legacy mrpEffectiveStatus), view only. */
class ProductionJournalTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $num, string $channel, array $lines): Order
    {
        $order = new Order(['num' => $num, 'channel' => $channel, 'customer' => 'Демо', 'status' => 'queue', 'payment_pct' => 0]);
        $order->save();
        foreach ($lines as $i => [$name, $status]) {
            $order->items()->create(['line_no' => $i, 'name' => $name, 'qty' => '2', 'price' => '100', 'progress' => 30, 'status' => $status,
                'category' => 'Стул', 'resp' => 'Демо Мастер', 'item_date' => '2026-10-20', 'attrs' => []]);
        }

        return $order;
    }

    public function test_lines_show_the_effective_status_and_recorded_stages(): void
    {
        $order = $this->order('МО-1', 'office', [['Стул Демо', 'queue'], ['Стол Демо', 'queue'], ['Кресло Демо', 'progress'], ['Пуф Демо', 'queue']]);
        $lines = $order->items()->get();
        // Realized under the same name (trimmed, any case) → done.
        $r = new Realization(['order_num' => 'МО-1', 'doc_date' => '2026-10-01', 'name' => '  стул демо ', 'qty' => '2', 'revenue' => '200']);
        $r->order_id = $order->id;
        $r->save();
        // Recorded work on a queued line → in work; an empty record changes nothing.
        (new ProductionProgress)->forceFill(['order_item_id' => $lines[1]->id, 'stages' => [['stage' => 'Сборка каркаса'], ['stage' => '']], 'materials' => [], 'completed' => false])->save();
        (new ProductionProgress)->forceFill(['order_item_id' => $lines[3]->id, 'stages' => [], 'materials' => [], 'completed' => false])->save();

        $this->actingAs(User::factory()->role('foreman')->create())->get('/mrp')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Mrp')
            ->where('positions', fn ($p) => collect($p)->pluck('status', 'name')->all() === ['Стул Демо' => 'done', 'Стол Демо' => 'progress', 'Кресло Демо' => 'progress', 'Пуф Демо' => 'queue'])
            ->where('positions.1.stages', ['Сборка каркаса', '(без названия)'])
            ->where('positions.0.stages', null)
            ->where('positions.3.stages', [])
            ->where('positions.0.date', '20.10.2026')
            ->where('positions.0.qty', 2)
            ->where('operations', fn ($ops) => count($ops) === 24 && $ops[0] === 'Раскрой ЛДСП/МДФ'));
    }

    public function test_only_roles_with_the_page_and_their_channels_see_lines(): void
    {
        $this->order('МО-1', 'office', [['Демо', 'queue']]);
        $this->order('МО-2', 'salon_armada', [['Демо', 'queue']]);

        $this->actingAs(User::factory()->role('director')->create())->get('/mrp')->assertInertia(fn (Assert $page) => $page->has('positions', 2));
        $this->actingAs(User::factory()->role('manager')->create())->get('/mrp')->assertForbidden();
    }
}
