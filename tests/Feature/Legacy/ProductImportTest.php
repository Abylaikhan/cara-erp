<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\ProductImporter;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.products → M07 catalog (synthetic snapshot rows). */
class ProductImportTest extends TestCase
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
    }

    private function rows(array $rows): void
    {
        foreach ($rows as $i => $row) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'products', 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    private function realCatalog(): void
    {
        $this->rows([
            ['id' => 'b1', 'name' => 'Стул «А»', 'category' => 'Стул', 'price_retail' => 45000, 'price_wholesale' => 50000, 'unit' => 'шт', 'active' => true, 'created_by' => null, 'updated_by' => null, 'created_at' => '2025-03-01T10:00:00.123456+00:00', 'updated_at' => '2025-04-01T10:00:00+00:00', 'delete_requested' => false, 'delete_requested_by' => null],
            ['id' => 'b2', 'name' => 'Стол «Б»', 'category' => 'Стол', 'price_retail' => 0, 'price_wholesale' => 0, 'unit' => 'шт', 'active' => true, 'created_by' => 'Автор Легаси', 'created_at' => '2025-03-02T10:00:00+00:00', 'updated_at' => '2025-03-02T10:00:00+00:00', 'delete_requested' => true, 'delete_requested_by' => 'Кто-то'],
            ['id' => 'b3', 'name' => 'Старый', 'category' => 'Стол', 'price_retail' => 100, 'price_wholesale' => 90, 'unit' => 'шт', 'active' => false, 'created_at' => '2024-01-01T00:00:00+00:00', 'updated_at' => '2024-02-01T00:00:00+00:00', 'delete_requested' => false],
        ]);
    }

    public function test_imports_products_keeping_legacy_dates_authors_and_flags(): void
    {
        $this->realCatalog();
        $stats = app(ProductImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([3, 3, 2, 2], [$stats['legacy_rows'], $stats['created'], $stats['active'], $stats['categories']]);
        $a = Product::where('name', 'Стул «А»')->sole();
        $this->assertSame(['45000.00', '50000.00'], [$a->price_retail, $a->price_wholesale]);
        $this->assertSame('2025-03-01 10:00:00.123456', $a->fresh()->created_at->utc()->format('Y-m-d H:i:s.u'), 'microseconds are kept');
        $b = Product::where('name', 'Стол «Б»')->sole();
        $this->assertSame(['Автор Легаси', true, 'Кто-то', null], [$b->created_by_name, $b->delete_requested, $b->delete_requested_by_name, $b->created_by_id]);
        $this->assertFalse(Product::where('name', 'Старый')->sole()->active);
        $this->assertSame(3, DB::table('legacy_id_map')->where('source_table', 'products')->count());
    }

    public function test_import_is_idempotent(): void
    {
        $this->realCatalog();
        app(ProductImporter::class)->import(self::SNAPSHOT);
        $stats = app(ProductImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 3], [$stats['created'], $stats['updated'], $stats['unchanged']]);
        $this->assertSame(3, Product::count());
    }

    public function test_invalid_rows_change_nothing(): void
    {
        $this->rows([
            ['id' => 'c1', 'name' => 'Нормальный', 'category' => 'Стул', 'price_retail' => 1, 'unit' => 'шт'],
            ['id' => 'c2', 'name' => 'Без единицы', 'category' => 'Стул', 'price_retail' => 1, 'unit' => 'кг'],
        ]);
        $this->expectException(RuntimeException::class);
        try {
            app(ProductImporter::class)->import(self::SNAPSHOT);
        } finally {
            $this->assertSame(0, Product::count());
        }
    }
}
