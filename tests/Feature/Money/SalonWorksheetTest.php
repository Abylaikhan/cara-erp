<?php

namespace Tests\Feature\Money;

use App\Legacy\Importers\SalonWorksheetImporter;
use App\Models\AuditEvent;
use App\Models\SalonWorksheetRow;
use App\Models\User;
use App\Services\SalonWorksheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** «Деньги» → «Рабочий лист салонов»: rows load with the tab, cells are saved, transferred rows are locked; the legacy import. */
class SalonWorksheetTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountant = User::factory()->role('accountant')->create();
    }

    public function test_rows_load_with_the_tab_and_cells_are_saved_like_legacy(): void
    {
        $this->actingAs($this->accountant)->from('/money')->post('/money/salon-worksheet', ['salon' => 'armada'])->assertSessionHasNoErrors();
        $row = SalonWorksheetRow::query()->sole();
        $this->assertSame(now(config('erp.business_timezone'))->format('Y-m-d'), $row->order_date->format('Y-m-d'));

        $this->put("/money/salon-worksheet/{$row->id}", ['field' => 'cash_amount', 'value' => '100.50'])->assertSessionHasNoErrors();
        $this->put("/money/salon-worksheet/{$row->id}", ['field' => 'expense_amount', 'value' => 'абв'])->assertSessionHasNoErrors(); // parseFloat||0
        $this->put("/money/salon-worksheet/{$row->id}", ['field' => 'note', 'value' => ' Демо "в кавычках" '])->assertSessionHasNoErrors();
        $this->put("/money/salon-worksheet/{$row->id}", ['field' => 'payment_date', 'value' => '2026-02-30'])->assertSessionHasErrors('value');
        $this->put("/money/salon-worksheet/{$row->id}", ['field' => 'salon', 'value' => 'nazarbayeva'])->assertSessionHasErrors('field');
        $row->refresh();
        $this->assertSame(['100.500000000000', '0.000000000000', 'Демо "в кавычках"', 'armada'], [$row->getRawOriginal('cash_amount'), $row->getRawOriginal('expense_amount'), $row->note, $row->salon]);
        $this->assertSame(4, AuditEvent::query()->where('subject_type', 'salon_worksheet_row')->count());

        $this->get('/money')->assertInertia(fn (Assert $page) => $page
            ->missing('salonRows')
            ->reloadOnly('salonRows', fn (Assert $reload) => $reload
                ->where('salonRows.0.cash_amount', '100.500000000000')
                ->where('salonRows.0.note', 'Демо "в кавычках"')
                ->where('salonRows.0.transferred', false)));
    }

    public function test_transferred_rows_are_locked_and_clearing_keeps_them(): void
    {
        $service = app(SalonWorksheetService::class);
        $open = $service->add('nazarbayeva', $this->accountant);
        $done = $service->add('nazarbayeva', $this->accountant);
        $done->forceFill(['transferred_to_db' => true, 'transferred_at' => now()])->save();
        $service->add('armada', $this->accountant);

        $this->actingAs($this->accountant)->from('/money');
        $this->put("/money/salon-worksheet/{$done->id}", ['field' => 'note', 'value' => 'Демо'])->assertSessionHasErrors(['row' => SalonWorksheetService::TRANSFERRED]);
        $this->delete("/money/salon-worksheet/{$done->id}")->assertSessionHasErrors(['row' => SalonWorksheetService::TRANSFERRED]);
        $this->post('/money/salon-worksheet/clear', ['salon' => 'nazarbayeva'])->assertSessionHasNoErrors();
        $this->assertSame([$done->id], SalonWorksheetRow::query()->where('salon', 'nazarbayeva')->pluck('id')->all());
        $this->assertSame(1, SalonWorksheetRow::query()->where('salon', 'armada')->count());
        $this->assertNull(SalonWorksheetRow::find($open->id));
        $this->actingAs(User::factory()->role('manager')->create())->post('/money/salon-worksheet', ['salon' => 'armada'])->assertForbidden();
    }

    public function test_legacy_rows_are_imported_exactly_and_idempotently(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => '20260101T000000Z', 'taken_at' => now(), 'manifest' => '{}']);
        DB::table('legacy.rows')->insert(['snapshot_id' => '20260101T000000Z', 'table_name' => 'salon_worksheet', 'row_no' => 1, 'data' => '{"id": "0b0e7c1a-1111-4222-8333-944455556666", "salon": "armada",
            "created_at": "2026-02-01T08:00:00.123456+00:00", "order_date": "2026-01-31", "order_num": "МО-1", "manager": "Демо", "balance_before": 1500000.75, "payment_date": null,
            "cash_amount": 0.1, "card_amount": null, "balance_after": 12.345678, "note": " Демо ", "delivery_date": null, "cod_amount": null, "expense_amount": null,
            "transferred_to_office": 300, "recipient_desc": null, "transferred_to_db": true, "transferred_at": "2026-02-02T08:00:00+00:00", "transferred_tx_ids": ["a", "b"]}']);

        $stats = app(SalonWorksheetImporter::class)->import('20260101T000000Z');
        $row = SalonWorksheetRow::query()->sole();
        $this->assertSame([1, 1, '1500000.750000000000', '0.100000000000', '12.345678000000', ' Демо ', true, ['a', 'b']],
            [$stats['created'], $stats['transferred'], $row->getRawOriginal('balance_before'), $row->getRawOriginal('cash_amount'), $row->getRawOriginal('balance_after'), $row->note, $row->transferred_to_db, $row->transferred_tx_ids]);
        $this->assertSame([0, 1], [app(SalonWorksheetImporter::class)->import('20260101T000000Z')['created'], app(SalonWorksheetImporter::class)->import('20260101T000000Z')['unchanged']]);
    }
}
