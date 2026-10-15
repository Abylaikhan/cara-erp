<?php

namespace Tests\Feature\Reliability;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ERP-072, AC-74 «1001 и 5001 запись во всех существенных реестрах»: legacy read most tables with one request, which
 * Supabase cuts at 1000 rows (suppliers, advance reports, expense items, aliases, projects…) or with limit=5000
 * (realizations D-16, project calculations). Here every registry of a migrated page, including the lists sent when a tab
 * is opened, carries all 5001 rows, and the sums the server counts take all of them. 5001 = 5 × 1000 + 1 also catches a
 * lost last chunk of 1000. Each answer must come within BUDGET seconds (pages answer in tenths of a second): a slower one
 * has a step that grows with the square of the rows — the supplier form's expense items had one (36 s).
 */
class LargeRegistriesTest extends TestCase
{
    use RefreshDatabase;

    private const N = 5001;

    private const BUDGET = 5.0;

    /** @param callable(int): array<string, mixed> $row */
    private function bulk(string $table, callable $row, int $n = self::N): void
    {
        foreach (array_chunk(range(1, $n), 1000) as $chunk) {
            DB::table($table)->insert(array_map($row, $chunk));
        }
        $this->analyze($table);
    }

    /**
     * Planner statistics as the server keeps them (autovacuum): the rows of this test's transaction are invisible to it, and
     * a table it believes empty can get a nested loop of 5001 × 5001 rows (seen: 7 s). ANALYZE inside the transaction sees them.
     */
    private function analyze(string $table): void
    {
        DB::statement('ANALYZE '.DB::getQueryGrammar()->wrapTable($table));
    }

    /** Props of a page as the browser gets them; $only — a tab's list loaded on demand (partial reload). */
    private function props(User $user, string $url, ?string $only = null, ?string $component = null): array
    {
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
        if ($only !== null) {
            $headers += ['X-Inertia-Partial-Data' => $only, 'X-Inertia-Partial-Component' => $component];
        }

        $started = microtime(true);
        $props = $this->actingAs($user)->get($url, $headers)->assertOk()->json('props');
        $seconds = microtime(true) - $started;
        $this->assertLessThan(self::BUDGET, $seconds, sprintf('%s %s took %.1f s with %d rows', $url, $only ?? '', $seconds, self::N));

        return $props;
    }

    private function orders(string $channel, string $prefix): array
    {
        $this->bulk('orders', fn (int $i) => ['num' => "{$prefix}-{$i}", 'channel' => $channel, 'customer' => 'Демо клиент', 'end_client' => "Демо покупатель {$i}",
            'status' => $i % 2 ? 'queue' : 'progress',
            'payment_pct' => 0, 'order_date' => '2026-10-01', 'due_date' => '2026-10-20', 'created_at' => '2026-10-01 10:00:00+05']);
        $ids = DB::table('orders')->where('num', 'like', "{$prefix}-%")->orderBy('id')->pluck('id')->all();
        foreach (array_chunk($ids, 1000) as $chunk) {
            DB::table('order_items')->insert(array_map(fn (int $id) => ['order_id' => $id, 'line_no' => 1, 'name' => 'Демо изделие', 'qty' => '2', 'price' => '1500.50',
                'status' => 'queue', 'progress' => 0, 'attrs' => '{}'], $chunk));
        }
        $this->analyze('order_items');

        return $ids;
    }

