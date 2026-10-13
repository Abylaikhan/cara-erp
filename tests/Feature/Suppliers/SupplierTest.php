<?php

namespace Tests\Feature\Suppliers;

use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExpenseCategoryService;
use App\Services\SupplierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M06 «Поставщики»: list, card with every field, legacy duplicate check, expense item link, concurrency, deletion, rights. */
class SupplierTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private ExpenseCategory $general;

    private ExpenseCategory $rent;

    private ExpenseCategory $power;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->role('buyer')->create(['name' => 'Демо Закупщик']);
        $this->general = ExpenseCategory::create(['code' => 'general', 'name' => 'Общие расходы', 'sort_order' => 10]);
        $this->rent = ExpenseCategory::create(['code' => 'gen_rent', 'name' => 'Аренда', 'parent_id' => $this->general->id, 'sort_order' => 10]);
        $this->power = ExpenseCategory::create(['code' => 'gen_power', 'name' => 'Электроэнергия', 'parent_id' => $this->general->id, 'sort_order' => 20]);
    }

    private function supplier(array $attrs = []): Supplier
    {
        return Supplier::create(array_merge(['name' => 'ТОО «Демо»'], $attrs));
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => ' ТОО «Новый Поставщик» ', 'category' => 'ЛДСП', 'status' => 'active', 'contact' => 'Демо Контакт', 'phone' => '+7 700 000 0000',
            'email' => '', 'bin' => '000000000021', 'lead_time_days' => '5', 'turnover' => '1250000.50', 'defect_pct' => '1.5', 'address' => 'Демо-адрес',
            'comment' => '', 'expense_category_id' => $this->general->id, 'expense_article_id' => $this->rent->id, 'expense_source' => 'esf',
        ], $override);
    }

    public function test_page_lists_active_suppliers_by_name_with_expense_options(): void
    {
        $b = $this->supplier(['name' => 'Бета', 'expense_category_id' => $this->rent->id]);
        $a = $this->supplier(['name' => 'альфа']);
        $this->supplier(['name' => 'Удалённый'])->forceFill(['active' => false])->save();

        $this->actingAs($this->buyer)->get('/suppliers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Suppliers')
            ->where('page.title', 'Поставщики')
            ->has('suppliers', 2)
            ->where('suppliers.0.id', $a->id)
            ->where('suppliers.1.id', $b->id)
            ->where('suppliers.1.expense_category_id', $this->general->id)
            ->where('suppliers.1.expense_article_id', $this->rent->id)
            ->where('expenseCategories.0.name', 'Общие расходы')
            ->has('expenseCategories.0.articles', 2));
    }

    public function test_roles_without_the_page_cannot_read_or_write(): void
    {
        $manager = User::factory()->role('manager')->create();
        $s = $this->supplier();
        $this->actingAs($manager)->get('/suppliers')->assertForbidden();
        $this->actingAs($manager)->post('/suppliers', $this->payload())->assertForbidden();
        $this->actingAs($manager)->put("/suppliers/{$s->id}", $this->payload(['lock_version' => 1]))->assertForbidden();
        $this->actingAs($manager)->delete("/suppliers/{$s->id}")->assertForbidden();
        $this->assertSame(1, Supplier::count());
    }

    public function test_card_keeps_every_field_including_those_legacy_lost(): void
    {
        $this->actingAs($this->buyer)->from('/suppliers')->post('/suppliers', $this->payload())->assertRedirect('/suppliers')->assertSessionHasNoErrors();

        $s = Supplier::sole();
        $this->assertSame(
            ['ТОО «Новый Поставщик»', 'ЛДСП', 'active', '000000000021', 5, '1250000.50', '1.50', 'Демо-адрес', null, null, $this->rent->id, 'esf', 'Демо Закупщик'],
            [$s->name, $s->category, $s->status, $s->bin, $s->lead_time_days, $s->turnover, $s->defect_pct, $s->address, $s->email, $s->comment, $s->expense_category_id, $s->expense_source, $s->created_by_name],
        );
        $this->get('/suppliers')->assertInertia(fn (Assert $page) => $page->where('suppliers.0.turnover', 1250000.5)->where('suppliers.0.lead_time_days', 5));
        $this->assertDatabaseHas('audit_events', ['action' => 'supplier.created', 'subject_id' => (string) $s->id]);
    }

    public function test_a_new_card_is_refused_when_legacy_would_find_the_supplier(): void
    {
        $this->supplier(['name' => 'ТОО «Стройматериалы»', 'bin' => '000000000031']);
        $this->actingAs($this->buyer)->from('/suppliers');
        $message = 'Поставщик «ТОО «Стройматериалы»» с таким БИН/названием уже есть в справочнике — откройте его карточку вместо создания новой.';

        $this->post('/suppliers', $this->payload(['name' => 'Совсем другое', 'bin' => '000000000031']))->assertSessionHasErrors(['name' => $message]);
        $this->post('/suppliers', $this->payload(['name' => 'тоо "Стройматериалы"', 'bin' => '']))->assertSessionHasErrors(['name' => $message]);
        $this->post('/suppliers', $this->payload(['name' => 'ТОО «Стройматериалы» (склад)', 'bin' => '']))->assertSessionHasErrors(['name' => $message]);
        $this->post('/suppliers', $this->payload(['name' => 'Строй', 'bin' => '']))->assertSessionHasErrors(['name' => $message]); // one name contains the other
        $this->assertSame(1, Supplier::count());

        $this->post('/suppliers', $this->payload(['name' => 'ИП Демо Новый', 'bin' => '']))->assertSessionHasNoErrors();
        $this->assertSame(2, Supplier::count());
    }

    public function test_validation_messages(): void
    {
        $this->actingAs($this->buyer)->from('/suppliers');
        $this->post('/suppliers', $this->payload(['name' => ' ']))->assertSessionHasErrors(['name' => 'Укажите название поставщика']);
        $this->post('/suppliers', $this->payload(['lead_time_days' => '2.5']))->assertSessionHasErrors(['lead_time_days' => 'Срок поставки — целое число дней']);
        $this->post('/suppliers', $this->payload(['turnover' => '-1']))->assertSessionHasErrors(['turnover' => 'Оборот не может быть отрицательным']);
        $this->post('/suppliers', $this->payload(['defect_pct' => '101']))->assertSessionHasErrors(['defect_pct' => '% брака — от 0 до 100']);
        $this->post('/suppliers', $this->payload(['status' => 'closed']))->assertSessionHasErrors('status');
        $this->post('/suppliers', $this->payload(['expense_article_id' => $this->general->id]))->assertSessionHasErrors('expense_article_id');
        $this->post('/suppliers', $this->payload(['expense_category_id' => $this->rent->id, 'expense_article_id' => '']))->assertSessionHasErrors('expense_category_id');
        $this->assertSame(0, Supplier::count());
    }

    public function test_update_changes_fields_and_expense_item_and_keeps_a_deleted_link(): void
    {
        $s = $this->supplier(['expense_category_id' => $this->rent->id]);
        $this->rent->forceFill(['active' => false])->save(); // deleted later in «Статьи расходов»

        $this->actingAs($this->buyer)->from('/suppliers')
            ->put("/suppliers/{$s->id}", $this->payload(['name' => 'ТОО «Демо» новое', 'status' => 'blocked', 'lock_version' => 1]))
            ->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertSame(['ТОО «Демо» новое', 'blocked', $this->rent->id, 2], [$s->name, $s->status, $s->expense_category_id, $s->lock_version]);
        $this->get('/suppliers')->assertInertia(fn (Assert $page) => $page->where('expenseCategories.0.articles.0.deleted', true));

        $this->put("/suppliers/{$s->id}", $this->payload(['name' => 'ТОО «Демо» новое', 'expense_article_id' => $this->power->id, 'lock_version' => 2]))->assertSessionHasNoErrors();
        $this->assertSame($this->power->id, $s->fresh()->expense_category_id);
        $this->put("/suppliers/{$s->id}", $this->payload(['name' => 'ТОО «Демо» новое', 'expense_category_id' => '', 'expense_article_id' => '', 'lock_version' => 3]))->assertSessionHasNoErrors();
        $this->assertNull($s->fresh()->expense_category_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'supplier.updated', 'subject_id' => (string) $s->id]);
    }

    public function test_second_editor_gets_a_conflict(): void
    {
        $s = $this->supplier();
        app(SupplierService::class)->update($s->id, ['name' => 'Изменён первым', 'status' => 'active', 'expense_source' => 'payment'], null, null, 1, $this->buyer);

        $this->actingAs($this->buyer)->from('/suppliers')->put("/suppliers/{$s->id}", $this->payload(['name' => 'Изменён вторым', 'lock_version' => 1]))
            ->assertSessionHasErrors(['lock_version' => SupplierService::STALE]);
        $this->assertSame('Изменён первым', $s->fresh()->name);
    }

    public function test_deleted_supplier_does_not_return_after_reload(): void
    {
        $s = $this->supplier();
        $this->actingAs($this->buyer)->from('/suppliers')->delete("/suppliers/{$s->id}")->assertSessionHasNoErrors();
        $this->assertFalse($s->fresh()->active);
        $this->get('/suppliers')->assertInertia(fn (Assert $page) => $page->has('suppliers', 0));
        $this->delete("/suppliers/{$s->id}")->assertSessionHasErrors(['supplier' => SupplierService::NOT_FOUND]);
        $this->assertDatabaseHas('audit_events', ['action' => 'supplier.deactivated', 'subject_id' => (string) $s->id]);
    }

    public function test_merging_expense_articles_moves_supplier_links(): void
    {
        $s = $this->supplier(['expense_category_id' => $this->rent->id]);
        $director = User::factory()->role('director')->create();

        app(ExpenseCategoryService::class)->merge($this->rent->id, $this->power->id, $director);

        $this->assertSame($this->power->id, $s->fresh()->expense_category_id);
    }

    public function test_name_key_reproduces_legacy_norm_sup_name_merge(): void
    {
        // Expected values computed by the legacy JS function on the same strings.
        $cases = [
            'ТОО «Синтетика»' => 'тоо синтетика', 'Синтетика (Алматы)' => 'синтетика', 'тоо альфа-бета' => 'тоо альфа бета',
            'TOO Romashka' => 'тоо ромаshка', 'LLP Sample Ltd' => 'llр sамрlе lтd', 'Қазақ Сауда' => 'аза сауда',
            '  Пробелы   много  ' => 'пробелы много', 'АО «Альфа» (филиал)' => 'ао альфа', 'ip1тоо1' => 'iр11', 'Ёлка и Ко' => 'ёлка и ко',
            '(только скобки)' => '',
        ];
        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, Supplier::normalizedName($name), $name);
        }
    }
}
