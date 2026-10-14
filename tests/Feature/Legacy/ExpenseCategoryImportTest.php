<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\ExpenseCategoryImporter;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/** legacy.expense_categories → M14 directory (synthetic snapshot rows, no real data in tests). */
class ExpenseCategoryImportTest extends TestCase
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

    /** @param list<array<string, mixed>> $rows */
    private function legacyRows(array $rows): void
    {
        foreach ($rows as $i => $row) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'expense_categories', 'row_no' => $i + 1, 'data' => json_encode($row)]);
        }
    }

    private function realDirectory(): void
    {
        $this->legacyRows([
            ['id' => 'a1', 'code' => 'office', 'name' => 'Офис', 'parent_code' => null, 'is_fixed' => false, 'sort_order' => 10, 'active' => true],
            ['id' => 'a2', 'code' => 'office_rent', 'name' => 'Аренда офиса', 'parent_code' => 'office', 'is_fixed' => true, 'sort_order' => 20, 'active' => true],
            ['id' => 'a3', 'code' => 'office_old', 'name' => 'Старая статья', 'parent_code' => 'office', 'is_fixed' => false, 'sort_order' => 0, 'active' => false],
            ['id' => 'a4', 'code' => 'shop', 'name' => 'Цех', 'parent_code' => null, 'is_fixed' => false, 'sort_order' => 20, 'active' => true],
        ]);
    }

    public function test_real_directory_replaces_the_synthetic_one(): void
    {
        $synthetic = ExpenseCategory::create(['code' => 'demo_top', 'name' => 'Демо', 'sort_order' => 1]);
        ExpenseCategory::create(['code' => 'demo_child', 'name' => 'Демо статья', 'parent_id' => $synthetic->id]);
        ExpenseCategory::create(['code' => 'office', 'name' => 'Синтетическое имя', 'sort_order' => 99]);
        $this->realDirectory();

        $stats = app(ExpenseCategoryImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([4, 3, 1, 2], [$stats['legacy_rows'], $stats['created'], $stats['updated'], $stats['synthetic_removed']]);
        $this->assertSame(['office', 'office_old', 'office_rent', 'shop'], ExpenseCategory::orderBy('code')->pluck('code')->all());
        $this->assertSame('Офис', ExpenseCategory::where('code', 'office')->value('name'));
        $old = ExpenseCategory::where('code', 'office_old')->first();
        $this->assertFalse($old->active);
        $this->assertSame(0, $old->sort_order);
        $this->assertSame('office', ExpenseCategory::where('code', 'office_rent')->first()->parent->code);
        $this->assertSame(4, DB::table('legacy_id_map')->where('source_table', 'expense_categories')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'legacy.imported', 'subject_type' => 'expense_categories']);

        $director = User::factory()->role('director')->create();
        $this->actingAs($director)->get('/expense_cats')->assertInertia(fn (Assert $page) => $page->has('categories', 3));
    }

    public function test_import_is_idempotent(): void
    {
        $this->realDirectory();
        app(ExpenseCategoryImporter::class)->import(self::SNAPSHOT);
        $stats = app(ExpenseCategoryImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([0, 0, 4], [$stats['created'], $stats['updated'], $stats['unchanged']]);
        $this->assertSame(4, ExpenseCategory::count());
        $this->assertSame(4, DB::table('legacy_id_map')->count());
    }

    public function test_invalid_legacy_data_changes_nothing(): void
    {
        ExpenseCategory::create(['code' => 'demo_top', 'name' => 'Демо']);
        $this->legacyRows([
            ['id' => 'a1', 'code' => 'office', 'name' => 'Офис', 'parent_code' => null],
            ['id' => 'a2', 'code' => 'lost', 'name' => 'Потерянная', 'parent_code' => 'missing_parent'],
        ]);

        try {
            app(ExpenseCategoryImporter::class)->import(self::SNAPSHOT);
            $this->fail('invalid data must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('parent missing_parent of lost is missing', $e->getMessage());
        }
        $this->assertSame(['demo_top'], ExpenseCategory::pluck('code')->all());
        $this->assertSame(0, DB::table('legacy_id_map')->count());
    }

    public function test_seeder_keeps_the_imported_directory(): void
    {
        $this->realDirectory();
        app(ExpenseCategoryImporter::class)->import(self::SNAPSHOT);
        putenv('DEV_ADMIN_PASSWORD=seed-test-password');
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, ExpenseCategory::count(), 'synthetic demo categories are not added back');
    }

    public function test_only_migrated_modules_can_be_imported(): void
    {
        $this->artisan('legacy:import money_tx')->assertExitCode(2)->expectsOutputToContain('not migrated yet');
    }
}
