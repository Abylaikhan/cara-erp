<?php

namespace Tests\Feature\Customers;

use App\Models\SalesChannel;
use App\Models\User;
use App\Support\LegacyCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Dealers CSV import with the rules of legacy parseGenericCSV/importDealersCSV; whole file checked first, repeat-safe. */
class DealerImportTest extends TestCase
{
    use RefreshDatabase;

    /** Exactly what legacy downloadDealerTemplate produces. */
    private const TEMPLATE = "\u{FEFF}Название дилера;Регион;Кол-во заказов;Оборот за год;Статус\nДилер «Пример»;Город;0;0;Активен\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->role('manager')->create())->from('/customers');
    }

    private function upload(string $content): TestResponse
    {
        return $this->post('/customers/dealers/import', ['file' => UploadedFile::fake()->createWithContent('dealers.csv', $content)]);
    }

    private function added(): array
    {
        return SalesChannel::query()->dealers()->where('code', '~', '^dealer_[0-9]+$')->orderBy('sort_order')->get(['name', 'region'])->toArray();
    }

    public function test_legacy_template_imports_one_dealer(): void
    {
        $this->upload(self::TEMPLATE)->assertRedirect('/customers')->assertSessionHas('result', ['text' => '✓ Импортировано 1 запись', 'color' => 'green']);
        $this->assertSame([['name' => 'Дилер «Пример»', 'region' => 'Город']], $this->added());
    }

    public function test_commas_quotes_and_rows_without_name_follow_legacy(): void
    {
        $csv = "Наименование,Город,Статус\r\n\"Дилер, Север\",Павлодар,На паузе\r\n,Город,Активен\r\n  Дилер Юг  , — ,\r\n\r\n";
        $this->upload($csv)->assertSessionHas('result', ['text' => '✓ Импортировано 2 записи, пропущено строк без названия: 1', 'color' => 'green']);
        $this->assertSame([
            ['name' => 'Дилер, Север', 'region' => 'Павлодар'],
            ['name' => 'Дилер Юг', 'region' => null],
        ], $this->added());
    }

    public function test_repeated_upload_adds_nothing_and_known_dealers_are_not_duplicated(): void
    {
        $csv = "Название дилера;Регион\nДилер Новый;Кокшетау\nдилер астана;Астана\n";
        $this->upload($csv)->assertSessionHas('result', ['text' => '✓ Импортировано 1 запись, уже есть в списке: 1', 'color' => 'green']);
        $this->upload($csv)->assertSessionHas('result', ['text' => '✓ Импортировано 0 записей, уже есть в списке: 2', 'color' => 'green']);
        $this->assertSame(9, SalesChannel::count());
    }

    public function test_orders_and_turnover_from_the_file_are_not_stored(): void
    {
        $this->upload("Название дилера;Кол-во заказов;Оборот за год\nДилер Цифры;12;3500000\n")
            ->assertSessionHas('result', ['text' => '✓ Импортировано 1 запись. Кол-во заказов и оборот считаются из заказов и из файла не берутся', 'color' => 'green']);
    }

    public function test_bad_files_change_nothing(): void
    {
        $cases = [
            "Название дилера;Регион\n" => 'Файл пуст или содержит только заголовок.',
            "Регион;Статус\nАлматы;Активен\n" => 'Не найдена колонка с названием. Скачайте шаблон CSV для примера.',
            "Название дилера;Регион\nДилер А;Алматы\n".str_repeat('Д', 101).";Алматы\n" => 'Импорт не выполнен: строка 3: название или регион длиннее 100 символов.',
            mb_convert_encoding("Название дилера\nДилер Кириллица\n", 'Windows-1251', 'UTF-8') => 'Файл не в кодировке UTF-8. Сохраните CSV в UTF-8 (как шаблон) и повторите.',
        ];
        foreach ($cases as $content => $message) {
            $this->upload($content)->assertSessionHas('result', ['text' => $message, 'color' => 'red']);
        }
        $this->post('/customers/dealers/import', [])->assertSessionHas('result', ['text' => 'Файл не получен — выберите CSV ещё раз.', 'color' => 'red']);
        $this->assertSame(8, SalesChannel::count());
    }

    public function test_result_text_uses_the_legacy_plural_forms(): void
    {
        $this->assertSame(
            ['✓ Импортировано 0 записей', '✓ Импортировано 1 запись', '✓ Импортировано 3 записи', '✓ Импортировано 5 записей', '✓ Импортировано 21 записей'],
            array_map(LegacyCsv::importedText(...), [0, 1, 3, 5, 21]),
        );
        $this->assertSame(['a', 'b;c', 'd'], LegacyCsv::parseLine(' a ;"b;c"; d', ';'));
        $this->assertSame(';', LegacyCsv::delimiter('a;b,c;d'));
        $this->assertSame(',', LegacyCsv::delimiter('a,b;c,d'));
    }
}
