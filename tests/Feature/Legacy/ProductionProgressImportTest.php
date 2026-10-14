<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\ProductionProgressImporter;
use App\Models\Order;
use App\Models\ProductionProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.production_progress → M02: a record finds its order line by «num|index» (synthetic rows). */
class ProductionProgressImportTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => self::SNAPSHOT, 'taken_at' => now(), 'manifest' => '{}']);
        $order = new Order(['num' => 'МО-1', 'channel' => 'office', 'status' => 'queue', 'payment_pct' => 0]);
        $order->save();
        foreach (['Стул', 'Стол'] as $i => $name) {
            $order->items()->create(['line_no' => $i, 'name' => $name, 'qty' => '1', 'price' => '1', 'progress' => 0, 'status' => 'queue', 'attrs' => []]);
        }
    }

    private function rows(array $rows): void
    {
        foreach ($rows as $i => $row) {
            $row += ['stages' => [], 'materials' => [], 'completed' => false, 'item_name' => null, 'updated_at' => '2026-03-01T08:00:00+00:00'];
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'production_progress', 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    public function test_imports_progress_onto_its_order_line(): void
    {
        $this->rows([
            ['id' => 1, 'item_key' => 'МО-1|1', 'order_num' => 'МО-1', 'item_idx' => 1, 'stages' => [['stage' => 'Сборка каркаса', 'qty' => 1]], 'materials' => [['name' => 'Клей', 'qty' => 0.5]]],
            ['id' => 2, 'item_key' => 'МО-1|0', 'order_num' => 'МО-1', 'item_idx' => 0, 'completed' => true],
        ]);
        $stats = app(ProductionProgressImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([2, 2, 2], [$stats['legacy_rows'], $stats['created'], $stats['with_activity']]);
        $stol = ProductionProgress::query()->whereHas('item', fn ($q) => $q->where('name', 'Стол'))->sole();
        $this->assertEquals([[['stage' => 'Сборка каркаса', 'qty' => 1]], [['name' => 'Клей', 'qty' => 0.5]], false], [$stol->stages, $stol->materials, $stol->completed]);
        $this->assertTrue(ProductionProgress::query()->whereHas('item', fn ($q) => $q->where('name', 'Стул'))->sole()->completed);

        $again = app(ProductionProgressImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 2], [$again['created'], $again['updated'], $again['unchanged']]);
    }

    public function test_rejects_records_of_unknown_lines(): void
    {
        $this->rows([['id' => 1, 'item_key' => 'МО-1|5'], ['id' => 2, 'item_key' => 'МО-1|0'], ['id' => 3, 'item_key' => 'МО-1|0']]);

        try {
            app(ProductionProgressImporter::class)->import(self::SNAPSHOT);
            $this->fail('import must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('row 1: order line МО-1|5 not found', $e->getMessage());
            $this->assertStringContainsString('row 3: second record for order line МО-1|0', $e->getMessage());
        }
        $this->assertSame(0, ProductionProgress::count());
    }
}
