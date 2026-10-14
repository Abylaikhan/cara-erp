<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\OrderImporter;
use App\Legacy\Importers\RealizationImporter;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Realization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.orders + order_items + realizations → M09 (synthetic snapshot rows, natural order of the lines). */
class OrderImportTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.natural_order (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, position integer NOT NULL, row_id text NOT NULL, PRIMARY KEY (snapshot_id, table_name, position), UNIQUE (snapshot_id, table_name, row_id))');
        DB::table('legacy.snapshots')->insert(['id' => self::SNAPSHOT, 'taken_at' => now(), 'manifest' => '{}']);
    }

    private function rows(string $table, array $rows): void
    {
        foreach ($rows as $i => $row) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => $table, 'row_no' => $i + 1, 'data' => json_encode($row, JSON_PRESERVE_ZERO_FRACTION)]);
        }
    }

    /** @param list<int> $ids line ids in the legacy natural order */
    private function naturalOrder(array $ids): void
    {
        DB::table('legacy.natural_order')->where('table_name', 'order_items')->delete();
        foreach ($ids as $p => $id) {
            DB::table('legacy.natural_order')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'order_items', 'position' => $p, 'row_id' => (string) $id]);
        }
    }

    private function legacyData(): void
    {
        $o = ['customer' => 'Демо', 'seller' => 'Демо Продавец', 'end_client' => '', 'payment' => 100, 'status' => 'done', 'comment' => null, 'delivery_date' => null, 'payment_due' => ''];
        $this->rows('orders', [
            ['id' => 1, 'num' => 'МО-1', 'channel' => 'salon_armada', 'created_date' => '2026-03-04 10:00:00', 'date' => '05.03.2026', 'created_at' => '2026-03-04T05:00:00.123456+00:00'] + $o,
            ['id' => 2, 'num' => 'НФНФ-7', 'channel' => null, 'created_date' => '06.03.26', 'date' => '2026-03-10', 'status' => null, 'payment' => 0, 'created_at' => '2026-03-06T05:00:00+00:00'] + $o,
        ]);
        $i = ['category' => null, 'item_type' => null, 'resp' => null, 'date' => null, 'progress' => null, 'status' => null, 'created_at' => '2026-03-04T05:00:00+00:00'];
        // Physical rows in another order than the natural one; the second order has an object of characteristics.
        $this->rows('order_items', [
            ['id' => 11, 'order_num' => 'МО-1', 'name' => 'Стул B', 'qty' => 2, 'price' => 1500.5, 'attributes' => []] + $i,
            ['id' => 10, 'order_num' => 'МО-1', 'name' => 'Стул A', 'qty' => 1, 'price' => 1000, 'attributes' => [], 'progress' => 50, 'status' => 'progress'] + $i,
            ['id' => 12, 'order_num' => 'НФНФ-7', 'name' => 'Стол', 'qty' => 1.5, 'price' => 30000, 'attributes' => ['Цвет' => 'Дуб'], 'date' => '07.03.26'] + $i,
        ]);
        $this->naturalOrder([10, 11, 12]);
    }

    public function test_imports_orders_with_their_lines_in_legacy_order(): void
    {
        $this->legacyData();
        $stats = app(OrderImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([2, 3, 2, 3], [$stats['legacy_orders'], $stats['legacy_items'], $stats['created'], $stats['items']]);
        $a = Order::where('num', 'МО-1')->sole();
        $this->assertSame(['salon_armada', '2026-03-04', '2026-03-05', 100, 'done', null], [$a->channel, $a->order_date->format('Y-m-d'), $a->due_date->format('Y-m-d'), $a->payment_pct, $a->status, $a->end_client]);
        $this->assertSame('2026-03-04 05:00:00.123456', $a->fresh()->created_at->utc()->format('Y-m-d H:i:s.u'));
        $this->assertSame([['Стул A', '1.0000', '1000.00', 50, 'progress'], ['Стул B', '2.0000', '1500.50', 0, 'queue']],
            $a->items->map(fn (OrderItem $i) => [$i->name, $i->qty, $i->price, $i->progress, $i->status])->all());
        $this->assertSame([0, 1], $a->items->pluck('line_no')->all());
        $b = Order::where('num', 'НФНФ-7')->sole();
        $this->assertSame(['office', '2026-03-06', 'queue', 0], [$b->channel, $b->order_date->format('Y-m-d'), $b->status, $b->payment_pct]);
        $this->assertSame(['1.5000', '2026-03-07'], [$b->items->first()->qty, $b->items->first()->item_date->format('Y-m-d')]);
        $this->assertSame('{"Цвет": "Дуб"}', DB::table('order_items')->where('name', 'Стол')->value('attrs'));
        $this->assertSame('[]', DB::table('order_items')->where('name', 'Стул A')->value('attrs'));

        // A second run changes nothing; a line removed in legacy goes, the others keep their places.
        $this->assertSame([0, 0, 2], array_values(array_intersect_key(app(OrderImporter::class)->import(self::SNAPSHOT), array_flip(['created', 'updated', 'unchanged']))));
        DB::table('legacy.rows')->where('table_name', 'order_items')->whereRaw("data->>'id' = '10'")->delete();
        $this->naturalOrder([11, 12]);
        $again = app(OrderImporter::class)->import(self::SNAPSHOT);
        $this->assertSame(1, $again['removed_lines']);
        $this->assertSame([['Стул B', 0]], $a->fresh()->items->map(fn (OrderItem $i) => [$i->name, $i->line_no])->all());
    }

    public function test_realizations_link_to_orders_by_number_and_keep_the_others(): void
    {
        $this->legacyData();
        app(OrderImporter::class)->import(self::SNAPSHOT);
        $this->rows('realizations', [
            ['id' => 'r1', 'order_num' => 'МО-1', 'doc_num' => 'РН-1', 'doc_date' => '2026-03-05', 'customer' => 'Демо', 'name' => 'Стул A', 'qty' => 1.0, 'unit' => 'шт', 'revenue' => 1000.25, 'created_at' => '2026-03-05T05:00:00+00:00'],
            ['id' => 'r2', 'order_num' => 'МО-999', 'doc_num' => null, 'doc_date' => '2026-03-06', 'customer' => 'Демо', 'name' => 'Стол', 'qty' => 2, 'unit' => null, 'revenue' => 500, 'created_at' => '2026-03-06T05:00:00+00:00'],
        ]);
        $stats = app(RealizationImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([2, 1, 1], [$stats['created'], $stats['linked'], $stats['without_order']]);
        $r1 = Realization::where('doc_num', 'РН-1')->sole();
        $this->assertSame([Order::where('num', 'МО-1')->value('id'), '1.0000', '1000.25', '2026-03-05'], [$r1->order_id, $r1->qty, $r1->revenue, $r1->doc_date->format('Y-m-d')]);
        $this->assertSame(['МО-999', null], [Realization::where('name', 'Стол')->sole()->order_num, Realization::where('name', 'Стол')->sole()->order_id]);
    }

    public function test_rejects_the_whole_set_when_a_row_is_wrong(): void
    {
        $this->rows('orders', [
            ['id' => 1, 'num' => 'МО-1', 'channel' => 'salon_unknown', 'created_date' => 'вчера', 'date' => '', 'payment' => 150, 'status' => 'archived', 'created_at' => '2026-03-04T05:00:00+00:00'],
            ['id' => 2, 'num' => 'МО-1', 'channel' => 'office', 'created_date' => '', 'date' => '', 'payment' => 0, 'status' => 'queue', 'created_at' => '2026-03-04T05:00:00+00:00'],
        ]);
        $this->rows('order_items', [['id' => 10, 'order_num' => 'МО-404', 'name' => 'Демо', 'qty' => 1, 'price' => 1, 'created_at' => '2026-03-04T05:00:00+00:00']]);

        try {
            app(OrderImporter::class)->import(self::SNAPSHOT);
            $this->fail('import must be rejected');
        } catch (RuntimeException $e) {
            foreach (['unknown channel', 'unknown status', 'payment is not a whole percentage', 'created_date is not a date', 'duplicate or too long number', 'no natural order', 'order not found'] as $problem) {
                $this->assertStringContainsString($problem, $e->getMessage());
            }
        }
        $this->assertSame([0, 0], [Order::count(), OrderItem::count()]);
    }
}
