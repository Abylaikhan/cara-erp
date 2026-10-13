<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\SupplierImporter;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.suppliers → M06 (synthetic snapshot rows). */
class SupplierImportTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    private ExpenseCategory $article;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => self::SNAPSHOT, 'taken_at' => now(), 'manifest' => '{}']);
        $top = ExpenseCategory::create(['code' => 'commercial', 'name' => 'Коммерческие', 'sort_order' => 10]);
        $this->article = ExpenseCategory::create(['code' => 'com_rent', 'name' => 'Аренда', 'parent_id' => $top->id, 'sort_order' => 10]);
    }

    private function rows(array $rows): void
    {
        DB::table('legacy.rows')->where('table_name', 'suppliers')->delete();
        foreach ($rows as $i => $row) {
            $row += ['category' => null, 'phone' => null, 'email' => null, 'contact' => null, 'inn' => null, 'comment' => null, 'status' => 'active',
                'bin' => null, 'expense_category' => null, 'expense_subcategory' => null, 'expense_source' => 'payment', 'created_at' => '2025-05-01T08:00:00+00:00'];
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'suppliers', 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    private function legacySuppliers(): void
    {
        $this->rows([
            ['id' => 's1', 'name' => 'ТОО «Демо Плита»', 'category' => 'ЛДСП', 'bin' => '000000000041', 'comment' => '', 'created_at' => '2025-03-01T10:00:00.654321+00:00'],
            ['id' => 's2', 'name' => 'ТОО «Демо Аренда»', 'expense_category' => 'commercial', 'expense_subcategory' => 'com_rent', 'expense_source' => 'esf', 'inn' => '000000000042'],
            ['id' => 's3', 'name' => 'ИП Демо', 'status' => '', 'expense_source' => null, 'expense_category' => 'commercial'],
        ]);
    }

    public function test_imports_suppliers_with_expense_links_and_legacy_defaults(): void
    {
        $this->legacySuppliers();
        $stats = app(SupplierImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([3, 3, 3, 1, 2], [$stats['legacy_rows'], $stats['created'], $stats['suppliers'], $stats['with_bin'], $stats['with_expense_item']]);
        $plita = Supplier::where('name', 'ТОО «Демо Плита»')->sole();
        $this->assertSame(['ЛДСП', '000000000041', null, 'active', 'payment'], [$plita->category, $plita->bin, $plita->comment, $plita->status, $plita->expense_source]);
        $this->assertSame('2025-03-01 10:00:00.654321', $plita->fresh()->created_at->utc()->format('Y-m-d H:i:s.u'));
        $rent = Supplier::where('name', 'ТОО «Демо Аренда»')->sole();
        $this->assertSame([$this->article->id, 'esf', '000000000042'], [$rent->expense_category_id, $rent->expense_source, $rent->inn]);
        $ip = Supplier::where('name', 'ИП Демо')->sole();
        $this->assertSame([$this->article->parent_id, 'active', 'payment'], [$ip->expense_category_id, $ip->status, $ip->expense_source]);
        $this->assertSame(3, DB::table('legacy_id_map')->where('source_table', 'suppliers')->count());
    }

    public function test_import_is_idempotent(): void
    {
        $this->legacySuppliers();
        app(SupplierImporter::class)->import(self::SNAPSHOT);
        $stats = app(SupplierImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 3], [$stats['created'], $stats['updated'], $stats['unchanged']]);
        $this->assertSame(3, Supplier::count());
    }

    public function test_rows_that_cannot_be_kept_change_nothing(): void
    {
        $bad = [
            ['status' => 'archived'],
            ['expense_source' => 'cash'],
            ['expense_category' => 'unknown_code'],
            ['expense_category' => 'com_rent'],                                  // an article given as the category
            ['expense_category' => 'commercial', 'expense_subcategory' => 'x'],  // unknown article
            ['name' => '  '],
        ];
        foreach ($bad as $i => $fields) {
            $this->rows([['id' => 'ok', 'name' => 'Нормальный'], $fields + ['id' => "bad{$i}", 'name' => 'Ошибочный']]);
            try {
                app(SupplierImporter::class)->import(self::SNAPSHOT);
                $this->fail('Import must refuse: '.json_encode($fields, JSON_UNESCAPED_UNICODE));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('nothing imported', $e->getMessage());
            }
            $this->assertSame(0, Supplier::count());
        }
    }
}
