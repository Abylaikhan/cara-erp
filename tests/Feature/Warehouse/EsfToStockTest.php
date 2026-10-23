<?php

namespace Tests\Feature\Warehouse;

use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceItem;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** «Входящие ЭСФ» → «📦 Принять» / «📦 На склад»: a posted receipt from the ESF line, one line taken in once (№ 45, ADR-0049). */
class EsfToStockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IncomingInvoice $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->role('director')->create(['name' => 'Демо Директор']);
        $this->inv = IncomingInvoice::create(['num' => '77', 'date' => '2031-03-01', 'status' => 'DELIVERED', 'supplier_name' => 'ТОО «Демо Плита»',
            'total_amount' => '0', 'total_nds' => '0', 'total_with_nds' => '0']);
    }

    private function line(string $description, string $qty = '10', string $price = '850.5'): IncomingInvoiceItem
    {
        return IncomingInvoiceItem::query()->create(['invoice_id' => $this->inv->id, 'line_no' => IncomingInvoiceItem::query()->count() + 1, 'description' => $description,
            'qty' => $qty, 'unit_price' => $price, 'amount' => '0', 'nds_rate' => '12', 'nds_amount' => '0', 'total' => '0']);
    }

    public function test_a_line_is_taken_into_its_material_once_and_the_material_moves_to_the_chosen_warehouse(): void
    {
        $m = new Material(['name' => 'ЛДСП Демо 16мм', 'category' => 'ЛДСП/МДФ', 'unit' => 'лист', 'price' => '8500', 'min_qty' => 0, 'warehouse_code' => 'wh_main']);
        $m->forceFill(['qty' => '2.5'])->save();
        $line = $this->line('ЛДСП Демо 16мм');
        $this->actingAs($this->user)->from('/esf');

        $this->get("/esf/{$this->inv->id}/detail")->assertJsonPath('items.0.match.id', $m->id)->assertJsonPath('items.0.received', null);
        $this->post("/esf/items/{$line->id}/to-warehouse", ['material_id' => $m->id, 'warehouse' => 'wh_plates'])->assertRedirect('/esf')->assertSessionHasNoErrors();

        $m->refresh();
        $this->assertSame(['12.500', 'wh_plates'], [$m->qty, $m->warehouse_code]);
        $doc = DB::table('stock_documents')->sole();
        $this->assertSame(['ПН-001', 'posted', 'ТОО «Демо Плита»', 'ЭСФ №77'], [$doc->num, $doc->status, $doc->supplier_name, $doc->esf_ref]);
        $this->assertSame([['10.000', '850.50', '8505.00']], DB::table('stock_document_lines')->get()->map(fn ($l) => [$l->qty, $l->price, $l->amount])->all());
        $this->get("/esf/{$this->inv->id}/detail")->assertJsonPath('items.0.received', 'ПН-001');

        $this->post("/esf/items/{$line->id}/to-warehouse", ['material_id' => $m->id, 'warehouse' => 'wh_plates'])->assertSessionHasErrors(['item' => 'Строка ЭСФ уже принята на склад документом ПН-001']);
        $this->assertSame('12.500', $m->fresh()->qty);
    }

    public function test_a_new_material_is_created_with_the_line_as_its_alias(): void
    {
        $line = $this->line('Плита демо белая 2800×2070');
        $this->actingAs($this->user)->from('/esf');
        $this->post("/esf/items/{$line->id}/to-warehouse", ['warehouse' => 'wh_plates', 'name' => ' ', 'unit' => 'лист'])->assertSessionHasErrors(['name' => 'Укажите название материала']);
        $this->post("/esf/items/{$line->id}/to-warehouse", ['warehouse' => 'wh_plates', 'name' => 'ЛДСП Демо белая', 'unit' => 'лист'])->assertSessionHasNoErrors();

        $m = Material::query()->where('name', 'ЛДСП Демо белая')->sole();
        $this->assertSame(['10.000', 'лист', '850.50', 'wh_plates', 'ТОО «Демо Плита»'], [$m->qty, $m->unit, (string) $m->price, $m->warehouse_code, $m->supplier_name]);
        $this->assertTrue(MaterialAlias::query()->where('material_name', 'ЛДСП Демо белая')->where('alias_name', 'Плита демо белая 2800×2070')->exists());
        $this->assertSame(1, DB::table('stock_movements')->count());

        $other = $this->line('Плита демо серая');
        $this->post("/esf/items/{$other->id}/to-warehouse", ['warehouse' => 'wh_plates', 'name' => 'лдсп демо БЕЛАЯ'])->assertSessionHasErrors(['name' => 'Материал «лдсп демо БЕЛАЯ» уже есть на складе']);
    }

    public function test_a_correcting_line_and_an_unknown_warehouse_are_refused_and_the_page_decides_who_may(): void
    {
        $neg = $this->line('Возврат демо', '-1');
        $this->actingAs($this->user)->from('/esf');
        $this->post("/esf/items/{$neg->id}/to-warehouse", ['warehouse' => 'wh_plates', 'name' => 'X'])->assertSessionHasErrors('item');
        $ok = $this->line('Плита демо');
        $this->post("/esf/items/{$ok->id}/to-warehouse", ['warehouse' => 'wh_finished', 'name' => 'X'])->assertSessionHasErrors(['warehouse' => 'Склад не найден — обновите страницу.']);
        $this->actingAs(User::factory()->role('salon_armada')->create())->post("/esf/items/{$ok->id}/to-warehouse", ['warehouse' => 'wh_plates', 'name' => 'X'])->assertForbidden();
        $this->assertSame(0, DB::table('stock_movements')->count());
    }
}