    public function test_reference_registries_carry_every_row(): void
    {
        $this->bulk('products', fn (int $i) => ['name' => "Демо товар {$i}", 'category' => 'Демо', 'price_retail' => $i, 'price_wholesale' => $i, 'unit' => 'шт']);
        $this->bulk('customers', fn (int $i) => ['name' => "Демо клиент {$i}", 'type' => 'client', 'channel' => 'office']);
        $this->bulk('suppliers', fn (int $i) => ['name' => "Демо поставщик {$i}"]);
        $this->bulk('materials', fn (int $i) => ['name' => "Демо материал {$i}", 'category' => 'Демо', 'unit' => 'шт', 'qty' => $i % 7, 'price' => '10.50']);
        $this->bulk('material_aliases', fn (int $i) => ['material_name' => "Демо материал {$i}", 'alias_name' => "Демо синоним {$i}"]);
        $this->bulk('expense_categories', fn (int $i) => ['code' => "demo_{$i}", 'name' => "Демо статья {$i}"]);
        $this->bulk('material_requests', fn (int $i) => ['material_name' => "Демо материал {$i}", 'qty' => 1, 'status' => 'requested']);
        $this->bulk('incoming_invoices', fn (int $i) => ['num' => (string) $i, 'date' => '2026-10-01', 'status' => 'DELIVERED', 'supplier_name' => 'Демо',
            'total_amount' => '100.00', 'total_nds' => '12.00', 'total_with_nds' => '112.00']);
        $director = User::factory()->role('director')->create();

        $this->assertCount(self::N, $this->props($director, '/catalog')['products']);
        $this->assertCount(self::N, $this->props($director, '/customers')['persons']);
        $this->assertCount(self::N, $this->props($director, '/suppliers')['suppliers'], 'legacy read the suppliers with one request: 1000');
        $warehouse = $this->props($director, '/warehouse');
        $this->assertSame([self::N, self::N, self::N], [count($warehouse['materials']), count($warehouse['aliases']), count($warehouse['suppliers'])]);
        $this->assertSame(0, bccomp($warehouse['stockValue'], (string) DB::table('materials')->sum(DB::raw('qty * price')), 4), 'the stock value takes every material');
        $this->assertCount(self::N, $this->props($director, '/expense_cats')['categories'], 'legacy: one request, 1000');
        $this->assertCount(self::N, $this->props($director, '/purchases')['requests']);
        $this->assertCount(self::N, $this->props($director, '/esf')['invoices']);
        $this->assertCount(self::N, $this->props($director, '/finance', 'purchases', 'Finance')['purchases']['invoices']);
        $dashboard = $this->props($director, '/dashboard');
        $this->assertSame([self::N, bcmul((string) self::N, '112', 2)], [$dashboard['stockCount'], $dashboard['supplierDebt']]);
    }

    public function test_sales_production_and_crm_registries_carry_every_row(): void
    {
        $this->orders('office', 'МО');
        $items = DB::table('order_items')->orderBy('id')->pluck('id')->all();
        $this->bulk('production_progress', fn (int $i) => ['order_item_id' => $items[$i - 1], 'stages' => '[]', 'materials' => '[]', 'completed' => false]);
        $this->bulk('realizations', fn (int $i) => ['order_num' => "МО-{$i}", 'doc_date' => '2026-10-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT),
            'name' => 'Демо изделие', 'qty' => 1, 'revenue' => "{$i}.25"]);
        $this->bulk('leads', fn (int $i) => ['name' => "Демо лид {$i}", 'channel' => 'office', 'status' => 'new', 'budget' => 0]);
        $this->bulk('crm_tasks', fn (int $i) => ['title' => "Демо задача {$i}", 'status' => 'open', 'priority' => 'normal']);
        $this->bulk('projects', fn (int $i) => ['num' => "ПР-{$i}", 'title' => "Демо проект {$i}", 'channel' => 'office', 'status' => 'request']);
        $director = User::factory()->role('director')->create();

        $this->assertCount(self::N, $this->props($director, '/sales')['orders']);
        $this->assertCount(self::N, $this->props($director, '/sales', 'realizations', 'Sales')['realizations'], 'legacy: limit=5000 (D-16)');
        $this->assertCount(self::N, $this->props($director, '/sales', 'report', 'Sales')['report']['lines']);
        $this->assertCount(self::N, $this->props($director, '/crm', 'boardOrders', 'Crm')['boardOrders']);
        $this->assertCount(self::N, $this->props($director, '/money', 'calendar', 'Money')['calendar']['orders'], 'unpaid orders of the payment calendar');
        $this->assertCount(self::N, $this->props($director, '/mrp')['positions']);
        $crm = $this->props($director, '/crm');
        $this->assertSame([self::N, self::N], [count($crm['leads']), count($crm['tasks'])]);
        $this->assertCount(self::N, $this->props($director, '/crm', 'projects', 'Crm')['projects'], 'legacy: one request, 1000');
        $this->assertCount(self::N, $this->props($director, '/finance')['orders']);
        $revenue = $this->props($director, '/dashboard')['revenue'];
        $this->assertSame(0, bccomp((string) $revenue['2026-10'], (string) DB::table('realizations')->sum('revenue'), 2), 'revenue of the month takes every realization');
    }

