<?php

namespace Tests\Feature\Warehouse;

use App\Models\AuditEvent;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\MaterialService;
use App\Services\SupplierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M03 «Склад»: warehouses, materials directory, inline edits saved by the server, stock not edited directly, aliases, rights. */
class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storekeeper = User::factory()->role('storekeeper')->create(['name' => 'Демо Склад']);
    }

    private function material(array $attrs = []): Material
    {
        $m = new Material(array_merge(['name' => 'ЛДСП Демо 16мм', 'category' => 'ЛДСП/МДФ', 'unit' => 'лист', 'price' => '8500.00', 'min_qty' => 0, 'warehouse_code' => 'wh_plates'], $attrs));
        $m->forceFill(['qty' => $attrs['qty'] ?? '12.500'])->save();

        return $m;
    }

    public function test_directory_holds_the_nine_legacy_warehouses(): void
    {
        $this->assertSame(
            ['wh_plates', 'wh_fabric', 'wh_wood', 'wh_paint', 'wh_main', 'wh_semi', 'wh_finished', 'wh_salon_armada', 'wh_salon_naz'],
            Warehouse::query()->ordered()->pluck('code')->all(),
        );
        $this->assertSame(['Фурнитура/Крепёж', 'Крепёж Novus', 'Инструмент/Абразивы', 'Металл/Профиль', 'Прочее'], Warehouse::find('wh_main')->categories);
        $this->assertSame(['material' => 5, 'semi' => 1, 'product' => 3], Warehouse::query()->ordered()->get()->countBy('type')->all());
    }

    public function test_page_lists_active_materials_by_name_with_row_values_and_stock_value(): void
    {
        $b = $this->material(['name' => 'Бета', 'qty' => '2.005', 'price' => '100.10']);
        $a = $this->material(['name' => 'альфа', 'qty' => '3', 'price' => '0.33']);
        $this->material(['name' => 'Удалённый', 'qty' => '0'])->forceFill(['active' => false])->save();

        $this->actingAs($this->storekeeper)->get('/warehouse')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Warehouse')
            ->where('page.title', 'Склад')
            ->has('materials', 2)
            ->where('materials.0.id', $a->id)
            ->where('materials.0.value', 0.99)
            ->where('materials.1.id', $b->id)
            ->where('materials.1.qty', 2.01) // shown as legacy rounds it: Math.round(2.005 * 100) / 100 = 2.01 in JS
            ->where('materials.1.value', 201.201)
            ->where('stockValue', '202.1910')
            ->has('warehouses', 9)
            ->has('workshops', 5));
    }

    public function test_roles_without_the_page_cannot_read_or_write(): void
    {
        $manager = User::factory()->role('manager')->create();
        $m = $this->material();
        $this->actingAs($manager)->get('/warehouse')->assertForbidden();
        $this->actingAs($manager)->put("/warehouse/materials/{$m->id}", ['name' => 'Чужое', 'lock_version' => 1])->assertForbidden();
        $this->actingAs($manager)->delete("/warehouse/materials/{$m->id}")->assertForbidden();
        $this->actingAs($manager)->post('/warehouse/aliases', ['material_name' => $m->name, 'alias_name' => 'Чужой'])->assertForbidden();
        $this->assertSame('ЛДСП Демо 16мм', $m->fresh()->name);
    }

    public function test_inline_cells_are_saved_and_the_quantity_is_not_edited_directly(): void
    {
        $m = $this->material();
        $this->actingAs($this->storekeeper)->from('/warehouse');
        $this->put("/warehouse/materials/{$m->id}", ['name' => ' ЛДСП Демо 18мм ', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->put("/warehouse/materials/{$m->id}", ['price' => '9100.5', 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->put("/warehouse/materials/{$m->id}", ['min_qty' => '4', 'lock_version' => 3])->assertSessionHasNoErrors();
        $this->put("/warehouse/materials/{$m->id}", ['sku' => ' SKU-1 ', 'unit' => 'шт', 'lock_version' => 4])->assertSessionHasNoErrors();

        $m->refresh();
        $this->assertSame(['ЛДСП Демо 18мм', '9100.50', '4.000', 'SKU-1', 'шт', '12.500', 5, 'Демо Склад'],
            [$m->name, $m->price, $m->min_qty, $m->sku, $m->unit, $m->qty, $m->lock_version, $m->updated_by_name]);
        $this->assertSame(4, AuditEvent::where('action', 'material.updated')->where('subject_id', (string) $m->id)->count());

        $this->put("/warehouse/materials/{$m->id}", ['qty' => '100', 'lock_version' => 5])
            ->assertSessionHasErrors(['qty' => 'Остаток меняется только складскими документами (приход, перемещение, списание, инвентаризация).']);
        $this->put("/warehouse/materials/{$m->id}", ['name' => ' ', 'lock_version' => 5])->assertSessionHasErrors(['name' => 'Введите наименование']);
        $this->put("/warehouse/materials/{$m->id}", ['price' => '-1', 'lock_version' => 5])->assertSessionHasErrors(['price' => 'Цена не может быть отрицательной']);
        $this->assertSame('12.500', $m->fresh()->qty);
    }

    public function test_second_editor_gets_a_conflict(): void
    {
        $m = $this->material();
        app(MaterialService::class)->update($m->id, ['name' => 'Изменён первым'], 1, $this->storekeeper);

        $this->actingAs($this->storekeeper)->from('/warehouse')->put("/warehouse/materials/{$m->id}", ['name' => 'Изменён вторым', 'lock_version' => 1])
            ->assertSessionHasErrors(['lock_version' => MaterialService::STALE]);
        $this->assertSame('Изменён первым', $m->fresh()->name);
    }

    public function test_only_a_material_without_stock_is_deleted_and_it_does_not_return(): void
    {
        $withStock = $this->material();
        $empty = $this->material(['name' => 'Пустой', 'qty' => '0']);
        $this->actingAs($this->storekeeper)->from('/warehouse');

        $this->delete("/warehouse/materials/{$withStock->id}")->assertSessionHasErrors(['material' => MaterialService::IN_STOCK]);
        $this->delete("/warehouse/materials/{$empty->id}")->assertSessionHasNoErrors();
        $this->assertSame([true, false], [$withStock->fresh()->active, $empty->fresh()->active]);
        $this->get('/warehouse')->assertInertia(fn (Assert $page) => $page->has('materials', 1)->where('materials.0.id', $withStock->id));
        $this->delete("/warehouse/materials/{$empty->id}")->assertSessionHasErrors(['material' => MaterialService::NOT_FOUND]);
    }

    public function test_aliases_are_added_once_per_material_and_deleted(): void
    {
        $m = $this->material();
        $supplier = Supplier::create(['name' => 'ТОО «Демо Плита»']);
        $this->actingAs($this->storekeeper)->from('/warehouse');

        $this->post('/warehouse/aliases', ['material_name' => $m->name, 'alias_name' => ' Плита ЛДСП Демо 2800х2070 ', 'supplier_id' => $supplier->id, 'coeff' => '1.5', 'comment' => 'Демо'])
            ->assertSessionHasNoErrors();
        $alias = MaterialAlias::sole();
        $this->assertSame(['ЛДСП Демо 16мм', 'Плита ЛДСП Демо 2800х2070', 'ЛДСП/МДФ', $supplier->id, '1.500', 'Демо', 'Демо Склад'],
            [$alias->material_name, $alias->alias_name, $alias->category, $alias->supplier_id, $alias->coeff, $alias->comment, $alias->created_by_name]);

        $this->post('/warehouse/aliases', ['material_name' => $m->name, 'alias_name' => 'плита лдсп демо 2800х2070'])->assertSessionHasErrors(['alias_name' => 'Такой алиас у этого материала уже есть']);
        $this->post('/warehouse/aliases', ['material_name' => '', 'alias_name' => 'Х'])->assertSessionHasErrors(['material_name' => 'Выберите основной материал']);
        $this->post('/warehouse/aliases', ['material_name' => $m->name, 'alias_name' => ''])->assertSessionHasErrors(['alias_name' => 'Введите алиас']);
        $this->post('/warehouse/aliases', ['material_name' => 'Нет такого', 'alias_name' => 'Х'])->assertSessionHasErrors('material_name');
        $this->post('/warehouse/aliases', ['material_name' => $m->name, 'alias_name' => 'Х', 'coeff' => '0'])->assertSessionHasErrors(['coeff' => 'Коэффициент пересчёта должен быть больше нуля']);
        $this->assertSame(1, MaterialAlias::count());

        $this->get('/warehouse')->assertInertia(fn (Assert $page) => $page->has('aliases', 1)->where('aliases.0.alias_name', 'Плита ЛДСП Демо 2800х2070'));
        $this->delete("/warehouse/aliases/{$alias->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, MaterialAlias::count());
        $this->assertDatabaseHas('audit_events', ['action' => 'material_alias.deleted', 'subject_id' => (string) $alias->id]);
    }

    public function test_new_material_gets_its_warehouse_an_automatic_sku_and_no_stock(): void
    {
        $this->actingAs($this->storekeeper)->from('/warehouse');
        $this->post('/warehouse/materials', ['name' => ' ЛДСП ', 'attrs' => ' 16мм Демо-дуб ', 'category' => 'ЛДСП/МДФ', 'unit' => 'листов', 'price' => '9100.5', 'min_qty' => '5', 'sku' => ''])
            ->assertSessionHasNoErrors();

        $m = Material::sole();
        $this->assertSame(['ЛДСП 16мм Демо-дуб', 'ЛДСП/МДФ', 'wh_plates', 'листов', '9100.50', '5.000', '0.000', 'SKU-'.(9000 + $m->id), 'Демо Склад'],
            [$m->name, $m->category, $m->warehouse_code, $m->unit, $m->price, $m->min_qty, $m->qty, $m->sku, $m->created_by_name]);
        $this->assertDatabaseHas('audit_events', ['action' => 'material.created', 'subject_id' => (string) $m->id]);

        $this->post('/warehouse/materials', ['name' => 'Без склада', 'category' => 'Новая категория', 'sku' => 'S-1'])->assertSessionHasNoErrors();
        $this->assertSame([null, 'S-1', 'шт'], [Material::where('name', 'Без склада')->value('warehouse_code'), Material::where('name', 'Без склада')->value('sku'), Material::where('name', 'Без склада')->value('unit')]);
    }

    public function test_new_material_is_refused_like_legacy(): void
    {
        $this->material(['name' => 'ЛДСП Демо 16мм']);
        $this->actingAs($this->storekeeper)->from('/warehouse');

        $this->post('/warehouse/materials', ['name' => 'лдсп демо', 'attrs' => '16ММ', 'category' => 'ЛДСП/МДФ'])
            ->assertSessionHasErrors(['name' => '⚠ Позиция «лдсп демо 16ММ» уже есть на складе (без SKU). Уточните характеристики.']);
        $this->post('/warehouse/materials', ['name' => ' ', 'category' => 'ЛДСП/МДФ'])->assertSessionHasErrors(['name' => 'Введите или выберите наименование материала']);
        $this->post('/warehouse/materials', ['name' => 'Новый', 'category' => ''])->assertSessionHasErrors(['category' => 'Выберите категорию']);
        $this->post('/warehouse/materials', ['name' => 'Новый', 'category' => 'Прочее', 'qty' => '10'])->assertSessionHasErrors(['qty' => 'Начальный остаток вносится приходом на склад.']);
        $this->post('/warehouse/materials', ['name' => 'Новый', 'category' => 'Прочее', 'price' => '-5'])->assertSessionHasErrors(['price' => 'Цена не может быть отрицательной']);
        $this->assertSame(1, Material::count());
        $this->actingAs(User::factory()->role('manager')->create())->post('/warehouse/materials', ['name' => 'Чужой', 'category' => 'Прочее'])->assertForbidden();
    }

    public function test_merging_suppliers_moves_alias_suppliers(): void
    {
        $m = $this->material();
        $kept = Supplier::create(['name' => 'ТОО «Альфа»']);
        $dupe = Supplier::create(['name' => 'ТОО Альфа']);
        $alias = MaterialAlias::create(['material_name' => $m->name, 'alias_name' => 'Плита Альфа', 'supplier_id' => $dupe->id]);

        app(SupplierService::class)->merge($kept->id, [$dupe->id], $this->storekeeper);

        $this->assertSame($kept->id, $alias->fresh()->supplier_id);
    }
}
