<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\MoneyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** legacy money → M12: accounts from the page code (without the account number), operations, order payments, advances. */
class MoneyImportTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    // Synthetic fragment shaped like legacy bankAccounts; the account number must not be read.
    private const HTML = <<<'JS'
        <script>
        let bankAccounts = [
          {id:'acc-cash', name:'Касса Демо',  bank:'',            iin:'', bik:'', balance:0, currency:'KZT', type:'cash', icon:'💵'},
          {id:'acc-bank', name:'Банк Демо',   bank:'АО «Демо»',   iin:'KZ000DEMO', bik:'DEMOKZKA', balance:0, openingBalance:1500.5, currency:'KZT', type:'bank', icon:'🏦'},
        ];
        </script>
        JS;

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
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => $table, 'row_no' => $i + 1, 'data' => json_encode($row, JSON_PRESERVE_ZERO_FRACTION)]);
        }
    }

    private function legacyData(): void
    {
        $code = 'demo_item';
        DB::table('expense_categories')->insert(['code' => $code, 'name' => 'Демо статья']);
        $tx = ['subcategory' => null, 'amount_fx' => null, 'fx_currency' => null, 'description' => '', 'counterparty' => 'Демо', 'bin' => null, 'order_num' => null, 'created_at' => '2026-10-01T05:00:00.123456+00:00'];
        $this->rows('money_tx', [
            ['id' => 'b-tx', 'account_id' => 'acc-cash', 'date' => '2026-10-01', 'flow' => 'income', 'category' => 'order_payment', 'amount' => 1000.25, 'order_num' => 'МО-1'] + $tx,
            ['id' => 'a-tx', 'account_id' => 'acc-cash', 'date' => '2026-10-01', 'flow' => 'expense', 'category' => 'general', 'amount' => 10] + $tx,
            ['id' => 'c-tx', 'account_id' => 'acc-bank', 'date' => '2026-10-02', 'flow' => 'expense', 'category' => 'supplier', 'subcategory' => $code, 'amount' => 300, 'amount_fx' => 0.6, 'fx_currency' => 'USD'] + $tx,
        ]);
        $this->rows('order_payments', [['id' => 1, 'tx_id' => 'b-tx', 'order_num' => 'МО-2', 'amount' => 500, 'created_at' => '2026-10-01T05:00:00+00:00']]);
        $this->rows('advance_reports', [['id' => 'adv-1', 'doc_num' => 'АО-1', 'emp_name' => 'Демо Сотрудник', 'date' => '2026-10-01', 'purpose' => 'Демо', 'issued' => 1000.1234567, 'spent' => 400,
            'returned' => 0, 'status' => 'open', 'account_id' => 'acc-cash', 'tx_ids' => null, 'tx_id' => 'a-tx', 'lines' => [['amount' => 400, 'desc' => 'Демо']], 'created_at' => '2026-10-01T05:00:00+00:00'],
            ['id' => 'adv-2', 'doc_num' => 'АО-2', 'emp_name' => 'Демо Второй', 'date' => '2026-10-02', 'purpose' => null, 'issued' => 10, 'spent' => 0, 'returned' => 0,
                'status' => 'closed', 'account_id' => 'acc-cash', 'tx_ids' => null, 'tx_id' => null, 'lines' => null, 'created_at' => '2026-10-02T05:00:00+00:00']]);
    }

    public function test_imports_accounts_operations_payments_and_advances(): void
    {
        $this->legacyData();
        $stats = (new MoneyImporter(self::HTML))->import(self::SNAPSHOT);

        $this->assertSame([2, 3, 1, 2, 8], [$stats['accounts'], $stats['operations'], $stats['order_payments'], $stats['advance_reports'], $stats['created']]);
        $bank = DB::table('money_accounts')->where('name', 'Банк Демо')->first();
        $this->assertSame(['АО «Демо»', 'DEMOKZKA', '1500.50', 'bank'], [$bank->bank, $bank->bik, $bank->opening_balance, $bank->type]);
        $this->assertStringNotContainsString('KZ000DEMO', json_encode(DB::table('money_accounts')->get()), 'the account number is not stored');
        // Inside a day the operations keep the legacy order (id ascending): a-tx before b-tx.
        $this->assertSame(['c-tx', 'a-tx', 'b-tx'], DB::table('money_transactions')->join('legacy_id_map', fn ($j) => $j->on('legacy_id_map.target_id', '=', 'money_transactions.id')->where('source_table', 'money_tx'))
            ->orderByDesc('tx_date')->orderBy('money_transactions.id')->pluck('source_key')->all());
        $c = DB::table('money_transactions')->where('category', 'supplier')->first();
        $this->assertSame(['300.00', '0.60', 'USD'], [$c->amount, $c->amount_fx, $c->fx_currency]);
        $this->assertNotNull($c->expense_category_id);
        $this->assertSame('2026-10-01 05:00:00.123456+00', DB::table('money_transactions')->where('category', 'general')->value('created_at'));
        $this->assertSame('1000.123456700000', DB::table('advance_reports')->value('issued'));
        $this->assertSame('[]', DB::table('advance_reports')->where('doc_num', 'АО-2')->value('lines'), 'a missing list is an empty one');

        $again = (new MoneyImporter(self::HTML))->import(self::SNAPSHOT);
        $this->assertSame([0, 0, 8], [$again['created'], $again['updated'], $again['unchanged']]);
    }

    public function test_rejects_the_whole_set_when_a_row_is_wrong(): void
    {
        $this->rows('money_tx', [
            ['id' => 'x', 'account_id' => 'acc-unknown', 'date' => '01.10.2026', 'flow' => 'transfer', 'category' => 'x', 'amount' => -5, 'subcategory' => 'NO-SUCH', 'created_at' => '2026-10-01T05:00:00+00:00'],
        ]);
        $this->rows('order_payments', [['id' => 1, 'tx_id' => 'missing', 'order_num' => 'МО-1', 'amount' => 1, 'created_at' => '2026-10-01T05:00:00+00:00']]);

        try {
            (new MoneyImporter(self::HTML))->import(self::SNAPSHOT);
            $this->fail('import must be rejected');
        } catch (RuntimeException $e) {
            foreach (['unknown account', 'unknown flow', 'date is not YYYY-MM-DD', 'amount_text is not a non-negative amount', 'unknown expense item', 'order payment 1: operation not found'] as $problem) {
                $this->assertStringContainsString($problem, $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('money_transactions')->count());
    }
}
