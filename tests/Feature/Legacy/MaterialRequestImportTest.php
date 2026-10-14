<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\MaterialRequestImporter;
use App\Models\MaterialRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.material_requests → M04 (synthetic snapshot rows). */
class MaterialRequestImportTest extends TestCase
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
            $row += ['qty' => 0, 'unit' => '', 'order_num' => '', 'item_name' => '', 'workshop' => '', 'requested_by' => '', 'purchase_note' => null, 'status' => 'requested',
                'created_at' => '2026-03-01T08:00:00+00:00', 'updated_at' => null];
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'material_requests', 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    public function test_imports_requests_with_exact_quantities_and_times(): void
    {
        $this->rows([
            ['id' => 1, 'material_name' => 'Поролон Демо', 'qty' => 12.5, 'unit' => 'м²', 'order_num' => 'З-1', 'item_name' => 'Диван', 'workshop' => 'upholstery', 'status' => 'in_purchase',
                'created_at' => '2026-03-01T08:00:00.123456+00:00', 'updated_at' => '2026-03-02T09:00:00+00:00'],
            ['id' => 2, 'material_name' => 'Клей Демо', 'status' => null],
        ]);
        $stats = app(MaterialRequestImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([2, 2, 2], [$stats['legacy_rows'], $stats['created'], $stats['open']]);
        $a = MaterialRequest::where('material_name', 'Поролон Демо')->sole();
        $this->assertSame(['12.5000', 'м²', 'З-1', 'Диван', 'upholstery', null, 'in_purchase'], [$a->qty, $a->unit, $a->order_num, $a->item_name, $a->workshop, $a->requested_by, $a->status]);
        $this->assertSame('2026-03-01 08:00:00.123456', $a->fresh()->created_at->utc()->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-03-02 09:00:00', $a->fresh()->updated_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('requested', MaterialRequest::where('material_name', 'Клей Демо')->sole()->status);

        $again = app(MaterialRequestImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 2], [$again['created'], $again['updated'], $again['unchanged']]);
    }

    public function test_rejects_the_whole_set_when_a_row_is_wrong(): void
    {
        $this->rows([
            ['id' => 1, 'material_name' => 'Демо'],
            ['id' => 2, 'material_name' => ' '],
            ['id' => 3, 'material_name' => 'Демо', 'status' => 'ordered'],
            ['id' => 4, 'material_name' => 'Демо', 'qty' => -1],
            ['id' => 5, 'material_name' => 'Демо', 'workshop' => 'kitchen'],
        ]);

        try {
            app(MaterialRequestImporter::class)->import(self::SNAPSHOT);
            $this->fail('import must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('row 2: empty material name', $e->getMessage());
            $this->assertStringContainsString('row 3: unknown status', $e->getMessage());
            $this->assertStringContainsString('row 4: qty is not a non-negative number', $e->getMessage());
            $this->assertStringContainsString('row 5: unknown workshop', $e->getMessage());
        }
        $this->assertSame(0, MaterialRequest::count());
    }
}
