<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\AppUserImporter;
use App\Legacy\LegacyRejected;
use App\Models\User;
use App\Support\Erp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** D-13: the accounts of the built-in roles of legacy with app_users laid over them (synthetic rows); no password is read. */
class AppUserImportTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    // Synthetic fragment shaped like legacy `var _USERS` (two lines per role); passwords live elsewhere and are not read.
    private const PAGE = <<<'JS'
        <script>
        var _USERS={
          director:   {name:'Демо Директор Легаси', initials:'ДЛ', color:'#4f8ef7', label:'Директор',
            pages:['dashboard']},
          manager:    {name:'Демо Продажи Легаси', initials:'ПЛ', color:'#3dd68c', label:'Бухгалтер (продажи)',
            pages:['dashboard']},
        };
        var _PWD={director:'не-читается'};
        </script>
        JS;

    private function importer(): AppUserImporter
    {
        return new AppUserImporter(self::PAGE);
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => self::SNAPSHOT, 'taken_at' => now(), 'manifest' => '{}']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function legacy(array $rows): void
    {
        DB::table('legacy.rows')->where('table_name', 'app_users')->delete();
        foreach ($rows as $i => $r) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => 'app_users', 'row_no' => $i + 1, 'data' => json_encode($r)]);
        }
    }

    public function test_the_built_in_accounts_are_made_and_a_record_is_laid_over_its_role(): void
    {
        $existing = User::factory()->role('manager')->create(['login' => 'manager', 'password' => 'Секрет-Демо-1']);
        $this->legacy([
            ['id' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'login' => ' Accountant ', 'name' => 'Демо Главбух', 'label' => 'Гл. бухгалтер (демо)', 'initials' => 'ДГ',
                'color' => '#123456', 'pages' => ['dashboard', 'warehouse', 'techcards'], 'active' => true, 'password' => 'не-читается', 'role' => null],
            ['id' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb', 'login' => 'director', 'name' => 'Демо Шеф', 'pages' => ['dashboard'], 'active' => false],
            ['id' => 'cccccccc-3333-4333-8333-cccccccccccc', 'login' => 'buyer', 'active' => false, 'pages' => []],
        ]);

        $stats = $this->importer()->import(self::SNAPSHOT);
        $this->assertSame([3, count(config('erp.roles')), 3], [$stats['legacy_rows'], $stats['accounts'], $stats['overlaid']]);

        $acc = User::query()->where('login', 'accountant')->sole();
        $this->assertSame(['Демо Главбух', 'Гл. бухгалтер (демо)', 'ДГ', '#123456', ['dashboard', 'warehouse', 'techcards'], true, 'accountant'],
            [$acc->name, $acc->role_label, $acc->initials, $acc->color, $acc->pages, $acc->is_active, $acc->role]);
        // the pages of the record replace those of the role; a page that does not exist is left out
        $this->assertSame(['dashboard', 'warehouse'], Erp::pagesFor($acc));
        $this->assertSame(['name' => 'Демо Главбух', 'initials' => 'ДГ', 'color' => '#123456', 'roleLabel' => 'Гл. бухгалтер (демо)'], $acc->sidebarCard());
        $this->assertFalse(Hash::check('не-читается', $acc->password), 'a legacy password is never taken');

        // the director can be neither blocked nor deprived of the pages of the role; its record's name wins over the built-in
        $director = User::query()->where('login', 'director')->sole();
        $this->assertSame([true, 'Демо Шеф', 'ДЛ'], [$director->is_active, $director->name, $director->initials]);
        $this->assertSame(Erp::pagesFor(User::factory()->role('director')->make()), Erp::pagesFor($director));
        // a blocked record blocks its account; an empty page list keeps the role's
        $buyer = User::query()->where('login', 'buyer')->sole();
        $this->assertSame([false, null, []], [$buyer->is_active, $buyer->pages, Erp::pagesFor($buyer)]);
        // an account that existed keeps its password; a role without a record keeps its role's pages and label
        $this->assertTrue(Hash::check('Секрет-Демо-1', $existing->fresh()->password));
        $this->assertSame([null, null, 'Демо Продажи Легаси', 'ПЛ'], [$existing->fresh()->pages, $existing->fresh()->role_label, $existing->fresh()->name, $existing->fresh()->initials]);
        // a role legacy names nobody for gets its login as the name, as legacy does
        $this->assertSame('hr', User::query()->where('login', 'hr')->value('name'));
        $this->assertSame(3, DB::table('legacy_id_map')->where('source_table', 'app_users')->count());

        $again = $this->importer()->import(self::SNAPSHOT);
        $this->assertSame([0, 0], [$again['created'], $again['updated']]);
    }

    public function test_a_record_of_an_unknown_login_is_refused_whole(): void
    {
        $this->legacy([['id' => 'dddddddd-4444-4444-8444-dddddddddddd', 'login' => 'новый_логин', 'pages' => 'не список']]);
        try {
            $this->importer()->import(self::SNAPSHOT);
            $this->fail('accepted');
        } catch (LegacyRejected $e) {
            $this->assertSame(['row 1: the login is not a built-in role — a new account needs its role decided (D-13)', 'row 1: pages are not a list'], $e->problems());
        }
        $this->assertSame(0, User::query()->count());
    }
}
