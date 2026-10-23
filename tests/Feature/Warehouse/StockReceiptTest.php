<?php

namespace Tests\Feature\Warehouse;

use App\Legacy\Importers\MaterialImporter;
use App\Models\Material;
use App\Models\User;
use App\Services\Warehouse\StockDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** «Склад» → «📥 Приходная накладная» (№ 45, D-04 — ADR-0049): a draft keeps the stock, posting adds it once, with a movement. */
class StockReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storekeeper = User::factory()->role('storekeeper')->create(['name' => 'Демо Склад']);
    }

    private function material(string $qty = '12.500', array $attrs = []): Material
    {
        $m = new Material(array_merge(['name' => 'ЛДСП Демо 16мм', 'category' => 'ЛДСП/МДФ', 'unit' => 'лист', 'price' => '8500.00', 'min_qty' => 0, 'warehouse_code' => 'wh_plates'], $attrs));
        $m->forceFill(['qty' => $qty])->save();

        return $m;
    }

    /** @return array<string, mixed> */
    private function receipt(array $lines, array $head = []): array
    {
        return ['date' => '2031-04-01', 'num' => null, 'supplier' => 'ТОО «Демо Поставщик»', 'esf_ref' => 'ESF-1', 'note' => null, 'lines' => $lines, ...$head];
    }

    public function test_posting_adds_the_quantities_once_and_writes_the_movements(): void
    {
        $a = $this->material();
        $b = $this->material('0', ['name' => 'Кромка Демо', 'unit' => 'м']);
        $service = new StockDocumentService;
        $doc = $service->saveReceipt($this->receipt([
            ['material_id' => $a->id, 'qty' => '2.25', 'price' => '8600.5'],
            ['material_id' => $b->id, 'qty' => '0', 'price' => '1'], // legacy keeps only the lines with a quantity
            ['material_id' => $b->id, 'qty' => '100', 'price' => '33.333'],
        ]), true, $this->storekeeper);

        $this->assertSame(['ПН-001', 'posted'], [$doc->num, $doc->status]);
        $this->assertSame(['14.750', '100.000'], [$a->fresh()->qty, $b->fresh()->qty]);
        $this->assertSame([['лист', '19351.13'], ['м', '3333.00']], $doc->lines()->get()->map(fn ($l) => [$l->unit, (string) $l->amount])->all());
        $this->assertSame([[$a->id, '2.250', '14.750', false], [$b->id, '100.000', '100.000', false]],
            DB::table('stock_movements')->orderBy('id')->get()->map(fn ($m) => [$m->material_id, $m->qty_delta, $m->qty_after, $m->below_zero])->all());
        $this->assertDatabaseHas('audit_events', ['action' => 'stock_document.posted', 'subject_id' => (string) $doc->id]);

        try {
            $service->post($doc->id, $doc->lock_version, $this->storekeeper);
            $this->fail('a posted document is posted once');
        } catch (ValidationException $e) {
            $this->assertSame('Документ уже проведён — изменить его нельзя.', collect($e->errors())->flatten()->first());
        }
        $this->assertSame('14.750', $a->fresh()->qty);
    }

    public function test_a_draft_keeps_the_stock_and_is_posted_later_under_its_version(): void
    {
        $a = $this->material();
        $service = new StockDocumentService;
        $draft = $service->saveReceipt($this->receipt([['material_id' => $a->id, 'qty' => '1', 'price' => '10']], ['num' => 'ПН-007']), false, $this->storekeeper);
        $this->assertSame(['draft', '12.500', 0], [$draft->status, $a->fresh()->qty, DB::table('stock_movements')->count()]);
        $this->assertSame('ПН-008', StockDocumentService::nextNumber('receipt'));

        $edited = $service->saveReceipt($this->receipt([['material_id' => $a->id, 'qty' => '3', 'price' => '10']]), false, $this->storekeeper, $draft->id, 1);
        $this->assertSame(['ПН-007', 2], [$edited->num, $edited->lock_version]);
        try {
            $service->post($draft->id, 1, $this->storekeeper);
            $this->fail('a stale version is refused');
        } catch (ValidationException) {
        }
        $service->post($draft->id, 2, $this->storekeeper);
        $this->assertSame('15.500', $a->fresh()->qty);
    }

    public function test_the_form_is_checked_as_legacy_checks_it_and_a_number_is_taken_once(): void
    {
        $a = $this->material();
        $service = new StockDocumentService;
        foreach ([[[], 'Добавьте хотя бы одну позицию'], [[['material_id' => $a->id + 9, 'qty' => '1', 'price' => '1']], 'Материал не найден — обновите страницу.']] as [$lines, $message]) {
            try {
                $service->saveReceipt($this->receipt($lines), true, $this->storekeeper);
                $this->fail($message);
            } catch (ValidationException $e) {
                $this->assertSame($message, collect($e->errors())->flatten()->first());
            }
        }
        $service->saveReceipt($this->receipt([['material_id' => $a->id, 'qty' => '1', 'price' => '1']], ['num' => 'ПН-5']), false, $this->storekeeper);
        $this->expectException(ValidationException::class);
        $service->saveReceipt($this->receipt([['material_id' => $a->id, 'qty' => '1', 'price' => '1']], ['num' => 'ПН-5']), false, $this->storekeeper);
    }

    public function test_the_import_of_materials_stops_once_documents_moved_the_stock(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => 'S1', 'taken_at' => now(), 'manifest' => '{}']);
        DB::table('legacy.rows')->insert(['snapshot_id' => 'S1', 'table_name' => 'warehouse', 'row_no' => 1, 'data' => json_encode(['id' => 'm-1'])]);
        (new StockDocumentService)->saveReceipt($this->receipt([['material_id' => $this->material()->id, 'qty' => '1', 'price' => '1']]), true, $this->storekeeper);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Остатки склада уже меняли документы новой системы');
        (new MaterialImporter)->import('S1');
    }
}
