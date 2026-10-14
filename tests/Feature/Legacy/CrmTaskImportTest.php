<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\CrmTaskImporter;
use App\Legacy\Importers\LeadImporter;
use App\Models\CrmTask;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy.tasks → M10 (synthetic snapshot rows); the lead is found through the imported leads. */
class CrmTaskImportTest extends TestCase
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
        $this->rows('leads', [['id' => 'lead-1', 'name' => 'Демо Лид', 'status' => 'contact', 'channel' => 'office', 'budget' => 0, 'created_at' => '2026-01-10T08:00:00+00:00']]);
        app(LeadImporter::class)->import(self::SNAPSHOT);
    }

    private function rows(string $table, array $rows): void
    {
        foreach ($rows as $i => $row) {
            if ($table === 'tasks') {
                $row += ['date' => '', 'time' => '', 'manager' => null, 'priority' => 'normal', 'status' => 'open', 'lead_id' => null, 'created_at' => '2026-02-01T08:00:00+00:00'];
            }
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => $table, 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    public function test_imports_tasks_with_their_lead_and_legacy_defaults(): void
    {
        $this->rows('tasks', [
            ['id' => 't1', 'title' => 'Позвонить', 'date' => '2026-02-03', 'time' => '10:30', 'manager' => 'Демо Менеджер', 'priority' => 'high', 'lead_id' => 'lead-1', 'created_at' => '2026-02-01T08:00:00.123456+00:00'],
            ['id' => 't2', 'title' => 'Общая', 'priority' => null, 'status' => 'done'],
            ['id' => 't3', 'title' => 'Удалённая', 'status' => 'deleted'],
        ]);
        $stats = app(CrmTaskImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([3, 3, 1, 1], [$stats['legacy_rows'], $stats['created'], $stats['open_tasks'], $stats['with_lead']]);
        $a = CrmTask::where('title', 'Позвонить')->sole();
        $this->assertSame(['2026-02-03', '10:30', 'Демо Менеджер', 'high', 'open', Lead::sole()->id],
            [$a->due_date->format('Y-m-d'), $a->timeLabel(), $a->manager, $a->priority, $a->status, $a->lead_id]);
        $this->assertSame('2026-02-01 08:00:00.123456', $a->fresh()->created_at->utc()->format('Y-m-d H:i:s.u'));
        $b = CrmTask::where('title', 'Общая')->sole();
        $this->assertSame(['normal', 'done', null, null, null], [$b->priority, $b->status, $b->due_date, $b->due_time, $b->lead_id]);
        $this->assertFalse(CrmTask::where('title', 'Удалённая')->sole()->active);

        $again = app(CrmTaskImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 3], [$again['created'], $again['updated'], $again['unchanged']]);
    }

    public function test_rejects_the_whole_set_when_a_row_is_wrong(): void
    {
        $this->rows('tasks', [
            ['id' => 't1', 'title' => 'Демо'],
            ['id' => 't2', 'title' => 'Демо', 'lead_id' => 'lead-unknown'],
            ['id' => 't3', 'title' => 'Демо', 'time' => '9 утра'],
            ['id' => 't4', 'title' => ' '],
            ['id' => 't5', 'title' => 'Демо', 'priority' => 'urgent'],
        ]);

        try {
            app(CrmTaskImporter::class)->import(self::SNAPSHOT);
            $this->fail('import must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('row 2: lead is not imported', $e->getMessage());
            $this->assertStringContainsString('row 3: time is not HH:MM', $e->getMessage());
            $this->assertStringContainsString('row 4: empty title', $e->getMessage());
            $this->assertStringContainsString('row 5: unknown priority', $e->getMessage());
        }
        $this->assertSame(0, CrmTask::count());
    }
}
