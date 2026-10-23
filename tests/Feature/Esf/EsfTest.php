<?php

namespace Tests\Feature\Esf;

use App\Models\AuditEvent;
use App\Models\EsfPaymentLink;
use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceItem;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Esf\EsfXmlParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M05 «Входящие ЭСФ»: XML parsing with the legacy rules, preview with duplicates, atomic import, list, type, detail, rights. */
class EsfTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->director = User::factory()->role('director')->create(['name' => 'Демо Директор']);
    }

    private function xml(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/esf/two-invoices.xml'));
    }

    private function invoice(array $attrs = []): IncomingInvoice
    {
        return IncomingInvoice::create(array_merge(['num' => '1', 'date' => '2026-03-01', 'status' => 'CREATED', 'supplier_name' => 'ТОО «Демо»',
            'total_amount' => '100.00', 'total_nds' => '12.00', 'total_with_nds' => '112.00'], $attrs));
    }

    private function preview(string ...$xml): TestResponse
    {
        $files = array_map(fn (string $x, int $i) => UploadedFile::fake()->createWithContent("esf-{$i}.xml", $x), $xml, array_keys($xml));

        return $this->actingAs($this->director)->from('/esf')->post('/esf/import/preview', ['files' => $files]);
    }

    public function test_parser_follows_legacy_rules(): void
    {
        [$a, $b] = (new EsfXmlParser)->parse($this->xml(), 'two.xml');

        $this->assertSame(['two.xml (#1)', '2026-02-28', '101', 'ESF-000000000071-DEMO-0001', 'DELIVERED', 'ТОО «Демо Плита»', '000000000071', true],
            [$a['fileName'], $a['date'], $a['num'], $a['regNum'], $a['status'], $a['supplier_name'], $a['supplier_bin'], $a['ok']], 'turnover date, seller (not consignor), short legal form');
        $this->assertCount(2, $a['items'], 'the empty product is skipped');
        $this->assertSame(['8500.5000000000', '3000.0000000000'], [$a['items'][0]['unit_price'], $a['items'][1]['amount']], '«8 500,50»; turnoverSize when there is no priceWithoutTax');
        $this->assertSame(['98565.6000000000', '10560.6000000000'], [$a['total_with_nds'], $a['total_nds']]);
        $this->assertSame(['CREATED', 'ИП Демо', '2026-03-05'], [$b['status'], $b['supplier_name'], $b['date']]);

        $this->assertSame('Не удалось разобрать XML — файл повреждён или это не XML', (new EsfXmlParser)->parse('это не xml', 'x.xml')[0]['error']);
        $this->assertSame('Не найден блок invoiceInfo — формат файла не распознан', (new EsfXmlParser)->parse('<a/>', 'x.xml')[0]['error']);
        $this->assertSame('Пустой invoiceBody', (new EsfXmlParser)->parse('<a><invoiceInfo><invoiceBody/></invoiceInfo></a>', 'x.xml')[0]['error']);
        $entity = '<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a><invoiceInfo><registrationNumber>&x;</registrationNumber><invoiceBody>&lt;i/&gt;</invoiceBody></invoiceInfo></a>';
        $this->assertFalse((new EsfXmlParser)->parse($entity, 'xxe.xml')[0]['ok'], 'external entities are not loaded');
    }

    public function test_preview_marks_duplicates_and_import_stores_invoice_lines_and_supplier(): void
    {
        $this->invoice(['reg_num' => 'ESF-000000000072-DEMO-0002', 'supplier_name' => 'ИП Демо']);
        $known = Supplier::create(['name' => 'ТОО «Демо Плита»']);

        $this->preview($this->xml())->assertRedirect('/esf')->assertSessionHas('esfPreview', function (array $p) {
            return $p['total'] === 2 && $p['ok'] === 2 && $p['rows'][0]['duplicate'] === null && $p['rows'][1]['duplicate'] === 'совпал рег.номер'
                && $p['rows'][0]['lines'] === 2 && $p['rows'][0]['total_with_nds'] === 98565.6;
        });
        $token = session('esfPreview')['token'];

        $this->post('/esf/import', ['token' => $token, 'selected' => [0]])->assertSessionHas('dialog', ['type' => 'alert', 'text' => 'Импортировано: 1']);
        $inv = IncomingInvoice::where('reg_num', 'ESF-000000000071-DEMO-0001')->sole();
        $this->assertSame(['2026-02-28', '101', 'DELIVERED', 'general', '98565.60', '10560.60', $known->id, 'ТОО «Демо Плита»', '000000000071', 'esf-0.xml (#1)'],
            [$inv->date->format('Y-m-d'), $inv->num, $inv->status, $inv->invoice_type, $inv->total_with_nds, $inv->total_nds, $inv->supplier_id, $inv->supplier_name, $inv->supplier_bin, $inv->source_file]);
        $this->assertSame(['ЛДСП Демо 16мм', 'Доставка демо'], $inv->items->pluck('description')->all());
        $this->assertSame('000000000071', $known->fresh()->bin, 'the known supplier gets the BIN from the ESF');
        $this->assertDatabaseHas('audit_events', ['action' => 'esf.imported', 'subject_id' => (string) $inv->id]);

        $this->post('/esf/import', ['token' => $token, 'selected' => [0]])->assertSessionHasErrors(['token' => 'Предпросмотр устарел — выберите файлы ещё раз.']);
    }

    public function test_import_creates_an_unknown_supplier_and_refuses_a_second_copy(): void
    {
        $this->preview($this->xml());
        $token = session('esfPreview')['token'];
        $this->post('/esf/import', ['token' => $token, 'selected' => [0, 1]])->assertSessionHas('dialog', ['type' => 'alert', 'text' => 'Импортировано: 2']);
        $this->assertSame(['ИП Демо', 'ТОО «Демо Плита»'], Supplier::orderBy('name')->pluck('name')->all());
        $this->assertSame('000000000072', Supplier::where('name', 'ИП Демо')->value('bin'));

        // The same file again: the preview unticks both, and forcing them in is refused by the registration number.
        $this->preview($this->xml())->assertSessionHas('esfPreview', fn (array $p) => $p['rows'][0]['duplicate'] === 'совпал рег.номер' && $p['rows'][1]['duplicate'] === 'совпал рег.номер');
        $this->post('/esf/import', ['token' => session('esfPreview')['token'], 'selected' => [1]])
            ->assertSessionHas('dialog', fn (array $d) => str_starts_with($d['text'], "Импортировано: 0, ошибок: 1\n\nesf-0.xml (#2): ЭСФ с рег.номером ESF-000000000072-DEMO-0002 уже есть в базе"));
        $this->assertSame(2, IncomingInvoice::count());
        $this->assertSame(3, IncomingInvoiceItem::count());
        $this->assertSame(2, Supplier::count());
    }

    public function test_duplicate_by_fields_when_there_is_no_registration_number(): void
    {
        $this->invoice(['reg_num' => null, 'date' => '2026-03-05', 'num' => '7', 'supplier_name' => 'ИП «Демо»', 'total_with_nds' => '28000.80']);
        $this->preview($this->xml())->assertSessionHas('esfPreview', fn (array $p) => $p['rows'][1]['duplicate'] === 'совпали дата + номер + поставщик + сумма');
    }

    public function test_page_lists_invoices_with_linked_amounts_type_change_and_detail_with_material_match(): void
    {
        $old = $this->invoice(['date' => '2026-01-10', 'total_with_nds' => '1000.00']);
        $new = $this->invoice(['date' => '2026-02-10', 'num' => '2', 'total_with_nds' => '500.00']);
        EsfPaymentLink::create(['invoice_id' => $old->id, 'source_type' => 'money_tx', 'source_legacy_id' => 'x', 'amount' => '950.00', 'created_at' => now()]);
        IncomingInvoiceItem::insert([
            ['invoice_id' => $new->id, 'line_no' => 1, 'description' => 'ЛДСП ДЕМО 16мм', 'qty' => 1, 'unit_price' => 1, 'amount' => 1, 'nds_rate' => 12, 'nds_amount' => 0, 'total' => 1],
            ['invoice_id' => $new->id, 'line_no' => 2, 'description' => 'Плита Демо 2800×2070 белая', 'qty' => 1, 'unit_price' => 1, 'amount' => 1, 'nds_rate' => 12, 'nds_amount' => 0, 'total' => 1],
            ['invoice_id' => $new->id, 'line_no' => 3, 'description' => 'Неизвестный товар', 'qty' => 1, 'unit_price' => 1, 'amount' => 1, 'nds_rate' => 12, 'nds_amount' => 0, 'total' => 1],
        ]);
        (new Material(['name' => 'ЛДСП Демо 16мм', 'category' => 'ЛДСП/МДФ', 'unit' => 'лист']))->forceFill(['qty' => 0])->save();
        (new Material(['name' => 'Плита белая', 'category' => 'ЛДСП/МДФ', 'unit' => 'лист']))->forceFill(['qty' => 0])->save();
        MaterialAlias::create(['material_name' => 'Плита белая', 'alias_name' => 'Плита Демо 2800×2070']);

        $this->actingAs($this->director)->get('/esf')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Esf')
            ->where('page.title', 'Входящие ЭСФ')
            ->has('invoices', 2)
            ->where('invoices.0.id', $new->id)
            ->where('invoices.1.linked', 950)
            ->has('materialWarehouses', 5));

        $this->getJson("/esf/{$new->id}/detail")->assertOk()
            ->assertJsonPath('items.0.match.material', 'ЛДСП Демо 16мм')->assertJsonPath('items.0.match.via', 'direct')
            ->assertJsonPath('items.1.match.material', 'Плита белая')->assertJsonPath('items.1.match.via', 'alias')
            ->assertJsonPath('items.2.match', null);

        $this->from('/esf')->put("/esf/{$new->id}/type", ['invoice_type' => 'goods'])->assertSessionHasNoErrors();
        $this->assertSame('goods', $new->fresh()->invoice_type);
        $this->assertSame(1, AuditEvent::where('action', 'esf.type_changed')->count());
        $this->put("/esf/{$new->id}/type", ['invoice_type' => 'other'])->assertSessionHasErrors('invoice_type');
    }

    public function test_roles_without_the_page_cannot_read_or_import(): void
    {
        $inv = $this->invoice();
        $buyer = User::factory()->role('buyer')->create();
        $this->actingAs($buyer)->get('/esf')->assertForbidden();
        $this->actingAs($buyer)->getJson("/esf/{$inv->id}/detail")->assertForbidden();
        $this->actingAs($buyer)->put("/esf/{$inv->id}/type", ['invoice_type' => 'goods'])->assertForbidden();
        $this->actingAs($buyer)->post('/esf/import/preview', ['files' => [UploadedFile::fake()->createWithContent('a.xml', $this->xml())]])->assertForbidden();
        $this->assertSame('general', $inv->fresh()->invoice_type);
    }
}
