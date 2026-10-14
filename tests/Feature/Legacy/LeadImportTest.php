<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\LeadImporter;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.leads → M10 (synthetic snapshot rows). */
class LeadImportTest extends TestCase
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

    /** Rows go in as JSON text, so numbers keep the exact form legacy stored. */
    private function rows(array $rows): void
    {
        foreach ($rows as $i => $row) {
            $row += ['phone' => '', 'status' => 'new', 'interest' => '', 'budget' => 0, 'source' => null, 'comment' => '', 'channel' => 'office', 'manager' => null, 'created_at' => '2025-05-01T08:00:00+00:00'];
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'leads', 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    public function test_imports_every_row_with_legacy_defaults_and_dates(): void
    {
        $this->rows([
            ['id' => 'l1', 'name' => 'Демо Лид', 'phone' => '+7 700 000 0001', 'status' => 'contact', 'budget' => 150000.5, 'source' => 'call', 'manager' => 'Демо Менеджер', 'created_at' => '2026-03-01T10:00:00.123456+00:00'],
            ['id' => 'l2', 'name' => 'ТОО «Демо»', 'status' => null, 'channel' => null, 'budget' => null],
            ['id' => 'l3', 'name' => 'Демо Салон', 'status' => 'won', 'channel' => 'salon_armada', 'comment' => 'Демо'],
        ]);
        $stats = app(LeadImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([3, 3, 2, 2], [$stats['legacy_rows'], $stats['created'], $stats['active_leads'], $stats['channels']]);
        $a = Lead::where('name', 'Демо Лид')->sole();
        $this->assertSame(['+7 700 000 0001', 'contact', '150000.50', 'call', 'Демо Менеджер', null, 'office', null, [], null],
            [$a->phone, $a->status, $a->budget, $a->source, $a->manager, $a->interest, $a->channel, $a->segment, $a->tags, $a->next_date]);
        $this->assertSame('2026-03-01 10:00:00.123456', $a->fresh()->created_at->utc()->format('Y-m-d H:i:s.u'), 'microseconds are kept');
        $b = Lead::where('name', 'ТОО «Демо»')->sole();
        $this->assertSame(['new', 'office', '0.00', null], [$b->status, $b->channel, $b->budget, $b->phone]);
        $this->assertSame(['won', 'salon_armada', 'Демо'], [Lead::where('name', 'Демо Салон')->sole()->status, Lead::where('name', 'Демо Салон')->sole()->channel, Lead::where('name', 'Демо Салон')->sole()->comment]);
        $this->assertSame(3, DB::table('legacy_id_map')->where('source_table', 'leads')->count());
    }

    public function test_import_is_idempotent_and_takes_changes(): void
    {
        $this->rows([['id' => 'l1', 'name' => 'Демо Лид'], ['id' => 'l2', 'name' => 'Демо Второй']]);
        app(LeadImporter::class)->import(self::SNAPSHOT);
        $again = app(LeadImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 2], [$again['created'], $again['updated'], $again['unchanged']]);

        DB::table('legacy.rows')->where('table_name', 'leads')->where('row_no', 1)->update(['data' => DB::raw("jsonb_set(data, '{status}', '\"lost\"')")]);
        $changed = app(LeadImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 1, 1], [$changed['created'], $changed['updated'], $changed['unchanged']]);
        $this->assertSame(['lost', 2], [Lead::where('name', 'Демо Лид')->sole()->status, Lead::where('name', 'Демо Лид')->sole()->lock_version]);
        $this->assertSame(2, Lead::count());
    }

    public function test_rejects_the_whole_set_when_a_row_is_wrong(): void
    {
        $this->rows([
            ['id' => 'l1', 'name' => 'Демо Лид'],
            ['id' => 'l2', 'name' => 'Демо', 'status' => 'archived'],
            ['id' => 'l3', 'name' => 'Демо', 'channel' => 'salon_unknown'],
            ['id' => 'l4', 'name' => 'Демо', 'budget' => -5],
            ['id' => 'l5', 'name' => ' '],
        ]);

        try {
            app(LeadImporter::class)->import(self::SNAPSHOT);
            $this->fail('import must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('row 2: unknown status', $e->getMessage());
            $this->assertStringContainsString('row 3: unknown channel', $e->getMessage());
            $this->assertStringContainsString('row 4: budget is not a non-negative number', $e->getMessage());
            $this->assertStringContainsString('row 5: empty name', $e->getMessage());
        }
        $this->assertSame(0, Lead::count());
    }
}
