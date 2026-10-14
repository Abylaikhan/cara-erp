<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\IncomingInvoiceImporter;
use App\Models\EsfPaymentLink;
use App\Models\IncomingInvoice;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.incoming_invoices + items + esf_payment_links → M05 (synthetic snapshot rows). */
class EsfImportTest extends TestCase
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

    private function rows(string $table, array $json): void
    {
        DB::table('legacy.rows')->where('table_name', $table)->delete();
        foreach ($json as $i => $row) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => $table, 'row_no' => $i + 1, 'data' => $row]);
        }
    }

    private function legacyEsf(): void
    {
        $this->rows('incoming_invoices', [
            '{"id":"v1","num":"101","reg_num":"ESF-DEMO-1","date":"2026-02-28","status":"DELIVERED","invoice_type":"goods","supplier_name":"ТОО «Демо Плита»","supplier_bin":"000000000071","buyer_name":"ТОО «Демо»","buyer_bin":"000000000001","currency":"KZT","total_amount":13455.000000000002,"total_nds":1614.6,"total_with_nds":15069.599999999999,"created_at":"2026-03-01T10:00:00.123456+00:00","posted_at":null}',
            '{"id":"v2","num":"7","reg_num":null,"date":"2026-03-05","status":"CANCELED","invoice_type":null,"supplier_name":"ИП Демо","supplier_bin":"000000000072","currency":"KZT","total_amount":-100,"total_nds":0,"total_with_nds":-100,"created_at":"2026-03-06T10:00:00+00:00"}',
        ]);
        $this->rows('incoming_invoice_items', [
            '{"id":"i3","invoice_id":"v1","description":"Вторая строка","qty":1,"unit_price":455,"amount":455,"nds_rate":12.0,"nds_amount":54.6,"total":509.6,"warehouse_id":null}',
            '{"id":"i1","invoice_id":"v1","description":"Первая строка","qty":1.5,"unit_price":8666.67,"amount":13000,"nds_rate":12,"nds_amount":1560,"total":14560,"warehouse_id":"wh_plates"}',
            '{"id":"i2","invoice_id":"v2","description":"Возврат","qty":-1,"unit_price":100,"amount":-100,"nds_rate":0,"nds_amount":0,"total":-100,"warehouse_id":null}',
        ]);
        $this->rows('esf_payment_links', [
            '{"id":"l1","esf_invoice_id":"v1","source_type":"money_tx","source_id":"m1","amount":10000.004999999999,"created_at":"2026-03-02T10:00:00+00:00"}',
            '{"id":"l2","esf_invoice_id":"v1","source_type":"advance_report","source_id":"a1","amount":5069.6,"created_at":"2026-03-03T10:00:00+00:00"}',
        ]);
        // Natural order of the lines: «Первая строка» was inserted first.
        foreach (['i1', 'i3', 'i2'] as $pos => $id) {
            DB::table('legacy.natural_order')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'incoming_invoice_items', 'position' => $pos + 1, 'row_id' => $id]);
        }
    }

    public function test_imports_invoices_lines_in_natural_order_and_links(): void
    {
        $this->legacyEsf();
        $plita = Supplier::create(['name' => 'ТОО «Демо Плита»', 'bin' => '000000000071']);
        Supplier::create(['name' => 'ИП Демо 1', 'bin' => '000000000072']);
        Supplier::create(['name' => 'ИП Демо 2', 'bin' => '000000000072']);

        $stats = app(IncomingInvoiceImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([2, 2, 3, 'natural', 2, 1, 0], [$stats['legacy_invoices'], $stats['created'], $stats['items'], $stats['item_order'], $stats['links'], $stats['with_supplier'], $stats['invoices_totals_differ_from_lines']]);
        $v1 = IncomingInvoice::where('reg_num', 'ESF-DEMO-1')->sole();
        $this->assertSame(['13455.00', '1614.60', '15069.60', 'goods', $plita->id, '2026-02-28'], [$v1->total_amount, $v1->total_nds, $v1->total_with_nds, $v1->invoice_type, $v1->supplier_id, $v1->date->format('Y-m-d')]);
        $this->assertSame('2026-03-01 10:00:00.123456', $v1->fresh()->created_at->utc()->format('Y-m-d H:i:s.u'));
        $this->assertSame(['Первая строка', 'Вторая строка'], $v1->items->pluck('description')->all());
        $this->assertSame(['1.500', '8666.67', 'wh_plates'], [$v1->items[0]->qty, $v1->items[0]->unit_price, $v1->items[0]->warehouse_code]);
        $v2 = IncomingInvoice::where('num', '7')->sole();
        $this->assertSame(['-100.00', 'general', null, 'CANCELED'], [$v2->total_with_nds, $v2->invoice_type, $v2->supplier_id, $v2->status], 'a correcting ESF; two suppliers share the BIN — no link');
        $this->assertSame(['10000.00', '5069.60'], EsfPaymentLink::orderBy('id')->pluck('amount')->all());
        $this->assertSame(['m1', 'a1'], EsfPaymentLink::orderBy('id')->pluck('source_legacy_id')->all());
    }

    public function test_import_is_idempotent(): void
    {
        $this->legacyEsf();
        app(IncomingInvoiceImporter::class)->import(self::SNAPSHOT);
        $stats = app(IncomingInvoiceImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 2], [$stats['created'], $stats['updated'], $stats['unchanged']]);
        $this->assertSame([2, 3, 2], [IncomingInvoice::count(), DB::table('incoming_invoice_items')->count(), EsfPaymentLink::count()]);
    }

    public function test_bad_rows_change_nothing(): void
    {
        $this->legacyEsf();
        $this->rows('esf_payment_links', ['{"id":"l9","esf_invoice_id":"v-unknown","source_type":"money_tx","source_id":"m","amount":1,"created_at":"2026-03-02T10:00:00+00:00"}']);
        $this->expectException(RuntimeException::class);
        try {
            app(IncomingInvoiceImporter::class)->import(self::SNAPSHOT);
        } finally {
            $this->assertSame(0, IncomingInvoice::count());
        }
    }
}
