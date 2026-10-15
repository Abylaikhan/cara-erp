<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\CustomerImporter;
use App\Legacy\Importers\LeadImporter;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** legacy:control-totals — counts and sums by period, snapshot against the imported tables (synthetic snapshot rows). */
class ControlTotalsTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        $this->out = sys_get_temp_dir().'/control-totals-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        parent::tearDown();
    }

    /** @param list<array{string, int}> $extra other tables of the snapshot manifest */
    private function snapshot(array $extra = []): void
    {
        $rows = [
            'customers' => [
                ['id' => 'u1', 'name' => 'ТОО «Синтетика»', 'type' => 'company', 'channel' => 'office', 'created_at' => '2025-03-01T10:00:00+00:00'],
                ['id' => 'u2', 'name' => 'Демо Клиент', 'type' => '', 'channel' => 'salon_armada', 'created_at' => '2025-03-02T10:00:00+00:00'],
            ],
            'leads' => [
                ['id' => 'l1', 'name' => 'Демо Лид', 'status' => 'new', 'budget' => 150000.005, 'channel' => 'office', 'created_at' => '2026-02-28T20:00:00+00:00'],
                ['id' => 'l2', 'name' => 'Демо Лид 2', 'status' => 'new', 'budget' => null, 'channel' => 'office', 'created_at' => '2026-03-01T10:00:00+00:00'],
            ],
        ];
        $manifest = ['tables' => [['table' => 'customers', 'rows' => 2], ['table' => 'leads', 'rows' => 2], ['table' => 'staff', 'rows' => 1], ['table' => 'tech_cards', 'rows' => 0],
            ...array_map(fn (array $t) => ['table' => $t[0], 'rows' => $t[1]], $extra)]];
        DB::table('legacy.snapshots')->insert(['id' => self::SNAPSHOT, 'taken_at' => now(), 'manifest' => json_encode($manifest)]);
        foreach ($rows as $table => $list) {
            foreach ($list as $i => $row) {
                DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => $table, 'row_no' => $i + 1, 'data' => json_encode($row)]);
            }
        }
        app(CustomerImporter::class)->import(self::SNAPSHOT);
        app(LeadImporter::class)->import(self::SNAPSHOT);
    }

    /** @return array<string, mixed> */
    private function module(string $name): array
    {
        return collect(json_decode(file_get_contents($this->out), true)['modules'])->firstWhere('module', $name);
    }

    public function test_imported_modules_reconcile_and_unmigrated_tables_are_explained(): void
    {
        $this->snapshot();

        $this->artisan('legacy:control-totals', ['--out' => $this->out])->assertSuccessful()
            ->expectsOutputToContain('совпадает')->doesntExpectOutputToContain('150000');

        $leads = $this->module('M10 Лиды');
        $this->assertSame(['MATCH', 2, 2], [$leads['status'], $leads['legacy_rows'], $leads['target_rows']]);
        // by month of the creation in Asia/Almaty: 2026-02-28 20:00 UTC is already March there; the budget half up to kopecks
        $this->assertSame(['2026-03' => ['2', '150000.01']], $leads['legacy']);
        $this->assertSame(['office / company' => ['1'], 'salon_armada / client' => ['1']], $this->module('M08 Покупатели')['legacy'], 'an empty type is «client»');
        $notMigrated = collect(json_decode(file_get_contents($this->out), true)['not_migrated'])->keyBy('table');
        $this->assertStringContainsString('ERP-053', $notMigrated['staff']['reason']);
        $this->assertSame('в legacy нет записей', $notMigrated['tech_cards']['reason']);
        $this->assertSame(0600, fileperms($this->out) & 0777, 'the details hold real sums: owner only');
    }

    public function test_a_changed_record_is_a_difference(): void
    {
        $this->snapshot();
        Lead::query()->where('name', 'Демо Лид 2')->update(['budget' => 1]);
        Customer::query()->where('name', 'Демо Клиент')->delete();

        $this->artisan('legacy:control-totals', ['--out' => $this->out])->assertFailed()->expectsOutputToContain('расходится');

        $this->assertSame([['period' => '2026-03', 'legacy' => ['2', '150000.01'], 'target' => ['2', '150001.01']]], $this->module('M10 Лиды')['differences']);
        $this->assertSame(['DIFFERS', 2, 1], [$this->module('M08 Покупатели')['status'], $this->module('M08 Покупатели')['legacy_rows'], $this->module('M08 Покупатели')['target_rows']]);
    }

    public function test_a_legacy_table_without_importer_or_reason_fails(): void
    {
        $this->snapshot([['mystery_table', 3]]);

        $this->artisan('legacy:control-totals')->assertFailed()->expectsOutputToContain('НЕ ОБЪЯСНЕНО');
    }
}
