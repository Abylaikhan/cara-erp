<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M07 «Каталог»: CRUD, legacy rights enforced by the server, reload, exact prices, concurrency (AC-16). */
class ProductTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $manager;

    private User $otherManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->director = User::factory()->role('director')->create(['name' => 'Демо Директор']);
        $this->manager = User::factory()->role('manager')->create(['name' => 'Демо Продажи']);
        $this->otherManager = User::factory()->role('manager')->create(['name' => 'Другой Менеджер']);
    }

    private function product(array $attrs = []): Product
    {
        $p = new Product(array_merge(['name' => 'Стул «Тест»', 'category' => 'Стул', 'price_retail' => 45000, 'price_wholesale' => 38000, 'unit' => 'шт'], $attrs));
        $p->forceFill(array_intersect_key($attrs, array_flip(['created_by_id', 'created_by_name', 'active'])))->save();

        return $p;
    }

    private function payload(array $override = []): array
    {
        return array_merge(['name' => 'Стол «Новый»', 'category' => 'Стол', 'price_retail' => '180000.50', 'price_wholesale' => 150000, 'unit' => 'шт', 'sku' => ' ST-1 ', 'description' => '', 'delete_requested' => false], $override);
    }

    public function test_page_lists_active_products_with_rights(): void
    {
        $own = $this->product(['created_by_id' => $this->manager->id, 'created_by_name' => $this->manager->name]);
        $foreign = $this->product(['name' => 'Чужой', 'created_by_id' => $this->otherManager->id, 'created_by_name' => $this->otherManager->name]);
        $this->product(['name' => 'Удалённый', 'active' => false]);

        $this->actingAs($this->manager)->get('/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Catalog')
            ->has('products', 2)
            ->where('canDelete', false)
            ->where('page.title', 'Каталог продукции')
            ->where('products', fn ($list) => collect($list)->firstWhere('id', $own->id)['can_edit'] === true
                && collect($list)->firstWhere('id', $foreign->id)['can_edit'] === false));
        $this->actingAs($this->director)->get('/catalog')->assertInertia(fn (Assert $page) => $page->where('canDelete', true));
    }

    public function test_created_product_keeps_exact_prices_and_author_after_reload(): void
    {
        $this->actingAs($this->manager)->from('/catalog')->post('/catalog', $this->payload())
            ->assertRedirect('/catalog')->assertSessionHas('toast', '✅ Товар добавлен');

        $p = Product::sole();
        $this->assertSame(['Стол «Новый»', '180000.50', '150000.00', 'ST-1', null, $this->manager->id, 'Демо Продажи'],
            [$p->name, $p->price_retail, $p->price_wholesale, $p->sku, $p->description, $p->created_by_id, $p->created_by_name]);
        $this->get('/catalog')->assertInertia(fn (Assert $page) => $page->where('products.0.price_retail', 180000.5)->where('products.0.created_by', 'Демо Продажи'));
        $this->assertDatabaseHas('audit_events', ['action' => 'product.created', 'user_id' => $this->manager->id]);
    }

    public function test_validation_messages_repeat_legacy_texts(): void
    {
        $this->actingAs($this->manager)->from('/catalog')->post('/catalog', $this->payload(['name' => ' ']))->assertSessionHasErrors(['name' => 'Введите наименование']);
        $this->actingAs($this->manager)->from('/catalog')->post('/catalog', $this->payload(['category' => '']))->assertSessionHasErrors(['category' => 'Введите категорию']);
        $this->actingAs($this->manager)->from('/catalog')->post('/catalog', $this->payload(['price_retail' => -1]))->assertSessionHasErrors('price_retail');
        $this->actingAs($this->manager)->from('/catalog')->post('/catalog', $this->payload(['unit' => 'кг']))->assertSessionHasErrors('unit');
        $this->assertSame(0, Product::count());
    }

    public function test_author_edits_own_product_others_only_mark_it_for_deletion(): void
    {
        $p = $this->product(['created_by_id' => $this->manager->id, 'created_by_name' => $this->manager->name]);

        $this->actingAs($this->otherManager)->from('/catalog')->put("/catalog/{$p->id}", $this->payload(['name' => 'Подмена', 'lock_version' => 1]))->assertForbidden();
        $this->assertSame('Стул «Тест»', $p->fresh()->name);

        $same = ['name' => $p->name, 'category' => $p->category, 'price_retail' => '45000', 'price_wholesale' => '38000', 'unit' => 'шт', 'sku' => '', 'description' => ''];
        $this->actingAs($this->otherManager)->from('/catalog')->put("/catalog/{$p->id}", $same + ['delete_requested' => true, 'lock_version' => 1])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertTrue($p->delete_requested);
        $this->assertSame('Другой Менеджер', $p->delete_requested_by_name);

        $this->actingAs($this->manager)->from('/catalog')->put("/catalog/{$p->id}", $this->payload(['name' => 'Стул «Обновлённый»', 'lock_version' => 2]))->assertSessionHasNoErrors()->assertSessionHas('toast', '✅ Товар обновлён');
        $this->assertSame('Стул «Обновлённый»', $p->fresh()->name);
    }

    public function test_director_edits_any_product_and_product_without_author_is_editable(): void
    {
        $foreign = $this->product(['created_by_id' => $this->manager->id, 'created_by_name' => $this->manager->name]);
        $legacy = $this->product(['name' => 'Без автора']);

        $this->actingAs($this->director)->from('/catalog')->put("/catalog/{$foreign->id}", $this->payload(['lock_version' => 1]))->assertSessionHasNoErrors();
        $this->actingAs($this->otherManager)->from('/catalog')->put("/catalog/{$legacy->id}", $this->payload(['name' => 'Правка', 'lock_version' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('Правка', $legacy->fresh()->name);
    }

    public function test_concurrent_edit_is_rejected(): void
    {
        $p = $this->product();
        $this->actingAs($this->director)->from('/catalog')->put("/catalog/{$p->id}", $this->payload(['name' => 'Первый', 'lock_version' => 1]))->assertSessionHasNoErrors();
        $this->actingAs($this->director)->from('/catalog')->put("/catalog/{$p->id}", $this->payload(['name' => 'Второй', 'lock_version' => 1]))
            ->assertSessionHasErrors(['lock_version' => ProductService::STALE]);
        $this->assertSame('Первый', $p->fresh()->name);
    }

    public function test_only_director_or_accountant_deletes_and_deleted_product_does_not_return(): void
    {
        $p = $this->product(['created_by_id' => $this->manager->id, 'created_by_name' => $this->manager->name]);

        $this->actingAs($this->manager)->from('/catalog')->delete("/catalog/{$p->id}")->assertForbidden();
        $this->assertTrue($p->fresh()->active);

        $this->actingAs($this->director)->from('/catalog')->delete("/catalog/{$p->id}")->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->active);
        $this->get('/catalog')->assertInertia(fn (Assert $page) => $page->has('products', 0));
        $this->assertDatabaseHas('audit_events', ['action' => 'product.deactivated', 'user_id' => $this->director->id]);
    }

    public function test_order_follows_legacy_database_not_byte_order(): void
    {
        // Byte order puts «Б» (U+0411) before «а» (U+0430) and «Стол» before «стол»; the legacy database does the opposite.
        foreach ([['Б-товар', 'Б'], ['а-товар', 'а'], ['Стол', 'Ш'], ['стол', 'Ш']] as [$name, $category]) {
            $this->product(['name' => $name, 'category' => $category]);
        }
        $this->actingAs($this->director)->get('/catalog')->assertInertia(fn (Assert $page) => $page
            ->where('products', fn ($list) => collect($list)->pluck('name')->all() === ['а-товар', 'Б-товар', 'стол', 'Стол']));
    }

    public function test_roles_without_catalog_cannot_read_or_write(): void
    {
        $p = $this->product();
        $storekeeper = User::factory()->role('storekeeper')->create();
        $this->actingAs($storekeeper)->get('/catalog')->assertForbidden();
        $this->actingAs($storekeeper)->post('/catalog', $this->payload())->assertForbidden();
        $this->actingAs($storekeeper)->put("/catalog/{$p->id}", $this->payload(['lock_version' => 1]))->assertForbidden();
        $this->actingAs($storekeeper)->delete("/catalog/{$p->id}")->assertForbidden();
        $this->assertSame(1, Product::active()->count());

        $dealer = User::factory()->role('dealer_astana')->create();
        $this->actingAs($dealer)->get('/catalog')->assertOk();
    }
}