    public function test_money_registries_and_their_tabs_carry_every_row(): void
    {
        $cash = DB::table('money_accounts')->insertGetId(['name' => 'Касса Демо', 'type' => 'cash', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        DB::table('legacy_id_map')->insert(['source_table' => 'money_accounts', 'source_key' => 'c854b1e4-c0f7-436b-a274-5e8a59c4ef78', 'target_table' => 'money_accounts',
            'target_id' => $cash, 'snapshot_id' => '20260101T000000Z']); // the cash of the salon «Армада» (SalonCashController)
        $this->bulk('money_transactions', fn (int $i) => ['account_id' => $cash, 'tx_date' => '2026-10-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT),
            'flow' => $i % 3 ? 'income' : 'expense', 'category' => $i % 3 ? 'order_payment' : 'other', 'amount' => "{$i}.10"]);
        $this->bulk('advance_reports', fn (int $i) => ['employee_name' => "Демо сотрудник {$i}", 'report_date' => '2026-10-01', 'issued' => 100, 'spent' => 40, 'status' => 'open']);
        // the bank tab shows the statements of the Kaspi and Halyk accounts (legacy BANK_RECON_ACCOUNTS, MoneyController::bankRecon)
        $kaspi = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 1]);
        DB::table('legacy_id_map')->insert(['source_table' => 'money_accounts', 'source_key' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'target_table' => 'money_accounts',
            'target_id' => $kaspi, 'snapshot_id' => '20260101T000000Z']);
        $this->bulk('bank_statement_rows', fn (int $i) => ['uuid' => sprintf('00000000-0000-4000-8000-%012d', $i), 'account_id' => $kaspi, 'bank_format' => 'kaspi',
            'row_date' => '2026-10-01', 'flow' => 'income', 'amount' => 1]);
        $this->bulk('salon_worksheet_rows', fn (int $i) => ['uuid' => sprintf('00000000-0000-4000-9000-%012d', $i), 'salon' => 'armada', 'order_date' => '2026-10-01']);
        $director = User::factory()->role('director')->create();

        $money = $this->props($director, '/money');
        $this->assertCount(self::N, $money['transactions']);
        $this->assertSame(round((float) DB::table('money_transactions')->sum('amount'), 2), round(array_sum(array_column($money['transactions'], 'amount')), 2));
        $this->assertSame(0, bccomp((string) $money['advanceDebt'], bcmul((string) self::N, '60', 2), 2), 'the advance debt takes every report');
        $this->assertCount(self::N, $this->props($director, '/money', 'advances', 'Money')['advances'], 'legacy: one request, 1000');
        $this->assertCount(self::N, $this->props($director, '/money', 'salonRows', 'Money')['salonRows']);
        $this->assertCount(self::N, $this->props($director, '/finance', 'transactions', 'Finance')['transactions']);
        $dashboard = $this->props($director, '/dashboard');
        $in = (string) DB::table('money_transactions')->where('flow', 'income')->sum('amount');
        $this->assertSame(0, bccomp((string) $dashboard['cashIn']['2026-10'], $in, 2), 'the month income takes every operation');
        $this->assertCount(self::N, $this->props(User::factory()->role('salon_armada')->create(), '/money_salon')['transactions']);
        $this->assertCount(self::N, $this->props($director, '/money', 'bankRecon', 'Money')['bankRecon']['rows']);
    }

    public function test_channel_registries_of_salons_and_dealers_carry_every_row(): void
    {
        $this->orders('salon_armada', 'СА');
        $this->orders('dealer_astana', 'ДА');

        $salonManager = User::factory()->role('salon_armada_mgr')->create();
        $this->assertCount(self::N, $this->props($salonManager, '/salon_report')['orders']);
        $this->assertCount(self::N, $this->props($salonManager, '/crm', 'clientOrders', 'Crm')['clientOrders'], 'the clients of the salons');
        $this->assertCount(self::N, $this->props(User::factory()->role('dealer_astana')->create(), '/reconcile')['orders']);
        $this->assertCount(self::N, $this->props(User::factory()->role('director')->create(), '/customers')['dealerOrders']);
    }
}
