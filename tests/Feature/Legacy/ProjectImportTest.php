<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\ProjectImporter;
use App\Models\Project;
use App\Models\ProjectCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy projects, project_calcs, project_comments → M10 «Проекты» (synthetic snapshot rows). */
class ProjectImportTest extends TestCase
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

    private function rows(string $table, array $rows): void
    {
        foreach ($rows as $i => $row) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => $table, 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    public function test_an_empty_legacy_set_is_imported_as_nothing(): void
    {
        $stats = app(ProjectImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([0, 0, 0], [$stats['projects'], $stats['calcs'], $stats['comments']]);
    }

    public function test_imports_projects_with_calculations_and_messages_idempotently(): void
    {
        $this->rows('projects', [['id' => 7, 'num' => 'ПР-0007', 'title' => 'Демо кухня', 'channel' => 'dealer_astana', 'status' => 'sent', 'customer_name' => 'Демо',
            'description' => 'Размеры', 'due_date' => '2026-03-01', 'responsible' => 'manager, director', 'responsible_name' => 'Демо, Демо', 'order_num' => null,
            'total_amount' => 2546.75, 'created_by' => 'dealer_astana', 'created_by_name' => 'Демо Дилер', 'created_at' => '2026-02-01T08:00:00.123456+00:00', 'updated_at' => '2026-02-02T08:00:00+00:00']]);
        $this->rows('project_calcs', [['id' => 1, 'project_id' => 7, 'version' => 1, 'items' => [['name' => 'Корпус', 'attrs' => '', 'qty' => 2.5, 'cost' => 800.1, 'price' => 1000.1], ['name' => 'Фасад', 'qty' => 3, 'cost' => '', 'price' => 15.5]],
            'total' => 2546.75, 'markup_pct' => 30, 'sent_at' => '2026-02-02T08:00:00+00:00', 'created_by' => 'manager', 'created_by_name' => 'Демо', 'created_at' => '2026-02-01T09:00:00+00:00']]);
        $this->rows('project_comments', [['id' => 3, 'project_id' => 7, 'author' => 'manager', 'author_name' => 'Демо', 'side' => 'staff', 'text' => 'Привет', 'internal' => true, 'created_at' => '2026-02-01T10:00:00+00:00']]);

        $stats = app(ProjectImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([1, 1, 1, 3], [$stats['projects'], $stats['calcs'], $stats['comments'], $stats['created']]);
        $project = Project::query()->sole();
        $this->assertSame(['ПР-0007', 'sent', ['manager', 'director'], '2546.75', '2026-03-01'], [$project->num, $project->status, $project->executors, $project->total_amount, $project->due_date->format('Y-m-d')]);
        $this->assertSame('2026-02-01 08:00:00.123456', $project->created_at->utc()->format('Y-m-d H:i:s.u'));
        $calc = ProjectCalc::query()->sole();
        $this->assertSame(['2546.75', '2.500', '0.00', '15.50'], [$calc->total, $calc->items[0]['qty'], $calc->items[1]['cost'], $calc->items[1]['price']]);
        $this->assertTrue($project->comments()->sole()->internal);

        $again = app(ProjectImporter::class)->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 3], [$again['created'], $again['updated'], $again['unchanged']]);
    }

    public function test_files_and_bad_rows_are_refused_before_anything_is_written(): void
    {
        $this->rows('projects', [['id' => 1, 'title' => '', 'status' => 'odd', 'channel' => 'nowhere', 'created_at' => '2026-02-01T08:00:00+00:00']]);
        try {
            app(ProjectImporter::class)->import(self::SNAPSHOT);
            $this->fail('expected rejection');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty title', $e->getMessage());
            $this->assertStringContainsString('unknown status', $e->getMessage());
            $this->assertStringContainsString('unknown channel', $e->getMessage());
        }
        $this->rows('project_files', [['id' => 1, 'project_id' => 1, 'file_name' => 'a.png']]);
        $this->expectExceptionMessage('file contents (Supabase Storage) are not copied yet');
        app(ProjectImporter::class)->import(self::SNAPSHOT);
        $this->assertSame(0, Project::count());
    }
}
