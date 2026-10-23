<?php

namespace Tests\Feature\Money;

use App\Models\User;
use App\Services\Money\BankStatementUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** «Сверка с банком» → «📥 Загрузить выписку» (legacy handleBankReconImport; ADR-0050): synthetic Kaspi and Halyk files. */
class BankStatementUploadTest extends TestCase
{
    use RefreshDatabase;

    private int $kaspi;

    private int $halyk;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['kaspi' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'halyk' => '0265fb65-7812-4bee-b794-71306246edb7'] as $f => $key) {
            $this->{$f} = DB::table('money_accounts')->insertGetId(['name' => "Банк Демо {$f}", 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
            DB::table('legacy_id_map')->insert(['source_table' => 'money_accounts', 'source_key' => $key, 'target_table' => 'money_accounts', 'target_id' => $this->{$f}, 'snapshot_id' => 'demo']);
        }
    }

    /** @param list<list<string>> $rows */
    private function file(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $v) {
                $book->getActiveSheet()->getCell([$c + 1, $r + 1])->setValueExplicit($v, DataType::TYPE_STRING);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'выписка.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function kaspiFile(): UploadedFile
    {
        return $this->file([
            ['Выписка по счёту (демо)'],
            ['№ документа', 'Дата операции', 'Дебет', 'Кредит', 'Наименование бенефициара / отправителя денег', 'Назначение платежа', 'КНП'],
            ['1', '2', '3', '4', '5', '6', '7'],
            ['П-1', '07.08.2031 17:22:48', '97 300,40', '', "ТОО Демо\nПоставщик\nИИН/БИН 870305080101", 'Оплата демо', '710'],
            ['П-2', '08.08.2031', '', '52183.17', "ИП Демо\nИИН 870305080202", 'Поступление демо', '190'],
            ['', '', '', '', '', '', ''],
            ['Итого', '', '', '', '', '', ''],
        ]);
    }

    public function test_a_kaspi_statement_is_read_as_legacy_reads_it_and_a_second_upload_adds_nothing(): void
    {
        $this->actingAs(User::factory()->role('accountant')->create())->from('/money');
        $this->post('/money/bank-recon/import', ['file' => $this->kaspiFile()])->assertRedirect('/money')->assertSessionHasNoErrors()
            ->assertSessionHas('alert', 'Готово! Добавлено новых операций: 2 (пропущено как уже загруженные: 0)');
        $rows = DB::table('bank_statement_rows')->orderBy('id')->get()->map(fn ($r) => [$r->account_id, $r->row_date, $r->flow, $r->amount, $r->counterparty, $r->bin, $r->knp, $r->doc_num, $r->bank_format])->all();
        $this->assertSame([
            [$this->kaspi, '2031-08-07', 'expense', '97300.40', 'ТОО Демо Поставщик', '870305080101', '710', 'П-1', 'kaspi'],
            [$this->kaspi, '2031-08-08', 'income', '52183.17', 'ИП Демо', '870305080202', '190', 'П-2', 'kaspi'],
        ], $rows);
        $this->assertDatabaseHas('audit_events', ['action' => 'bank_statement.imported']);

        $this->post('/money/bank-recon/import', ['file' => $this->kaspiFile()])->assertSessionHasErrors(['file' => 'Все 2 операций из файла уже есть в сверке — новых не найдено.']);
    }

    public function test_a_halyk_statement_goes_to_its_account_and_unknown_files_are_refused(): void
    {
        $this->actingAs(User::factory()->role('director')->create())->from('/money');
        $halyk = $this->file([
            ['Номер документа', 'Дата валютирования', 'Контрагент', 'БИН контрагента', 'Дебет', 'Кредит', 'Назначение платежа', 'КНП'],
            ['', '31.02.2031', 'ТОО Демо', '870305080101', '1.234,5', '', 'Демо', '710'],
        ]);
        $this->post('/money/bank-recon/import', ['file' => $halyk])->assertSessionHasNoErrors();
        $this->assertSame([[$this->halyk, null, '1234.50']], DB::table('bank_statement_rows')->get()->map(fn ($r) => [$r->account_id, $r->row_date, $r->amount])->all(), 'an impossible date is no date');

        $this->post('/money/bank-recon/import', ['file' => $this->file([['a', 'b', 'c', 'd', 'e'], ['1', '2', '3', '4', '5']])])
            ->assertSessionHasErrors(['file' => 'Не удалось найти строку заголовков в файле. Пока поддерживаются выписки Kaspi и Halyk.']);
        $this->post('/money/bank-recon/import', ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');
        $this->actingAs(User::factory()->role('salon_armada')->create())->post('/money/bank-recon/import', ['file' => $this->kaspiFile()])->assertForbidden();
    }

    public function test_the_numbers_dates_and_names_of_legacy(): void
    {
        $this->assertSame(['97300.00', '97300.00', '52183.17', '0', '1234'], array_map([BankStatementUpload::class, 'number'], ['97 300,00', '97.300,00', '52183.17', '', '1 234 ₸']));
        $this->assertSame(['2031-08-07', null, null], array_map([BankStatementUpload::class, 'date'], ['07.08.2031 17:22:48', '2031-08-07', '32.01.2031']));
        $this->assertSame(['ТОО Демо', ''], BankStatementUpload::nameAndBin("ТОО Демо\n"));
    }
}
