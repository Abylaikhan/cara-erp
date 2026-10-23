<?php

namespace Tests\Feature\Export;

use App\Models\IncomingInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Legacy exportEsfGoodsToExcel (ADR-0044): every goods line with the warehouse its description suggests, and the warehouses. */
class EsfGoodsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_goods_lines_with_their_guessed_warehouse(): void
    {
        $goods = IncomingInvoice::create(['num' => '7', 'date' => '2031-03-01', 'status' => 'CREATED', 'supplier_name' => 'ТОО «Демо»', 'invoice_type' => 'goods',
            'total_amount' => '100.00', 'total_nds' => '12.00', 'total_with_nds' => '112.00']);
        $service = IncomingInvoice::create(['num' => '8', 'date' => '2031-03-02', 'status' => 'CREATED', 'supplier_name' => 'ИП Демо', 'invoice_type' => 'service',
            'total_amount' => '1.00', 'total_nds' => '0', 'total_with_nds' => '1.00']);
        foreach ([[$goods->id, 0, 'Лак полиуретановый'], [$goods->id, 1, 'Шуруп 4x40'], [$goods->id, 2, 'Что-то иное'], [$service->id, 0, 'Доставка']] as [$inv, $no, $desc]) {
            DB::table('incoming_invoice_items')->insert(['invoice_id' => $inv, 'line_no' => $no, 'description' => $desc, 'qty' => 2, 'unit_price' => '10.5', 'amount' => 21, 'nds_rate' => 0,
                'nds_amount' => 0, 'total' => 21]);
        }
        $this->actingAs(User::factory()->role('salon_armada')->create())->get('/esf/export-goods')->assertForbidden();

        $r = $this->actingAs(User::factory()->role('director')->create())->get('/esf/export-goods')->assertOk();
        $this->assertSame(['3', '1'], [$r->headers->get('X-Export-Rows'), $r->headers->get('X-Export-Invoices')]);
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $r->getContent());
        $book = IOFactory::load($file);
        unlink($file);
        $rows = $book->getSheet(0)->toArray(null, false, false);
        $this->assertSame(['Дата', '№ ЭСФ', 'Поставщик', 'Наименование товара', 'Кол-во', 'Цена', 'Сумма', 'СКЛАД (заполните)', 'item_id'], $rows[0]);
        $this->assertSame(['🎨 Склад краски', '🏭 Основной склад', '❓ Не определён'], array_column(array_slice($rows, 1), 7));
        $this->assertSame(['2031-03-01', '7', 'ТОО «Демо»', 'Лак полиуретановый'], array_slice($rows[1], 0, 4));
        $this->assertEquals([2, 10.5, 21], array_slice($rows[1], 4, 3));
        $this->assertSame(['Товары из ЭСФ', 'Склады'], $book->getSheetNames());
        $this->assertCount(8, $book->getSheet(1)->toArray());
    }
}
