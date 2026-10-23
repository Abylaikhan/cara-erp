<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\SalesWorksheetImporter;
use App\Legacy\LegacyRejected;
use App\Models\Order;
use App\Models\SalesWorksheetRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** legacy sales_worksheet → «Рабочий лист реализации»: copied exactly, linked to orders and realizations, idempotent (synthetic rows). */
class SalesWorksheetImportTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    private const DOC = 'abababab-abab-4bab-8bab-abababababab';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => self::SNAPSHOT, 'taken_at' => now(), 'manifest' => '{}']);
    }

    private function line(string $id, int $no, array $override = []): array
    {
        return $override + ['id' => $id, 'doc_id' => self::DOC, 'doc_num' => '7', 'doc_date' => '2031-04-02', 'line_no' => $no, 'line_kind' => 'sale', 'order_num' => 'ТЕСТ-1',
            'customer' => 'Демо Покупатель', 'item_name' => 'Стул Демо', 'item_no' => null, 'attrs' => 'Цвет: дуб', 'attrs_short' => false, 'unit' => 'шт', 'qty' => 2,
            'price' => 1234.567891234567, 'amount' => 2469, 'driver' => 'Демо Водитель', 'note' => null, 'posted' => false, 'posted_at' => null, 'realization_id' => null,
            'restoration_id' => null, 'delivered' => false, 'delivered_at' => null, 'delivered_by' => null, 'created_by' => 'director', 'created_by_name' => 'Демо',
            'created_at' => '2031-04-02T05:00:00.123456+00:00'];
    }

    private function legacy(array $rows): void
    {
        DB::table('legacy.rows')->where('table_name', 'sales_worksheet')->delete();
        foreach ($rows as $i => $r) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'sales_worksheet', 'row_no' => $i + 1, 'data' => json_encode($r)]);
        }
    }

    public function test_lines_are_copied_and_linked_and_a_second_import_changes_nothing(): void
    {
        $order = new Order(['num' => 'ТЕСТ-1', 'channel' => 'office', 'customer' => 'Демо', 'status' => 'queue', 'payment_pct' => 0]);
        $order->save();
        $this->legacy([
            $this->line('cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdc1', 0),
            $this->line('cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdc2', 1, ['order_num' => 'НЕТ-ТАКОГО', 'item_name' => 'Стол Демо', 'qty' => 1, 'price' => 0.1, 'amount' => 0.1, 'driver' => null]),
        ]);

        $stats = app(SalesWorksheetImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([2, 2, 1, 0], [$stats['legacy_rows'], $stats['created'], $stats['notes'], $stats['posted_lines']]);
        $first = SalesWorksheetRow::where('line_no', 0)->first();
        $this->assertSame([$order->id, '1234.567891234567', 'Цвет: дуб', '2031-04-02'], [$first->order_id, $first->getRawOriginal('price'), $first->attrs, $first->doc_date->format('Y-m-d')]);
        $this->assertSame('123456', $first->created_at->format('u'));
        $this->assertNull(SalesWorksheetRow::where('line_no', 1)->value('order_id'), 'a number without an order stays as typed');
        $this->assertSame('НЕТ-ТАКОГО', SalesWorksheetRow::where('line_no', 1)->value('order_num'));

        $again = app(SalesWorksheetImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 2], [$again['created'], $again['updated'], $again['unchanged']]);

        $this->legacy([$this->line('cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdc1', 0, ['posted' => true, 'posted_at' => '2031-04-02T06:00:00+00:00'])]);
        $third = app(SalesWorksheetImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([1, 1, 1], [$third['updated'], $third['removed'], $third['posted_lines']]);
    }

    public function test_a_bad_set_is_refused_whole(): void
    {
        $this->legacy([$this->line('not-a-uuid', 0, ['doc_date' => '02.04.2031', 'amount' => 1e20])]);
        try {
            app(SalesWorksheetImporter::class)->import(self::SNAPSHOT);
            $this->fail('accepted');
        } catch (LegacyRejected $e) {
            $this->assertSame(['row 1: missing, malformed or duplicate id', 'row 1: doc_date is not YYYY-MM-DD', 'row 1: amount is not a number that fits'], $e->problems());
        }
        $this->assertSame(0, SalesWorksheetRow::count());
    }
}
