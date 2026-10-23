<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\ProductionWorksheetImporter;
use App\Legacy\LegacyRejected;
use App\Models\Order;
use App\Models\ProductionWorksheetRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** legacy production_worksheet → «Рабочий лист производства»: copied exactly, linked to the order lines, idempotent (synthetic rows). */
class ProductionWorksheetImportTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.natural_order (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, position integer NOT NULL, row_id text NOT NULL, PRIMARY KEY (snapshot_id, table_name, position))');
        DB::table('legacy.snapshots')->insert(['id' => self::SNAPSHOT, 'taken_at' => now(), 'manifest' => '{}']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function legacy(array $rows, array $natural = []): void
    {
        DB::table('legacy.rows')->where('table_name', 'production_worksheet')->delete();
        DB::table('legacy.natural_order')->where('table_name', 'production_worksheet')->delete();
        foreach ($rows as $i => $r) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'production_worksheet', 'row_no' => $i + 1, 'data' => json_encode($r)]);
        }
        foreach ($natural as $i => $id) {
            DB::table('legacy.natural_order')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'production_worksheet', 'position' => $i + 1, 'row_id' => (string) $id]);
        }
    }

    public function test_rows_are_copied_and_linked_in_the_natural_order_and_a_second_import_changes_nothing(): void
    {
        $order = new Order(['num' => 'ТЕСТ-7', 'channel' => 'office', 'customer' => 'Демо', 'status' => 'queue', 'payment_pct' => 0]);
        $order->save();
        $order->items()->create(['line_no' => 0, 'name' => 'Стол', 'qty' => 1, 'price' => 1, 'progress' => 0, 'status' => 'queue', 'attrs' => []]);
        $second = $order->items()->create(['line_no' => 1, 'name' => 'Стул', 'qty' => 1, 'price' => 1, 'progress' => 0, 'status' => 'queue', 'attrs' => []]);
        $this->legacy([
            ['id' => 41, 'order_num' => 'ТЕСТ-7', 'item_idx' => 1, 'added_at' => '2031-04-02T05:00:00.123456+00:00'],
            ['id' => 42, 'order_num' => 'НЕТ-ТАКОГО', 'item_idx' => 0, 'added_at' => '2031-04-02T05:00:01+00:00'],
        ], [42, 41]);

        $stats = app(ProductionWorksheetImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([2, 2, 1], [$stats['legacy_rows'], $stats['created'], $stats['linked']]);
        $this->assertSame([42, 41], ProductionWorksheetRow::query()->orderBy('legacy_position')->pluck('legacy_id')->all());
        $row = ProductionWorksheetRow::query()->where('legacy_id', 41)->first();
        $this->assertSame([$second->id, 'ТЕСТ-7', 1, '123456'], [$row->order_item_id, $row->order_num, $row->item_idx, $row->added_at->format('u')]);
        $this->assertNull(ProductionWorksheetRow::query()->where('legacy_id', 42)->value('order_item_id'), 'a line of no order stays unlinked');

        $again = app(ProductionWorksheetImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 2, 0], [$again['created'], $again['updated'], $again['unchanged'], $again['removed']]);

        $this->legacy([['id' => 41, 'order_num' => 'ТЕСТ-7', 'item_idx' => 0, 'added_at' => '2031-04-02T05:00:00.123456+00:00']]);
        $third = app(ProductionWorksheetImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([1, 1], [$third['updated'], $third['removed']]);
        $this->assertSame(1, DB::table('legacy_id_map')->where('source_table', 'production_worksheet')->count());
    }

    public function test_a_bad_set_is_refused_whole(): void
    {
        $this->legacy([
            ['id' => 'x', 'order_num' => '', 'item_idx' => -1],
            ['id' => 5, 'order_num' => 'ТЕСТ-7', 'item_idx' => 0],
            ['id' => 6, 'order_num' => 'ТЕСТ-7', 'item_idx' => 0],
        ]);
        try {
            app(ProductionWorksheetImporter::class)->import(self::SNAPSHOT);
            $this->fail('accepted');
        } catch (LegacyRejected $e) {
            $this->assertSame(['row 1: missing, malformed or duplicate id', 'row 1: order number missing or too long', 'row 1: item_idx is not a line index',
                'row 3: the same order line twice'], $e->problems());
        }
        $this->assertSame(0, ProductionWorksheetRow::query()->count());
    }
}
