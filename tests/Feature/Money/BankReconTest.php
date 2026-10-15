<?php

namespace Tests\Feature\Money;

use App\Legacy\Importers\BankStatementImporter;
use App\Models\BankStatementRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** «Деньги» → «Сверка с банком»: statements and the journal by day load with the tab; clearing keeps transferred rows; the import. */
class BankReconTest extends TestCase
{
    use RefreshDatabase;

    private int $kaspi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kaspi = DB::table('money_accounts')->insertGetId(['name' => 'Kaspi Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        DB::table('legacy_id_map')->insert(['source_table' => 'money_accounts', 'source_key' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'target_table' => 'money_accounts',
            'target_id' => $this->kaspi, 'snapshot_id' => '20260101T000000Z']);
    }

    public function test_statements_and_the_journal_load_with_the_tab_and_clearing_keeps_transferred_rows(): void
    {
        DB::table('money_transactions')->insert(['account_id' => $this->kaspi, 'tx_date' => '2026-10-01', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '100.50']);
        foreach ([['2026-10-01', 'income', '100.50', false], ['2026-10-01', 'expense', '20', true], ['2026-10-02', 'expense', '5', false]] as $i => [$d, $flow, $amount, $done]) {
            BankStatementRow::query()->forceCreate(['uuid' => sprintf('00000000-0000-4000-8000-%012d', $i), 'account_id' => $this->kaspi, 'bank_format' => 'kaspi', 'row_date' => $d,
                'flow' => $flow, 'amount' => $amount, 'counterparty' => 'Демо', 'transferred_to_db' => $done]);
        }
        $accountant = User::factory()->role('accountant')->create();
        $this->actingAs($accountant)->get('/money')->assertInertia(fn (Assert $page) => $page
            ->missing('bankRecon')
            ->reloadOnly('bankRecon', fn (Assert $reload) => $reload
                ->where('bankRecon.accounts', [$this->kaspi])
                ->where('bankRecon.rows', fn ($r) => count($r) === 3)
                ->where('bankRecon.journal', [['account' => $this->kaspi, 'date' => '2026-10-01', 'in' => '100.50', 'out' => '0']])));

        $this->from('/money')->post('/money/bank-recon/clear', ['account' => $this->kaspi])->assertRedirect('/money')->assertSessionHasNoErrors();
        $this->assertSame([true], BankStatementRow::query()->pluck('transferred_to_db')->all());
        $this->actingAs(User::factory()->role('manager')->create())->post('/money/bank-recon/clear', ['account' => $this->kaspi])->assertForbidden();
    }

    public function test_legacy_statements_are_imported_and_rows_cleared_in_legacy_are_removed(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => '20260101T000000Z', 'taken_at' => now(), 'manifest' => '{}']);
        foreach (['0b0e7c1a-1111-4222-8333-944455556661', '0b0e7c1a-1111-4222-8333-944455556662'] as $i => $id) {
            DB::table('legacy.rows')->insert(['snapshot_id' => '20260101T000000Z', 'table_name' => 'bank_statement_raw', 'row_no' => $i + 1, 'data' => json_encode([
                'id' => $id, 'created_at' => '2026-10-01T05:00:00.123456+00:00', 'account_id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'bank_format' => 'kaspi', 'date' => '2026-10-01',
                'flow' => 'expense', 'amount' => 1234.56, 'counterparty' => 'ТОО Демо', 'bin' => '000000000000', 'description' => 'Демо', 'knp' => '710', 'doc_num' => (string) $i,
                'transferred_to_db' => false, 'transferred_tx_id' => null, 'transferred_at' => null])]);
        }
        $stats = app(BankStatementImporter::class)->import('20260101T000000Z');
        $this->assertSame([2, '1234.56'], [$stats['created'], BankStatementRow::query()->value('amount')]);

        DB::table('legacy.rows')->where('row_no', 2)->delete();
        $again = app(BankStatementImporter::class)->import('20260101T000000Z');
        $this->assertSame([0, 1, 1], [$again['created'], $again['unchanged'], $again['removed']]);
        $this->assertSame(1, BankStatementRow::query()->count());
    }
}
