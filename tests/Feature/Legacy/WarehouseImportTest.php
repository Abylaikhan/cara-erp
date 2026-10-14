<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\MaterialAliasImporter;
use App\Legacy\Importers\MaterialImporter;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Support\LegacyNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.warehouse and legacy.material_aliases → M03 (synthetic snapshot rows). */
class WarehouseImportTest extends TestCase
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

    /** Raw JSON text, so numbers reach jsonb exactly as written (9915.335, 1.005). */
    private function rows(string $table, array $jsonRows): void
    {
        DB::table('legacy.rows')->where('table_name', $table)->delete();
        foreach ($jsonRows as $i => $json) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => $table, 'row_no' => $i + 1, 'data' => $json]);
        }
    }

    private function legacyMaterials(): void
    {
        $this->rows('warehouse', [
            '{"id":"w1","name":"ЛДСП Демо","category":"ЛДСП/МДФ","unit":"лист","qty":12.125,"price":9915.335,"min_qty":5,"supplier":"ТОО «Демо»","warehouse_id":"wh_plates","created_at":"2025-03-01T10:00:00.111111+00:00"}',
            '{"id":"w2","name":"Ткань Демо","category":"Ткань/Обивочные","unit":"м","qty":0,"price":2279.865,"min_qty":0,"supplier":"","warehouse_id":null,"created_at":"2025-03-02T10:00:00+00:00"}',
            '{"id":"w3","name":"Без единицы","category":"","unit":"","qty":1.005,"price":0,"min_qty":0,"supplier":null,"warehouse_id":"wh_main","created_at":"2025-03-03T10:00:00+00:00"}',
        ]);
    }

    public function test_imports_materials_with_exact_quantities_and_legacy_prices(): void
    {
        $this->legacyMaterials();
        $stats = app(MaterialImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([3, 3, 3, 2, 3], [$stats['legacy_rows'], $stats['created'], $stats['materials'], $stats['in_stock'], $stats['categories']]);
        $a = Material::where('name', 'ЛДСП Демо')->sole();
        $this->assertSame(['12.125', '9915.33', '5.000', 'ТОО «Демо»', 'wh_plates', 'лист'], [$a->qty, $a->price, $a->min_qty, $a->supplier_name, $a->warehouse_code, $a->unit]);
        $this->assertSame('2025-03-01 10:00:00.111111', $a->fresh()->created_at->utc()->format('Y-m-d H:i:s.u'));
        $b = Material::where('name', 'Ткань Демо')->sole();
        $this->assertSame(['2279.86', null, null], [$b->price, $b->supplier_name, $b->warehouse_code]);
        $c = Material::where('name', 'Без единицы')->sole();
        $this->assertSame(['Прочее', 'шт', '1.005'], [$c->category, $c->unit, $c->qty]);
        $this->assertSame(3, DB::table('legacy_id_map')->where('source_table', 'warehouse')->count());
    }

    public function test_material_import_is_idempotent(): void
    {
        $this->legacyMaterials();
        app(MaterialImporter::class)->import(self::SNAPSHOT);
        $stats = app(MaterialImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 3], [$stats['created'], $stats['updated'], $stats['unchanged']]);
    }

    public function test_bad_material_rows_change_nothing(): void
    {
        $bad = ['"warehouse_id":"wh_unknown"', '"price":-1', '"qty":"много"', '"name":"  "'];
        foreach ($bad as $i => $field) {
            $this->rows('warehouse', [
                '{"id":"ok","name":"Нормальный","category":"Прочее","unit":"шт","qty":1,"price":1,"min_qty":0,"created_at":"2025-01-01T00:00:00+00:00"}',
                '{"name":"Ошибочный","category":"Прочее","unit":"шт","qty":1,"price":1,"min_qty":0,"created_at":"2025-01-01T00:00:00+00:00","id":"bad'.$i.'",'.$field.'}',
            ]);
            try {
                app(MaterialImporter::class)->import(self::SNAPSHOT);
                $this->fail('Import must refuse: '.$field);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('nothing imported', $e->getMessage());
            }
            $this->assertSame(0, Material::count());
        }
    }

    public function test_alias_copies_become_one_alias_and_every_source_row_is_mapped(): void
    {
        $this->rows('material_aliases', [
            '{"id":"a1","material_name":"ЛДСП Демо","alias_name":"Плита Демо 2800","category":"ЛДСП/МДФ","created_at":"2025-03-01T10:05:00+00:00"}',
            '{"id":"a2","material_name":"ЛДСП Демо","alias_name":"Плита Демо 2800","category":"ЛДСП/МДФ","created_at":"2025-03-01T10:00:00+00:00"}',
            '{"id":"a3","material_name":"ЛДСП Демо","alias_name":"Плита Демо 2070","category":"ЛДСП/МДФ","created_at":"2025-03-01T10:00:00+00:00"}',
        ]);
        $stats = app(MaterialAliasImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([3, 2, 2, 1], [$stats['legacy_rows'], $stats['aliases'], $stats['created'], $stats['duplicate_rows']]);
        $alias = MaterialAlias::where('alias_name', 'Плита Демо 2800')->sole();
        $this->assertSame('2025-03-01 10:00:00', $alias->created_at->utc()->format('Y-m-d H:i:s'), 'the earliest copy');
        $this->assertSame([$alias->id, $alias->id], DB::table('legacy_id_map')->where('source_table', 'material_aliases')->whereIn('source_key', ['a1', 'a2'])->pluck('target_id')->all());

        $again = app(MaterialAliasImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 2], [$again['created'], $again['updated'], $again['unchanged']]);
        $this->assertSame(2, MaterialAlias::count());
    }

    public function test_legacy_rounding_matches_javascript(): void
    {
        // Math.round(x * 100) / 100 in JS for the same inputs.
        $cases = ['9915.335' => '9915.33', '2279.865' => '2279.86', '1.005' => '1.00', '1.115' => '1.12', '0.125' => '0.13', '12.5' => '12.50', '0' => '0.00', '737500' => '737500.00'];
        foreach ($cases as $in => $expected) {
            $this->assertSame($expected, LegacyNumber::round2($in), $in);
        }
    }
}
