<?php

namespace Tests\Feature\Legacy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ТЗ MIG-02, AC-75: the import has a dry run, an error journal and a report of the references it cannot resolve or
 * resolve uniquely (synthetic snapshot rows).
 */
class MigrationToolsTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => self::SNAPSHOT, 'taken_at' => now(), 'manifest' => '{}']);
        $this->file = sys_get_temp_dir().'/migration-tools-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $rows */
    private function rows(string $table, array $rows): void
    {
        foreach ($rows as $i => $row) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => $table, 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    public function test_a_dry_run_imports_verifies_and_writes_nothing(): void
    {
        $this->rows('expense_categories', [
            ['id' => 'a1', 'code' => 'office', 'name' => 'Офис', 'parent_code' => null, 'is_fixed' => false, 'sort_order' => 10, 'active' => true],
            ['id' => 'a2', 'code' => 'office_rent', 'name' => 'Аренда офиса', 'parent_code' => 'office', 'is_fixed' => true, 'sort_order' => 20, 'active' => true],
        ]);
        $count = fn () => [DB::table('expense_categories')->count(), DB::table('legacy_id_map')->count(), DB::table('audit_events')->count()];

        $this->artisan('legacy:import', ['entity' => 'expense_categories', '--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain('DRY RUN expense_categories: imported and verified')
            ->expectsOutputToContain('nothing is written');
        $this->assertSame([0, 0, 0], $count(), 'the dry run left nothing');

        $this->artisan('legacy:import', ['entity' => 'expense_categories'])->assertSuccessful();
        $this->assertSame(2, DB::table('expense_categories')->count());
    }

    public function test_a_refused_set_keeps_every_problem_in_the_error_journal(): void
    {
        $this->rows('expense_categories', array_map(fn (int $i) => ['id' => "b{$i}", 'code' => "demo_{$i}", 'name' => "Демо {$i}", 'parent_code' => "missing_{$i}",
            'is_fixed' => false, 'sort_order' => $i, 'active' => true], range(1, 12)));

        $this->artisan('legacy:import', ['entity' => 'expense_categories', '--errors' => $this->file])->assertFailed()
            ->expectsOutputToContain('rejected, nothing imported')
            ->expectsOutputToContain('12 problem(s) written to');

        $this->assertCount(12, file($this->file, FILE_IGNORE_NEW_LINES), 'the console names ten, the journal all');
        $this->assertSame(0600, fileperms($this->file) & 0777);
        $this->assertSame(0, DB::table('expense_categories')->count());
    }

    public function test_the_references_report_names_what_the_import_cannot_resolve_or_resolve_uniquely(): void
    {
        $this->rows('orders', [['id' => 1, 'num' => 'МО-1', 'customer' => 'Демо клиент']]);
        $this->rows('order_items', [['id' => 1, 'order_num' => 'МО-1'], ['id' => 2, 'order_num' => 'МО-9']]);
        $this->rows('realizations', [['id' => 1, 'order_num' => 'МО-1'], ['id' => 2, 'order_num' => 'МО-404'], ['id' => 3, 'order_num' => '']]);
        $this->rows('customers', [['id' => 1, 'name' => 'Демо клиент'], ['id' => 2, 'name' => 'Демо клиент']]);
        $this->rows('suppliers', [['id' => 1, 'name' => 'ТОО «Демо 1»', 'bin' => '000000000071'], ['id' => 2, 'name' => 'ТОО «Демо 2»', 'bin' => '000000000071']]);
        $this->rows('incoming_invoices', [['id' => 'v1', 'supplier_bin' => '000000000071'], ['id' => 'v2', 'supplier_bin' => '000000000099']]);

        $this->artisan('legacy:references', ['--out' => $this->file])->assertFailed()
            ->expectsOutputToContain('The import will refuse: Строка заказа → заказ');

        $report = collect(json_decode((string) file_get_contents($this->file), true)['references'])->keyBy('name');
        $this->assertSame([1, 1, 0, [['id' => '2', 'ref' => 'МО-404']]], [$report['Реализация → заказ']['resolved'], $report['Реализация → заказ']['unresolved'],
            $report['Реализация → заказ']['ambiguous'], $report['Реализация → заказ']['unresolved_keys']], 'an empty number is not a reference');
        $this->assertSame([0, 1, 1], [$report['ЭСФ → поставщик по БИН']['resolved'], $report['ЭСФ → поставщик по БИН']['unresolved'], $report['ЭСФ → поставщик по БИН']['ambiguous']]);
        $this->assertSame([['id' => 'v1', 'ref' => '000000000071', 'matches' => 2]], $report['ЭСФ → поставщик по БИН']['ambiguous_keys']);
        $this->assertSame(1, $report['Заказ → покупатель по названию']['ambiguous']);
        $this->assertSame(0600, fileperms($this->file) & 0777);

        DB::table('legacy.rows')->where(['table_name' => 'order_items', 'row_no' => 2])->delete();
        $this->artisan('legacy:references')->assertSuccessful()->expectsOutputToContain('the import will not refuse');
    }
}
