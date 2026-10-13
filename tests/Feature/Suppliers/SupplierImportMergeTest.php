<?php

namespace Tests\Feature\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierMerge;
use App\Models\User;
use App\Services\SupplierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M06 «📥 Импорт CSV» (legacy importSuppliersCSV) and «🔗 Объединить» (legacy mergeSupplierGroup). */
class SupplierImportMergeTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = "\u{FEFF}Название;БИН;Категория;Контактное лицо;Телефон;Оборот за год;Срок поставки (дни);Статус\n"
        ."ТОО «Гамма»;000000000052;Ткань;Демо Контакт;+7 700 000 0052;1 000 000;7;Активен\n"
        ."Другое имя;000000000051;;;;;;\n"
        ."тоо \"Бета\";000000000053;;Демо Контакт Бета;;;;\n"
        .";;;;;;;\n"
        ."ТОО «Гамма»;000000000052;;;;;;\n"
        ."ИП Пауза;;Фурнитура;;;;3,5;На паузе\n";

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->role('buyer')->create(['name' => 'Демо Закупщик']);
        $this->actingAs($this->buyer)->from('/suppliers');
    }

    private function upload(string $content, bool $apply): TestResponse
    {
        return $this->post('/suppliers/import', ['file' => UploadedFile::fake()->createWithContent('suppliers.csv', $content), 'apply' => $apply ? 1 : 0]);
    }

    public function test_csv_is_planned_confirmed_and_applied_like_legacy(): void
    {
        Supplier::create(['name' => 'ТОО «Альфа»', 'bin' => '000000000051', 'contact' => 'Есть', 'category' => 'Есть']);
        $beta = Supplier::create(['name' => 'ТОО «Бета»', 'category' => 'Плита']);

        $this->upload(self::CSV, false)->assertRedirect('/suppliers')->assertSessionHas('dialog', ['type' => 'confirm', 'text' => "В файле строк: 5\n"
            ."Будет добавлено новых поставщиков: 2\nБудут дополнены данные у существующих: 1\nПропущено строк без названия: 1\n\nПродолжить?"]);
        $this->assertSame(2, Supplier::count(), 'the plan writes nothing');

        $this->upload(self::CSV, true)->assertSessionHas('dialog', ['type' => 'alert', 'text' => "Готово!\nДобавлено новых: 2\nДополнено существующих: 1\nПропущено (без названия): 1"]);
        $gamma = Supplier::where('name', 'ТОО «Гамма»')->sole();
        $this->assertSame(['000000000052', 'Ткань', 'Демо Контакт', '1000000.00', 7, 'active', 'Импортирован из CSV', 'Демо Закупщик'],
            [$gamma->bin, $gamma->category, $gamma->contact, $gamma->turnover, $gamma->lead_time_days, $gamma->status, $gamma->comment, $gamma->created_by_name]);
        $pause = Supplier::where('name', 'ИП Пауза')->sole();
        $this->assertSame(['pause', 3, null], [$pause->status, $pause->lead_time_days, $pause->turnover]);
        $beta->refresh();
        $this->assertSame(['000000000053', 'Демо Контакт Бета', 'Плита', 2], [$beta->bin, $beta->contact, $beta->category, $beta->lock_version]);

        $this->upload(self::CSV, false)->assertSessionHas('dialog', ['type' => 'alert', 'text' => 'Новых поставщиков не найдено — все 5 записей из файла уже есть в справочнике.']);
        $this->assertSame(4, Supplier::count());
        $this->assertDatabaseHas('audit_events', ['action' => 'supplier.imported', 'user_id' => $this->buyer->id]);
    }

    public function test_csv_problems_are_reported_and_change_nothing(): void
    {
        $cases = [
            "Название;БИН\n" => 'Файл пуст или содержит только заголовок.',
            "Регион;Телефон\nАлматы;1\n" => 'Не найдена колонка с названием поставщика. Проверьте, что в файле есть колонка «Название» или «Контрагент».',
            "Название;БИН\n;000000000060\n" => 'Не найдено ни одной строки с названием поставщика. Пропущено строк: 1',
            "Название\n".str_repeat('Я', 256)."\n" => 'Импорт не выполнен: строка 2: поле «Название» длиннее 255 символов.',
            mb_convert_encoding("Название\nТОО Кириллица\n", 'Windows-1251', 'UTF-8') => 'Файл не в кодировке UTF-8. Сохраните CSV в UTF-8 и повторите.',
        ];
        foreach ($cases as $content => $text) {
            $this->upload($content, true)->assertSessionHas('dialog', ['type' => 'alert', 'text' => $text]);
        }
        $this->post('/suppliers/import', [])->assertSessionHas('dialog', ['type' => 'alert', 'text' => 'Файл не получен — выберите CSV ещё раз.']);
        $this->assertSame(0, Supplier::count());
    }

    public function test_merge_keeps_history_fills_fields_and_moves_references(): void
    {
        Schema::create('test_supplier_refs', fn ($t) => [$t->id(), $t->foreignId('supplier_id')->constrained('suppliers')]);
        config(['erp.supplier_references' => [['table' => 'test_supplier_refs', 'column' => 'supplier_id']]]);
        $kept = Supplier::create(['name' => 'ТОО «Альфа»', 'bin' => '000000000061', 'turnover' => '100.00', 'comment' => 'Основной']);
        $dupe = Supplier::create(['name' => 'ТОО Альфа', 'phone' => '+7 700 000 0061', 'turnover' => '50.50', 'category' => 'Плита']);
        $same = Supplier::create(['name' => 'ТОО «Альфа»', 'email' => 'demo@example.test']);
        DB::table('test_supplier_refs')->insert([['supplier_id' => $dupe->id], ['supplier_id' => $dupe->id], ['supplier_id' => $kept->id]]);

        $this->post('/suppliers/merge', ['supplier_id' => $kept->id, 'merged_ids' => [$dupe->id, $same->id]])->assertSessionHasNoErrors();

        $kept->refresh();
        $this->assertSame(['+7 700 000 0061', 'Плита', 'demo@example.test', '150.50', 'Основной | Объединено с: ТОО Альфа'],
            [$kept->phone, $kept->category, $kept->email, $kept->turnover, $kept->comment]);
        foreach ([$dupe, $same] as $s) {
            $s->refresh();
            $this->assertSame([false, $kept->id], [$s->active, $s->merged_into_id]);
        }
        $this->assertSame(3, DB::table('test_supplier_refs')->where('supplier_id', $kept->id)->count());
        $merge = SupplierMerge::sole();
        $this->assertSame([$dupe->id, $same->id], $merge->merged_ids);
        $this->assertEquals(['category' => $dupe->id, 'phone' => $dupe->id, 'email' => $same->id], $merge->filled_fields); // jsonb reorders keys
        $this->assertEquals([$kept->id => 1, $dupe->id => 2, $same->id => 0], $merge->references_before['test_supplier_refs.supplier_id']);
        $this->assertEquals([$kept->id => 3, $dupe->id => 0, $same->id => 0], $merge->references_after['test_supplier_refs.supplier_id']);
        $this->get('/suppliers')->assertInertia(fn (Assert $page) => $page->has('suppliers', 1)->where('suppliers.0.id', $kept->id));
    }

    public function test_merge_requests_that_make_no_sense_change_nothing(): void
    {
        $a = Supplier::create(['name' => 'Первый']);
        $b = Supplier::create(['name' => 'Второй']);
        $gone = Supplier::create(['name' => 'Удалённый']);
        app(SupplierService::class)->deactivate($gone->id, $this->buyer);

        $this->post('/suppliers/merge', ['supplier_id' => $a->id, 'merged_ids' => [$a->id]])->assertSessionHasErrors('merged_ids');
        $this->post('/suppliers/merge', ['supplier_id' => $a->id, 'merged_ids' => []])->assertSessionHasErrors('merged_ids');
        $this->post('/suppliers/merge', ['supplier_id' => $a->id, 'merged_ids' => [$gone->id]])->assertSessionHasErrors('merged_ids');
        $this->post('/suppliers/merge', ['supplier_id' => $a->id, 'merged_ids' => [999999]])->assertSessionHasErrors('merged_ids');
        $this->assertSame([true, true], [$a->fresh()->active, $b->fresh()->active]);
        $this->assertSame(0, SupplierMerge::count());

        $this->actingAs(User::factory()->role('manager')->create())->post('/suppliers/merge', ['supplier_id' => $a->id, 'merged_ids' => [$b->id]])->assertForbidden();
        $this->post('/suppliers/import', [])->assertForbidden();
    }
}
