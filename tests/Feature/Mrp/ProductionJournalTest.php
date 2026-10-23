<?php

namespace Tests\Feature\Mrp;

use App\Models\Order;
use App\Models\ProductionProgress;
use App\Models\ProductionWorksheetRow;
use App\Models\Realization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_the_recorded_stages_come_with_the_workload_and_work_log_tabs(): void
    {
        $order = $this->order('МО-1', 'office', [['Стул Демо', 'progress'], ['Стол Демо', 'queue']]);
        $lines = $order->items()->get();
        (new ProductionProgress)->forceFill(['order_item_id' => $lines[1]->id, 'materials' => [], 'completed' => false, 'stages' => [
            ['stage' => 'Сборка', 'workshop' => 'assemble', 'posted' => true, 'postedDate' => '2031-03-02', 'assignees' => [['name' => 'Демо Мастер', 'ktu' => 2]]],
            ['stage' => 'Покраска', 'workshop' => 'paint', 'posted' => false],
        ]])->save();
        (new ProductionProgress)->forceFill(['order_item_id' => $lines[0]->id, 'stages' => [], 'materials' => [], 'completed' => false])->save();
        DB::table('employees')->insert(['uuid' => 'dddddddd-dddd-4ddd-8ddd-bbbbbbbbbbb1', 'name' => 'Демо Мастер', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->role('director')->create());

        $this->get('/mrp')->assertInertia(fn (Assert $page) => $page->missing('work')->has('today'));
        $version = $this->get('/mrp')->viewData('page')['version'];
        $work = $this->get('/mrp', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Mrp', 'X-Inertia-Partial-Data' => 'work'])
            ->assertOk()->json('props.work');

        $this->assertCount(1, $work['items'], 'a line without recorded stages is left out');
        $this->assertSame(['МО-1', 2, 'Стол Демо', '20.10.2026', '2026-10-20'], [$work['items'][0]['num'], $work['items'][0]['pos'], $work['items'][0]['name'], $work['items'][0]['date'], $work['items'][0]['deadline']]);
        $this->assertEquals(['stage' => 'Сборка', 'workshop' => 'assemble', 'posted' => true, 'postedDate' => '2031-03-02', 'assignees' => [['name' => 'Демо Мастер', 'ktu' => 2]]], $work['items'][0]['stages'][0]);
        $this->assertSame([false, ''], [$work['items'][0]['stages'][1]['posted'], $work['items'][0]['stages'][1]['postedDate']]);
        $this->assertSame(['Демо Мастер'], $work['execs']);
    }

    public function test_the_lines_on_the_production_worksheet_come_with_its_tab(): void
    {
        $order = $this->order('МО-2', 'salon_armada', [['Стул Демо', 'progress'], ['Стол Демо', 'queue'], ['Шкаф Демо', 'queue']]);
        $order->forceFill(['due_date' => '2031-03-05', 'delivery_date' => '2031-03-20'])->save();
        $lines = $order->items()->get();
        $lines[1]->forceFill(['attrs' => ['Цвет' => 'Дуб', 'Ткань' => '', 'Ножки' => '0']])->save();
        (new ProductionProgress)->forceFill(['order_item_id' => $lines[1]->id, 'completed' => false, 'materials' => [['name' => 'Ткань Демо'], ['name' => '']], 'stages' => [
            ['stage' => 'Сборка', 'workshop' => 'assemble', 'posted' => true, 'assignees' => [['name' => 'Демо Мастер', 'ktu' => 1]]],
            ['stage' => '', 'workshop' => 'paint', 'started' => true, 'assignees' => [['name' => 'Демо Мастер'], ['name' => 'Демо Маляр']]],
        ]])->save();
        // legacy order: the natural order of its table; a line of no order is left out, as legacy does
        foreach ([[11, 2, $lines[1]->id, 2], [12, 0, $lines[0]->id, 1], [13, 7, null, 3]] as [$id, $idx, $item, $position]) {
            (new ProductionWorksheetRow)->forceFill(['legacy_id' => $id, 'order_num' => 'МО-2', 'item_idx' => $idx, 'order_item_id' => $item, 'legacy_position' => $position])->save();
        }
        $this->actingAs(User::factory()->role('director')->create());

        $this->get('/mrp')->assertInertia(fn (Assert $page) => $page->missing('worksheet'));
        $version = $this->get('/mrp')->viewData('page')['version'];
        $data = $this->get('/mrp', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Mrp', 'X-Inertia-Partial-Data' => 'worksheet'])
            ->assertOk()->json('props.worksheet');

        $this->assertSame(['МО-2|0', 'МО-2|1'], array_column($data['rows'], 'key'));
        [$first, $second] = $data['rows'];
        $this->assertSame([1, 'salon_armada', '05.03.2031', '20.03.2031', '2031-03-05', '2031-03-20', 'Стул Демо', '', [], [], false],
            [$first['pos'], $first['channel'], $first['date'], $first['ship'], $first['dateIso'], $first['shipIso'], $first['name'], $first['chars'], $first['stages'], $first['materials'], $first['completed']]);
        // legacy itemCharacteristicsText: the values that are not empty ('0' is a value)
        $this->assertSame('Дуб, 0', $second['chars']);
        $this->assertSame(['Ткань Демо', '?'], $second['materials']);
        $this->assertEquals([
            ['stage' => 'Сборка', 'workshop' => 'assemble', 'posted' => true, 'started' => false, 'assignees' => ['Демо Мастер']],
            ['stage' => '', 'workshop' => 'paint', 'posted' => false, 'started' => true, 'assignees' => ['Демо Мастер', 'Демо Маляр']],
        ], $second['stages']);
        $this->assertSame([], $data['execs']);
    }
}
