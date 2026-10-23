<?php

namespace Tests\Feature\Esf;

use App\Legacy\Importers\IncomingInvoiceImporter;
use App\Models\EsfPaymentLink;
use App\Models\IncomingInvoice;
use App\Models\User;
use App\Services\Esf\EsfLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** «Сверка с оплатами» → «Сохранить связи» and «🗑 Удалить связь» (D-10: № 52 partial payment, № 54 excesses kept; ADR-0048). */
class EsfLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->role('accountant')->create();
        $this->account = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
    }

    private function invoice(string $total, string $num): int
    {
        return IncomingInvoice::create(['num' => $num, 'date' => '2031-03-01', 'status' => 'CREATED', 'supplier_name' => 'ТОО «Демо»',
            'total_amount' => $total, 'total_nds' => '0', 'total_with_nds' => $total])->id;
    }

    private function payment(string $amount, string $key, string $flow = 'expense'): int
    {
        $id = DB::table('money_transactions')->insertGetId(['account_id' => $this->account, 'tx_date' => '2031-03-02', 'flow' => $flow, 'category' => 'supplier', 'amount' => $amount]);
        DB::table('legacy_id_map')->insert(['source_table' => 'money_tx', 'source_key' => $key, 'target_table' => 'money_transactions', 'target_id' => $id, 'snapshot_id' => 'demo']);

        return $id;
    }

    /** @return list<array{int, string, string}> */
    private function links(): array
    {
        return EsfPaymentLink::query()->orderBy('id')->get()->map(fn ($l) => [$l->invoice_id, $l->source_legacy_id, (string) $l->amount])->all();
    }

    public function test_a_short_payment_covers_part_of_an_invoice_and_never_more_than_is_left(): void
    {
        $big = $this->invoice('1000.00', '1');
        $small = $this->invoice('300.00', '2');
        $other = $this->payment('100.00', 'tx-other');
        $pay = $this->payment('900.00', 'tx-pay');
        $service = new EsfLinkService;
        $service->save([['type' => 'money_tx', 'id' => $other]], [$big], $this->user);

        // the larger invoice first: 900 of what is left of it (900), nothing left of the payment for the smaller one
        $r = $service->save([['type' => 'money_tx', 'id' => $pay]], [$small, $big], $this->user);
        $this->assertSame([[$big, 'tx-other', '100.00'], [$big, 'tx-pay', '900.00']], $this->links());
        $this->assertSame([[$small], [], 0], [$r['skipped'], $r['removed'], $r['kept']]);
        $this->assertDatabaseHas('audit_events', ['action' => 'esf_link.saved']);
    }

    public function test_a_group_of_payments_is_filled_in_order_and_an_unticked_invoice_is_unlinked(): void
    {
        $a = $this->invoice('700.00', '1');
        $b = $this->invoice('500.00', '2');
        $p1 = $this->payment('600.00', 'tx-1');
        $p2 = $this->payment('400.00', 'tx-2');
        $service = new EsfLinkService;
        $service->save([['type' => 'money_tx', 'id' => $p1], ['type' => 'money_tx', 'id' => $p2]], [$a, $b], $this->user);
        $this->assertSame([[$a, 'tx-1', '600.00'], [$a, 'tx-2', '100.00'], [$b, 'tx-2', '300.00']], $this->links());

        $r = $service->save([['type' => 'money_tx', 'id' => $p1], ['type' => 'money_tx', 'id' => $p2]], [$a], $this->user);
        $this->assertSame([[[$a, 'tx-1', '600.00'], [$a, 'tx-2', '100.00']], [$b], 2], [$this->links(), $r['removed'], $r['kept']]);
    }

    public function test_a_link_carried_over_keeps_its_amount_even_over_the_invoice(): void
    {
        $inv = $this->invoice('100.00', '1');
        $pay = $this->payment('500.00', 'tx-pay');
        EsfPaymentLink::query()->create(['invoice_id' => $inv, 'source_type' => 'money_tx', 'source_legacy_id' => 'tx-pay', 'amount' => '150.00']);
        $r = (new EsfLinkService)->save([['type' => 'money_tx', 'id' => $pay]], [$inv], $this->user);
        $this->assertSame([[[$inv, 'tx-pay', '150.00']], 1, []], [$this->links(), $r['kept'], $r['added']]);
    }

    public function test_an_advance_report_counts_what_was_issued_and_a_link_is_deleted_alone(): void
    {
        $inv = $this->invoice('800.00', '1');
        $adv = DB::table('advance_reports')->insertGetId(['employee_name' => 'Демо Сотрудник', 'report_date' => '2031-03-04', 'issued' => '500.123456', 'spent' => 0, 'returned' => 0, 'status' => 'open', 'lines' => '[]']);
        DB::table('legacy_id_map')->insert(['source_table' => 'advance_reports', 'source_key' => 'adv-1', 'target_table' => 'advance_reports', 'target_id' => $adv, 'snapshot_id' => 'demo']);
        $service = new EsfLinkService;
        $service->save([['type' => 'advance_report', 'id' => $adv]], [$inv], $this->user);
        $this->assertSame([[$inv, 'adv-1', '500.12']], $this->links());

        $service->delete(EsfPaymentLink::query()->value('id'), $this->user);
        $this->assertSame([], $this->links());
        $this->assertDatabaseHas('audit_events', ['action' => 'esf_link.deleted']);
    }

    public function test_only_expense_operations_known_to_legacy_and_existing_invoices(): void
    {
        $inv = $this->invoice('100.00', '1');
        $income = $this->payment('100.00', 'tx-in', 'income');
        $service = new EsfLinkService;
        foreach ([[[], [$inv], EsfLinkService::NOTHING], [[['type' => 'money_tx', 'id' => $income]], [$inv], 'Платёж не найден — обновите страницу.'],
            [[['type' => 'money_tx', 'id' => $this->payment('5.00', 'tx-ok')]], [$inv + 99], 'ЭСФ не найден — обновите страницу.']] as [$sources, $invoices, $message]) {
            try {
                $service->save($sources, $invoices, $this->user);
                $this->fail('refused: '.$message);
            } catch (ValidationException $e) {
                $this->assertSame($message, collect($e->errors())->flatten()->first());
            }
        }
        $this->assertSame([], $this->links());
    }

    public function test_the_import_from_legacy_stops_once_links_were_changed_here(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => 'S1', 'taken_at' => now(), 'manifest' => '{}']);
        DB::table('legacy.rows')->insert(['snapshot_id' => 'S1', 'table_name' => 'incoming_invoices', 'row_no' => 1, 'data' => json_encode(['id' => 'inv-1'])]);
        $inv = $this->invoice('100.00', '1');
        (new EsfLinkService)->save([['type' => 'money_tx', 'id' => $this->payment('100.00', 'tx-1')]], [$inv], $this->user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Связи ЭСФ с оплатами уже менялись в новой системе');
        (new IncomingInvoiceImporter)->import('S1');
    }
}
