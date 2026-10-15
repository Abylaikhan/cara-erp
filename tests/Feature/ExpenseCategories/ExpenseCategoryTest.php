<?php

namespace Tests\Feature\ExpenseCategories;

use App\Models\AuditEvent;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCategoryMerge;
use App\Models\User;
use App\Services\ExpenseCategoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M14 «Статьи расходов»: CRUD, reload, validation, concurrency and atomic merge (QA-062, AC-58). */
class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private ExpenseCategory $general;

    private ExpenseCategory $electricity;

    private ExpenseCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->director = User::factory()->role('director')->create();
        $this->general = ExpenseCategory::create(['code' => 'general', 'name' => 'Общие', 'sort_order' => 10]);
        $this->electricity = ExpenseCategory::create(['code' => 'gen_electricity', 'name' => 'Электроэнергия', 'parent_id' => $this->general->id, 'is_fixed' => true, 'sort_order' => 10]);
        $this->rent = ExpenseCategory::create(['code' => 'gen_rent', 'name' => 'Аренда', 'parent_id' => $this->general->id, 'sort_order' => 20]);
    }

    private function asDirector(): static
    {
        return $this->actingAs($this->director)->from('/expense_cats');
    }

    public function test_page_lists_active_categories_with_parent_codes(): void
    {
        $this->asDirector()->get('/expense_cats')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ExpenseCats')
            ->has('categories', 3)
            ->where('categories.1.code', 'gen_electricity')
            ->where('categories.1.parent_code', 'general')
            ->where('categories.1.is_fixed', true)
            ->where('page.title', 'Статьи расходов'));
    }

    public function test_created_article_is_there_after_reload(): void
    {
        $this->asDirector()->post('/expense_cats', [
            'name' => '  Связь ', 'code' => 'gen_internet', 'is_top' => false, 'parent_id' => $this->general->id, 'is_fixed' => true, 'sort_order' => 30,
        ])->assertRedirect('/expense_cats')->assertSessionHasNoErrors();

        $this->get('/expense_cats')->assertInertia(fn (Assert $page) => $page
            ->has('categories', 4)
            ->where('categories.3.code', 'gen_internet')
            ->where('categories.3.name', 'Связь')
            ->where('categories.3.parent_id', $this->general->id));
        $this->assertDatabaseHas('audit_events', ['action' => 'expense_category.created', 'user_id' => $this->director->id]);
    }

    public function test_top_level_category_and_zero_sort_order(): void
    {
        $this->asDirector()->post('/expense_cats', ['name' => 'Налоги', 'code' => 'taxes', 'is_top' => true, 'sort_order' => 0])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('expense_categories', ['code' => 'taxes', 'parent_id' => null, 'sort_order' => 0]);
    }

    public function test_default_sort_order_is_50(): void
    {
        $this->asDirector()->post('/expense_cats', ['name' => 'Прочее', 'code' => 'other', 'is_top' => false, 'parent_id' => $this->general->id, 'sort_order' => null]);
        $this->assertDatabaseHas('expense_categories', ['code' => 'other', 'sort_order' => 50]);
    }

    public function test_validation_messages_repeat_legacy_texts(): void
    {
        $base = ['is_top' => false, 'parent_id' => $this->general->id];
        $this->asDirector()->post('/expense_cats', $base + ['name' => '', 'code' => 'x'])->assertSessionHasErrors(['name' => 'Введите название']);
        $this->asDirector()->post('/expense_cats', $base + ['name' => 'X', 'code' => ''])->assertSessionHasErrors(['code' => 'Введите код']);
        $this->asDirector()->post('/expense_cats', $base + ['name' => 'X', 'code' => 'Bad-Code'])->assertSessionHasErrors(['code' => 'Код — только латиница и цифры']);
        $this->asDirector()->post('/expense_cats', $base + ['name' => 'X', 'code' => 'gen_rent'])->assertSessionHasErrors(['code' => 'Такой код уже существует']);
        $this->assertSame(3, ExpenseCategory::count());
    }

    public function test_code_of_a_deleted_article_cannot_be_reused(): void
    {
        $this->asDirector()->delete("/expense_cats/{$this->rent->id}")->assertSessionHasNoErrors();
        $this->asDirector()->post('/expense_cats', ['name' => 'Аренда 2', 'code' => 'gen_rent', 'is_top' => false, 'parent_id' => $this->general->id])
            ->assertSessionHasErrors(['code' => 'Такой код уже существует']);
    }

    public function test_article_needs_an_active_top_level_parent(): void
    {
        $this->asDirector()->post('/expense_cats', ['name' => 'X', 'code' => 'x1', 'is_top' => false, 'parent_id' => $this->rent->id])
            ->assertSessionHasErrors(['parent_id' => 'Выберите категорию первого уровня']);
        $this->asDirector()->post('/expense_cats', ['name' => 'X', 'code' => 'x2', 'is_top' => false])
            ->assertSessionHasErrors(['parent_id' => 'Выберите категорию первого уровня']);
        $this->assertDatabaseMissing('expense_categories', ['code' => 'x1']);
    }

    public function test_update_keeps_code_and_level_and_records_changes(): void
    {
        $other = ExpenseCategory::create(['code' => 'production', 'name' => 'Производство', 'sort_order' => 20]);

        $this->asDirector()->put("/expense_cats/{$this->rent->id}", [
            'name' => 'Аренда цеха', 'parent_id' => $other->id, 'is_fixed' => true, 'sort_order' => 5, 'lock_version' => 1, 'code' => 'hacked',
        ])->assertSessionHasNoErrors();

        $this->rent->refresh();
        $this->assertSame(['gen_rent', 'Аренда цеха', $other->id, true, 5, 2], [$this->rent->code, $this->rent->name, $this->rent->parent_id, $this->rent->is_fixed, $this->rent->sort_order, $this->rent->lock_version]);
        $event = AuditEvent::where('action', 'expense_category.updated')->sole();
        $this->assertEquals(['from' => 'Аренда', 'to' => 'Аренда цеха'], $event->changes['name']); // jsonb reorders keys

        $this->asDirector()->put("/expense_cats/{$this->general->id}", ['name' => 'Общие расходы', 'parent_id' => $other->id, 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertNull($this->general->fresh()->parent_id, 'a top-level category stays top-level');
    }

    public function test_concurrent_edit_is_rejected_not_overwritten(): void
    {
        $this->asDirector()->put("/expense_cats/{$this->rent->id}", ['name' => 'Первый', 'parent_id' => $this->general->id, 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->asDirector()->put("/expense_cats/{$this->rent->id}", ['name' => 'Второй', 'parent_id' => $this->general->id, 'lock_version' => 1])
            ->assertSessionHasErrors(['lock_version' => ExpenseCategoryService::STALE]);
        $this->assertSame('Первый', $this->rent->fresh()->name);
    }

    public function test_deleted_article_does_not_come_back_after_reload(): void
    {
        $this->asDirector()->delete("/expense_cats/{$this->rent->id}")->assertSessionHasNoErrors();

        $this->get('/expense_cats')->assertInertia(fn (Assert $page) => $page->has('categories', 2)
            ->where('categories', fn ($list) => collect($list)->pluck('code')->doesntContain('gen_rent')));
        $this->assertDatabaseHas('expense_categories', ['code' => 'gen_rent', 'active' => false]);
        $this->assertDatabaseHas('audit_events', ['action' => 'expense_category.deactivated', 'subject_id' => (string) $this->rent->id]);
    }

    public function test_category_with_active_articles_cannot_be_deleted(): void
    {
        $this->asDirector()->delete("/expense_cats/{$this->general->id}")->assertSessionHasErrors(['category' => 'Нельзя удалить — есть 2 подстатей']);
        $this->asDirector()->delete("/expense_cats/{$this->rent->id}");
        $this->asDirector()->delete("/expense_cats/{$this->electricity->id}");
        $this->asDirector()->delete("/expense_cats/{$this->general->id}")->assertSessionHasNoErrors();
    }

    public function test_merge_moves_every_registered_reference_and_keeps_a_report(): void
    {
        Schema::create('test_expense_refs', fn ($t) => [$t->id(), $t->foreignId('category_id')->constrained('expense_categories')]);
        Schema::create('test_expense_refs_2', fn ($t) => [$t->id(), $t->foreignId('expense_category_id')->nullable()->constrained('expense_categories')]);
        config(['erp.expense_category_references' => [
            ['table' => 'test_expense_refs', 'column' => 'category_id'],
            ['table' => 'test_expense_refs_2', 'column' => 'expense_category_id'],
        ]]);
        DB::table('test_expense_refs')->insert([['category_id' => $this->rent->id], ['category_id' => $this->rent->id], ['category_id' => $this->electricity->id]]);
        DB::table('test_expense_refs_2')->insert([['expense_category_id' => $this->rent->id]]);

        $this->asDirector()->post('/expense_cats/merge', ['from_id' => $this->rent->id, 'to_id' => $this->electricity->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('alert', '✅ Готово — все транзакции "gen_rent" перенесены в "gen_electricity"');

        $this->assertSame(0, DB::table('test_expense_refs')->where('category_id', $this->rent->id)->count());
        $this->assertSame(3, DB::table('test_expense_refs')->where('category_id', $this->electricity->id)->count());
        $this->assertSame(1, DB::table('test_expense_refs_2')->where('expense_category_id', $this->electricity->id)->count());
        $this->rent->refresh();
        $this->assertFalse($this->rent->active);
        $this->assertSame($this->electricity->id, $this->rent->merged_into_id);
        $merge = ExpenseCategoryMerge::sole();
        $this->assertEquals([$this->rent->id => 2, $this->electricity->id => 1], $merge->references_before['test_expense_refs.category_id']);
        $this->assertEquals([$this->rent->id => 0, $this->electricity->id => 3], $merge->references_after['test_expense_refs.category_id']);
        $this->get('/expense_cats')->assertInertia(fn (Assert $page) => $page->has('categories', 2));
    }

    public function test_merge_rewrites_the_article_codes_of_advance_lines_and_the_group_of_money_operations(): void
    {
        $production = ExpenseCategory::create(['code' => 'production', 'name' => 'Производство', 'sort_order' => 20]);
        $tools = ExpenseCategory::create(['code' => 'prod_tools', 'name' => 'Инструмент', 'parent_id' => $production->id, 'sort_order' => 10]);
        $acc = DB::table('money_accounts')->insertGetId(['name' => 'Касса Демо', 'type' => 'cash', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        DB::table('money_transactions')->insert([
            ['account_id' => $acc, 'tx_date' => '2026-10-01', 'flow' => 'expense', 'category' => 'general', 'expense_category_id' => $this->rent->id, 'amount' => 10],
            ['account_id' => $acc, 'tx_date' => '2026-10-01', 'flow' => 'expense', 'category' => 'general', 'expense_category_id' => $this->electricity->id, 'amount' => 20],
        ]);
        DB::table('advance_reports')->insert(['doc_num' => 'АО-1', 'employee_name' => 'Демо', 'report_date' => '2026-10-01', 'issued' => 0, 'spent' => 0, 'returned' => 0, 'status' => 'open',
            'lines' => json_encode([['type' => 'expense', 'category' => 'general', 'subcategory' => 'gen_rent', 'amount' => 5], ['type' => 'supplier', 'amount' => 7],
                ['type' => 'expense', 'category' => 'general', 'subcategory' => 'gen_electricity', 'amount' => 3]])]);

        $merge = app(ExpenseCategoryService::class)->merge($this->rent->id, $tools->id, $this->director);

        $this->assertSame(['production', 'general'], DB::table('money_transactions')->orderBy('id')->pluck('category')->all());
        $this->assertSame([$tools->id, $this->electricity->id], DB::table('money_transactions')->orderBy('id')->pluck('expense_category_id')->all());
        $lines = json_decode(DB::table('advance_reports')->value('lines'), true);
        $this->assertSame([['production', 'prod_tools', 5], [null, null, 7], ['general', 'gen_electricity', 3]], array_map(fn ($l) => [$l['category'] ?? null, $l['subcategory'] ?? null, $l['amount']], $lines));
        $this->assertEquals([$this->rent->id => 1, $tools->id => 0], $merge->references_before['advance_reports.lines.subcategory']);
        $this->assertEquals([$this->rent->id => 0, $tools->id => 1], $merge->references_after['advance_reports.lines.subcategory']);
    }

    public function test_merge_is_all_or_nothing(): void
    {
        Schema::create('test_expense_refs', fn ($t) => [$t->id(), $t->foreignId('category_id')->constrained('expense_categories')]);
        DB::table('test_expense_refs')->insert([['category_id' => $this->rent->id]]);
        config(['erp.expense_category_references' => [
            ['table' => 'test_expense_refs', 'column' => 'category_id'],
            ['table' => 'table_that_fails', 'column' => 'category_id'],
        ]]);

        try {
            app(ExpenseCategoryService::class)->merge($this->rent->id, $this->electricity->id, $this->director);
            $this->fail('merge must fail');
        } catch (QueryException) {
            // expected: the second reference update fails inside the transaction
        }

        $this->assertSame(1, DB::table('test_expense_refs')->where('category_id', $this->rent->id)->count(), 'first update rolled back');
        $this->assertTrue($this->rent->fresh()->active);
        $this->assertSame(0, ExpenseCategoryMerge::count());
    }

    public function test_merge_rules_and_repeated_request(): void
    {
        $this->asDirector()->post('/expense_cats/merge', ['from_id' => $this->rent->id, 'to_id' => $this->rent->id])
            ->assertSessionHasErrors(['to_id' => 'Выберите разные статьи']);
        $this->asDirector()->post('/expense_cats/merge', ['from_id' => $this->general->id, 'to_id' => $this->rent->id])
            ->assertSessionHasErrors(['from_id' => 'Объединять можно только статьи второго уровня']);

        $this->asDirector()->post('/expense_cats/merge', ['from_id' => $this->rent->id, 'to_id' => $this->electricity->id])->assertSessionHasNoErrors();
        $this->asDirector()->post('/expense_cats/merge', ['from_id' => $this->rent->id, 'to_id' => $this->electricity->id])
            ->assertSessionHasErrors(['from_id' => 'Статья не найдена или уже удалена — обновите страницу.']);
        $this->assertSame(1, ExpenseCategoryMerge::count(), 'a repeated (double-click) merge changes nothing');
    }

    public function test_other_roles_cannot_read_or_change_the_directory(): void
    {
        $accountant = User::factory()->role('accountant')->create();
        $this->actingAs($accountant)->get('/expense_cats')->assertForbidden();
        $this->actingAs($accountant)->post('/expense_cats', ['name' => 'X', 'code' => 'x', 'is_top' => true])->assertForbidden();
        $this->actingAs($accountant)->put("/expense_cats/{$this->rent->id}", ['name' => 'X', 'lock_version' => 1])->assertForbidden();
        $this->actingAs($accountant)->delete("/expense_cats/{$this->rent->id}")->assertForbidden();
        $this->actingAs($accountant)->post('/expense_cats/merge', ['from_id' => $this->rent->id, 'to_id' => $this->electricity->id])->assertForbidden();
        $this->assertSame(3, ExpenseCategory::active()->count());
        $this->assertSame('Аренда', $this->rent->fresh()->name);
    }
}
