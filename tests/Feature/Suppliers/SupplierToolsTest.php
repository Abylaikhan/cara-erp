<?php

namespace Tests\Feature\Suppliers;

use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** «Поставщики»: import from incoming invoices, orphan counterparties and spellings of the supplier payments, the invoices of a card. */
class SupplierToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->role('buyer')->create();
    }

    private function supplier(string $name, ?string $bin = null): Supplier
    {
        return app(SupplierService::class)->create(['name' => $name, 'bin' => $bin, 'status' => 'active'], null, null, $this->buyer);
    }

    public function test_import_from_esf_plans_then_adds_suppliers_and_fills_bins(): void
    {
        $this->supplier('ТОО «Демо Один»');
        $this->supplier('ТОО «Демо Два»', '111111111111');
        foreach ([['ТОО «Демо Один»', '222222222222'], ['ТОО Демо Два', '111111111111'], ['ИП Демо Три', ''], ['ИП Демо Три', '333333333333'], ['', '']] as $i => [$name, $bin]) {
            DB::table('incoming_invoices')->insert(['num' => "Демо-{$i}", 'date' => '2026-10-0'.($i + 1), 'status' => 'Принят', 'supplier_name' => $name, 'supplier_bin' => $bin,
                'total_amount' => 100, 'total_nds' => 0, 'total_with_nds' => 100]);
        }
        $this->actingAs($this->buyer)->from('/suppliers')->post('/suppliers/import-esf', ['apply' => 0])
            ->assertSessionHas('dialog', ['type' => 'confirm', 'text' => "Будет добавлено новых поставщиков: 2\nБудет дополнен БИН у существующих поставщиков: 1\n\nПродолжить?"]);
        $this->assertSame(2, Supplier::count());

        $this->post('/suppliers/import-esf', ['apply' => 1])->assertSessionHas('dialog', ['type' => 'alert', 'text' => "Готово!\nДобавлено: 2\nОбновлён БИН: 1"]);
        $this->assertSame('222222222222', Supplier::where('name', 'ТОО «Демо Один»')->value('bin'));
        $this->assertSame(['ИП Демо Три', 'ИП Демо Три'], Supplier::where('comment', 'Добавлен автоматически из Входящих ЭСФ')->orderBy('id')->pluck('name')->all());
        $this->post('/suppliers/import-esf', ['apply' => 0])->assertSessionHas('dialog', ['type' => 'alert', 'text' => 'Все поставщики из ЭСФ уже есть в справочнике «Поставщики». Новых записей не найдено.']);
        $this->actingAs(User::factory()->role('manager')->create())->post('/suppliers/import-esf')->assertForbidden();
    }

    public function test_orphans_spellings_and_creating_a_card(): void
    {
        $this->supplier('ТОО «Демо»');
        $acc = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        foreach ([['ТОО «Демо»', '10'], ['ТОО "Демо"', '20'], ['ИП Сирота', '300.50'], ['ИП  Сирота', '1'], ['Не поставщику', '5']] as $i => [$ctr, $amount]) {
            DB::table('money_transactions')->insert(['account_id' => $acc, 'tx_date' => '2026-10-0'.($i + 1), 'flow' => 'expense', 'category' => $i === 4 ? 'rent' : 'supplier', 'amount' => $amount, 'counterparty' => $ctr]);
        }
        // Groups of equal size keep the order the newest-first journal meets them, as legacy (a stable sort).
        $data = $this->actingAs($this->buyer)->getJson('/suppliers/counterparties')->assertOk()->json();
        $this->assertSame([['name' => 'ИП  Сирота', 'count' => 2, 'sum' => '301.50', 'lastDate' => '2026-10-04']], $data['orphans']);
        $this->assertSame(['ИП  Сирота', 'ТОО «Демо»'], array_map(fn ($g) => $g['canonical'] ?? $g['variants'][0]['text'], $data['spellings']));

        $this->from('/suppliers')->post('/suppliers/orphans', ['name' => 'ИП  Сирота'])->assertSessionHasNoErrors()->assertSessionMissing('dialog');
        $this->post('/suppliers/orphans', ['name' => 'ИП Сирота'])->assertSessionHas('dialog', ['type' => 'alert', 'text' => 'Поставщик «ИП  Сирота» уже есть в справочнике — отдельную карточку создавать не нужно.']);
        $this->assertSame([], $this->getJson('/suppliers/counterparties')->json('orphans'));
    }

    public function test_card_lists_the_invoices_by_bin_or_by_name(): void
    {
        $byBin = $this->supplier('ТОО Демо', '444444444444');
        $byName = $this->supplier('ИП Демо Второй');
        DB::table('incoming_invoices')->insert([
            ['num' => 'Б-1', 'date' => '2026-10-01', 'status' => 'Принят', 'supplier_name' => 'Другое имя', 'supplier_bin' => '444444444444', 'total_amount' => 1, 'total_nds' => 0, 'total_with_nds' => '150.50'],
            ['num' => 'Н-1', 'date' => '2026-10-02', 'status' => 'Принят', 'supplier_name' => 'ип демо второй (филиал)', 'supplier_bin' => null, 'total_amount' => 1, 'total_nds' => 0, 'total_with_nds' => '20'],
        ]);
        $this->actingAs($this->buyer)->getJson("/suppliers/{$byBin->id}/esf")->assertExactJson([['num' => 'Б-1', 'date' => '2026-10-01', 'total' => '150.50', 'status' => 'Принят']]);
        $this->getJson("/suppliers/{$byName->id}/esf")->assertJsonPath('0.num', 'Н-1');
    }
}
