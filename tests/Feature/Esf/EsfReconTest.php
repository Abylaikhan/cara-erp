<?php

namespace Tests\Feature\Esf;

use App\Models\IncomingInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M05 «Входящие ЭСФ» → «Сверка с оплатами» (legacy initEsfRecon): the payments, advance reports and links, view only. */
class EsfReconTest extends TestCase
{
    use RefreshDatabase;

    private function map(string $table, string $key, int $id): void
    {
        DB::table('legacy_id_map')->insert(['source_table' => $table, 'source_key' => $key, 'target_table' => $table === 'money_tx' ? 'money_transactions' : $table,
            'target_id' => $id, 'snapshot_id' => '20260101T000000Z']);
    }

    private function partial()
    {
        $version = $this->get('/esf')->viewData('page')['version'];

        return $this->get('/esf', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Esf', 'X-Inertia-Partial-Data' => 'recon']);
    }

    public function test_the_tab_gets_the_expense_payments_the_advance_reports_and_the_links_by_their_ids_here(): void
    {
        $inv = IncomingInvoice::create(['num' => '7', 'date' => '2031-03-01', 'status' => 'CREATED', 'supplier_name' => 'ТОО «Демо»',
            'total_amount' => '100.00', 'total_nds' => '12.00', 'total_with_nds' => '112.00']);
        $this->map('incoming_invoices', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', $inv->id);
        $acc = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        $tx = DB::table('money_transactions')->insertGetId(['account_id' => $acc, 'tx_date' => '2031-03-02', 'flow' => 'expense', 'category' => 'supplier', 'amount' => '112.50', 'counterparty' => 'ТОО Демо']);
        DB::table('money_transactions')->insert(['account_id' => $acc, 'tx_date' => '2031-03-03', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '10']);
        $this->map('money_tx', 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb', $tx);
        $adv = DB::table('advance_reports')->insertGetId(['employee_name' => 'Демо Сотрудник', 'report_date' => '2031-03-04', 'issued' => '500', 'spent' => 0, 'returned' => 0,
            'status' => 'closed', 'lines' => json_encode([['name' => 'Демо', 'esfInvoiceId' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'], ['name' => 'Без ЭСФ']])]);
        $this->map('advance_reports', 'cccccccc-3333-4333-8333-cccccccccccc', $adv);
        DB::table('esf_payment_links')->insert([
            ['invoice_id' => $inv->id, 'source_type' => 'money_tx', 'source_legacy_id' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb', 'amount' => '60.25', 'created_at' => now()],
            ['invoice_id' => $inv->id, 'source_type' => 'advance_report', 'source_legacy_id' => 'cccccccc-3333-4333-8333-cccccccccccc', 'amount' => '40', 'created_at' => now()],
            ['invoice_id' => $inv->id, 'source_type' => 'advance_report', 'source_legacy_id' => 'dddddddd-4444-4444-8444-dddddddddddd', 'amount' => '5', 'created_at' => now()],
        ]);
        $this->actingAs(User::factory()->role('director')->create());

        $this->get('/esf')->assertInertia(fn (Assert $page) => $page->missing('recon'));
        $recon = $this->partial()->assertOk()->json('props.recon');

        $this->assertSame([['id' => $tx, 'date' => '2031-03-02', 'counterparty' => 'ТОО Демо', 'amount' => 112.5]], $recon['payments'], 'expense operations only');
        $this->assertSame([['id' => $adv, 'date' => '2031-03-04', 'emp_name' => 'Демо Сотрудник', 'issued' => 500, 'status' => 'closed', 'lineInvoices' => [$inv->id]]],
            array_map(fn ($a) => [...$a, 'issued' => (int) $a['issued']], $recon['advances']));
        // the links name their source by its id here; a source that is not in the data stays without one
        $this->assertSame([
            ['invoice' => $inv->id, 'type' => 'money_tx', 'source' => $tx, 'amount' => 60.25],
            ['invoice' => $inv->id, 'type' => 'advance_report', 'source' => $adv, 'amount' => 40],
            ['invoice' => $inv->id, 'type' => 'advance_report', 'source' => null, 'amount' => 5],
        ], array_map(fn ($l) => [...array_diff_key($l, ['id' => 0]), 'amount' => $l['amount'] == (int) $l['amount'] ? (int) $l['amount'] : $l['amount']], $recon['links']));
    }

    public function test_only_roles_with_the_page_get_it(): void
    {
        $this->actingAs(User::factory()->role('salon_armada')->create())->get('/esf')->assertForbidden();
    }
}
