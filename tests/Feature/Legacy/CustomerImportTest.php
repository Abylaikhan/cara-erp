<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\CustomerImporter;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.customers → M08 (synthetic snapshot rows). */
class CustomerImportTest extends TestCase
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
            $row += ['type' => 'client', 'phone' => '', 'email' => '', 'address' => '', 'inn' => '', 'contact' => null, 'comment' => '', 'channel' => 'office', 'turnover' => 0, 'created_at' => '2025-05-01T08:00:00+00:00'];
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'customers', 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    private function legacyCustomers(): void
    {
        $this->rows([
            ['id' => 'u1', 'name' => 'ТОО «Синтетика»', 'type' => 'company', 'phone' => '+7 700 000 0001', 'email' => 'demo@example.test', 'created_at' => '2025-03-01T10:00:00.123456+00:00'],
            ['id' => 'u2', 'name' => 'Демо Клиент', 'channel' => 'salon_armada', 'address' => 'Демо-адрес', 'comment' => 'Демо-комментарий'],
            ['id' => 'u3', 'name' => 'Демо Клиент', 'channel' => 'salon_nazarbayeva', 'email' => null],
            ['id' => 'u4', 'name' => 'ИП Демо', 'type' => 'Юр. лицо', 'inn' => '000000000001', 'channel' => 'office'],
        ]);
    }

    public function test_imports_every_row_keeping_fields_channels_and_dates(): void
    {
        $this->legacyCustomers();
        $stats = app(CustomerImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([4, 4, 4, 2, 3, 1], [$stats['legacy_rows'], $stats['created'], $stats['customers'], $stats['office'], $stats['channels'], $stats['duplicate_names']]);
        $a = Customer::where('name', 'ТОО «Синтетика»')->sole();
        $this->assertSame(['company', '+7 700 000 0001', 'demo@example.test', null, null, 'office'], [$a->type, $a->phone, $a->email, $a->address, $a->inn, $a->channel]);
        $this->assertSame('2025-03-01 10:00:00.123456', $a->fresh()->created_at->utc()->format('Y-m-d H:i:s.u'), 'microseconds are kept');
        $this->assertSame(['salon_armada', 'salon_nazarbayeva'], Customer::where('name', 'Демо Клиент')->orderBy('channel')->pluck('channel')->all(), 'duplicates by name stay separate');
        $this->assertSame('000000000001', Customer::where('name', 'ИП Демо')->sole()->inn);
        $this->assertSame(4, DB::table('legacy_id_map')->where('source_table', 'customers')->count());
    }

    public function test_import_is_idempotent(): void
    {
        $this->legacyCustomers();
        app(CustomerImporter::class)->import(self::SNAPSHOT);
        $stats = app(CustomerImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 4], [$stats['created'], $stats['updated'], $stats['unchanged']]);
        $this->assertSame(4, Customer::count());
    }

    public function test_rows_the_new_schema_cannot_keep_change_nothing(): void
    {
        foreach ([['channel' => 'salon_unknown'], ['inn' => '12345'], ['turnover' => 150000], ['contact' => 'Демо Контакт'], ['type' => 'partner']] as $i => $bad) {
            DB::table('legacy.rows')->where('table_name', 'customers')->delete();
            $this->rows([['id' => 'ok', 'name' => 'Нормальный'], ['id' => "bad{$i}", 'name' => 'Ошибочный'] + $bad]);
            try {
                app(CustomerImporter::class)->import(self::SNAPSHOT);
                $this->fail('Import must refuse: '.json_encode($bad, JSON_UNESCAPED_UNICODE));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('nothing imported', $e->getMessage());
            }
            $this->assertSame(0, Customer::count());
        }
    }
}
