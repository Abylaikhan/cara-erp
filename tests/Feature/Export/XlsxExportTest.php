<?php

namespace Tests\Feature\Export;

use App\Models\User;
use App\Services\Excel\XlsxFromRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/** ADR-0044: a page's rows become the workbook legacy builds with SheetJS — values as typed, widths, sheets; rights of the page. */
class XlsxExportTest extends TestCase
{
    use RefreshDatabase;

    private function read(string $bytes): Spreadsheet
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $bytes);
        $book = IOFactory::load($file);
        unlink($file);

        return $book;
    }

    public function test_values_keep_their_kind_and_a_text_is_never_a_formula(): void
    {
        $bytes = app(XlsxFromRows::class)->build([
            ['name' => 'Журнал работ', 'rows' => [['Журнал работ'], [], ['Дата', 'Сумма'], ['01.03.2031', 1234.5], ['=1+1', 7], [null, '']], 'cols' => [12, 18]],
            ['name' => 'Второй: лист/[x]', 'rows' => [[true, 0]]],
        ]);
        $book = $this->read($bytes);
        $ws = $book->getSheet(0);
        $this->assertSame('Журнал работ', $ws->getTitle());
        $this->assertSame(['Журнал работ', null, 'Дата', '01.03.2031', '=1+1', null], [$ws->getCell('A1')->getValue(), $ws->getCell('A2')->getValue(), $ws->getCell('A3')->getValue(),
            $ws->getCell('A4')->getValue(), $ws->getCell('A5')->getValue(), $ws->getCell('A6')->getValue()]);
        $this->assertSame(DataType::TYPE_STRING, $ws->getCell('A5')->getDataType(), 'a text beginning with «=» stays a text');
        $this->assertSame([DataType::TYPE_NUMERIC, 1234.5], [$ws->getCell('B4')->getDataType(), (float) $ws->getCell('B4')->getValue()]);
        $this->assertEqualsWithDelta(12.714, $ws->getColumnDimension('A')->getWidth(), 0.01, 'SheetJS wch → width');
        $this->assertSame('Второй  лист  x', $book->getSheet(1)->getTitle());
        $this->assertSame([DataType::TYPE_BOOL, DataType::TYPE_NUMERIC], [$book->getSheet(1)->getCell('A1')->getDataType(), $book->getSheet(1)->getCell('B1')->getDataType()]);
    }

    public function test_the_page_decides_who_may_export_and_the_file_is_named_as_legacy_names_it(): void
    {
        $body = ['page' => 'mrp', 'filename' => '../PromTech_Zhurnal_rabot_2031-03-01', 'sheets' => [['name' => 'Журнал работ', 'rows' => [['a', 1]], 'cols' => [10]]]];
        $this->actingAs(User::factory()->role('salon_armada')->create())->postJson('/export/xlsx', $body)->assertForbidden();

        $response = $this->actingAs(User::factory()->role('director')->create())->postJson('/export/xlsx', $body)->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("filename*=UTF-8''.._PromTech_Zhurnal_rabot_2031-03-01.xlsx", (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('a', $this->read($response->getContent())->getSheet(0)->getCell('A1')->getValue());
        $this->assertDatabaseHas('audit_events', ['action' => 'export.xlsx', 'subject_id' => 'mrp']);

        $this->postJson('/export/xlsx', [...$body, 'sheets' => [['name' => 'x', 'rows' => [[['nested']]]]]])->assertStatus(422);
    }
}
