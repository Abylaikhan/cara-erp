<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M08 «Покупатели», tab «Контрагенты»: list, legacy messages, update by id (QA-041), concurrency, channel scope (AC-11, AC-17). */
class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->role('manager')->create(['name' => 'Демо Продажи']);
    }

    private function customer(string $name, string $type = 'client', string $channel = 'office', array $attrs = []): Customer
    {
        $c = new Customer(['name' => $name, 'type' => $type] + $attrs);
        $c->forceFill(['channel' => $channel])->save();

        return $c;
    }

    private function payload(array $override = []): array
    {
        return array_merge(['name' => ' ТОО «Новый» ', 'type' => 'Юр. лицо', 'inn' => '123456789012', 'phone' => ' +7 700 000 0000 '], $override);
    }

    public function test_tab_lists_office_customers_once_per_name_in_database_order(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $first = $this->customer('ТОО «Альфа»');
        Carbon::setTestNow('2026-01-02 10:00:00');
        $this->customer('ТОО «Альфа»', 'Физ. лицо');
        $person = $this->customer('Бета Покупатель', 'Физ. лицо', 'office', ['phone' => '+7 700 000 0001']);
        $this->customer('Клиент Салона', 'client', 'salon_armada');
        Carbon::setTestNow();

        $this->actingAs($this->manager)->get('/customers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customers')
            ->where('page.title', 'Покупатели')
            ->has('persons', 2)
            ->where('persons.0.id', $person->id)
            ->where('persons.0.is_legal', false)
            ->where('persons.0.source', 'Офис (прямые продажи)')
            ->where('persons.1.id', $first->id)
            ->where('persons.1.is_legal', true));
    }

    public function test_roles_without_the_page_cannot_read_or_write(): void
    {
        $storekeeper = User::factory()->role('storekeeper')->create();
        $customer = $this->customer('Покупатель');
        $this->actingAs($storekeeper)->get('/customers')->assertForbidden();
        $this->actingAs($storekeeper)->post('/customers', $this->payload())->assertForbidden();
        $this->actingAs($storekeeper)->put("/customers/{$customer->id}", $this->payload(['lock_version' => 1]))->assertForbidden();
        $this->assertSame(1, Customer::count());
    }

    public function test_created_customer_gets_office_channel_author_and_audit(): void
    {
        $this->actingAs($this->manager)->from('/customers')->post('/customers', $this->payload())->assertRedirect('/customers')->assertSessionHasNoErrors();

        $c = Customer::sole();
        $this->assertSame(['ТОО «Новый»', 'Юр. лицо', '123456789012', '+7 700 000 0000', 'office', $this->manager->id, 'Демо Продажи'],
            [$c->name, $c->type, $c->inn, $c->phone, $c->channel, $c->created_by_id, $c->created_by_name]);
        $this->get('/customers')->assertInertia(fn (Assert $page) => $page->where('persons.0.name', 'ТОО «Новый»')->where('persons.0.inn', '123456789012'));
        $this->assertDatabaseHas('audit_events', ['action' => 'customer.created', 'subject_id' => (string) $c->id, 'user_id' => $this->manager->id]);
    }

    public function test_validation_messages_repeat_legacy_texts(): void
    {
        $this->actingAs($this->manager)->from('/customers');
        $this->post('/customers', $this->payload(['name' => '  ']))->assertSessionHasErrors(['name' => 'Укажите название/ФИО']);
        $this->post('/customers', $this->payload(['inn' => '12345']))->assertSessionHasErrors(['inn' => 'БИН должен содержать ровно 12 цифр']);
        $this->post('/customers', $this->payload(['type' => 'Физ. лицо', 'inn' => '12345678901a']))->assertSessionHasErrors(['inn' => 'ИИН должен содержать ровно 12 цифр']);
        $this->post('/customers', $this->payload(['type' => 'client']))->assertSessionHasErrors('type');
        $this->assertSame(0, Customer::count());
    }

    public function test_update_changes_exactly_the_chosen_customer_even_with_the_same_name(): void
    {
        $twin = $this->customer('Дубль', 'client');
        $chosen = $this->customer('Дубль', 'client');

        $this->actingAs($this->manager)->from('/customers')
            ->put("/customers/{$chosen->id}", $this->payload(['name' => 'Дубль Переименован', 'type' => 'Физ. лицо', 'inn' => '', 'lock_version' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['Дубль', 'client', 1], [$twin->fresh()->name, $twin->fresh()->type, $twin->fresh()->lock_version]);
        $chosen->refresh();
        $this->assertSame(['Дубль Переименован', 'Физ. лицо', null, 2, 'Демо Продажи'], [$chosen->name, $chosen->type, $chosen->inn, $chosen->lock_version, $chosen->updated_by_name]);
        $this->assertDatabaseHas('audit_events', ['action' => 'customer.updated', 'subject_id' => (string) $chosen->id]);
    }

    public function test_second_editor_gets_a_conflict_instead_of_overwriting(): void
    {
        $c = $this->customer('Покупатель');
        app(CustomerService::class)->update($c->id, ['name' => 'Изменён первым', 'type' => 'Физ. лицо', 'inn' => null, 'phone' => null], 1, $this->manager);

        $this->actingAs($this->manager)->from('/customers')
            ->put("/customers/{$c->id}", $this->payload(['name' => 'Изменён вторым', 'lock_version' => 1]))
            ->assertSessionHasErrors(['lock_version' => CustomerService::STALE]);
        $this->assertSame('Изменён первым', $c->fresh()->name);
    }

    public function test_channel_bound_account_sees_and_changes_only_its_channel(): void
    {
        // No legacy salon role has this page; give it one to prove the scope is enforced by the server, not by the menu.
        config(['erp.roles.salon_armada.pages' => ['catalog', 'sales', 'crm', 'money_salon', 'customers']]);
        $salon = User::factory()->role('salon_armada')->create();
        $office = $this->customer('ТОО «Офисный»');

        $this->actingAs($salon)->get('/customers')->assertOk()->assertInertia(fn (Assert $page) => $page->has('persons', 0));
        $this->actingAs($salon)->from('/customers')
            ->put("/customers/{$office->id}", $this->payload(['name' => 'Чужой', 'lock_version' => 1]))
            ->assertSessionHasErrors(['customer' => 'Покупатель не найден — обновите страницу.']);
        $this->assertSame('ТОО «Офисный»', $office->fresh()->name);

        $this->actingAs($salon)->from('/customers')->post('/customers', $this->payload(['name' => 'Клиент салона']))->assertSessionHasNoErrors();
        $this->assertSame('salon_armada', Customer::where('name', 'Клиент салона')->sole()->channel);
    }

    public function test_head_of_salons_must_add_a_customer_from_a_salon(): void
    {
        config(['erp.roles.salon_armada_mgr.pages' => ['catalog', 'sales', 'crm', 'money_salon', 'salon_report', 'hr', 'customers']]);
        $head = User::factory()->role('salon_armada_mgr')->create();

        $this->actingAs($head)->from('/customers')->post('/customers', $this->payload())->assertSessionHasErrors('channel');
        $this->assertSame(0, Customer::count());
    }

    public function test_legal_form_in_the_name_is_recognised_next_to_cyrillic_letters(): void
    {
        foreach (['ТОО «Синтетика»', 'ИП Тестов', 'тоо альфа', 'Компания ТОО', 'Мастер-ИП', 'СХПК Нива'] as $name) {
            $this->assertTrue(Customer::hasLegalForm($name), $name);
        }
        foreach (['Синтетический Покупатель', 'ТООшка', 'Аорта', 'ПКО', ''] as $name) {
            $this->assertFalse(Customer::hasLegalForm($name), $name);
        }
        $this->assertTrue((new Customer(['name' => 'Без формы', 'type' => 'company']))->isLegal());
        $this->assertFalse((new Customer(['name' => 'Без формы', 'type' => 'client']))->isLegal());
    }

    public function test_persons_include_customers_of_office_orders_with_their_turnover(): void
    {
        $director = User::factory()->role('director')->create();
        $own = new Customer(['name' => 'ТОО «Демо Офис»', 'type' => 'Юр. лицо']);
        $own->forceFill(['channel' => 'office'])->save();
        $named = new Customer(['name' => 'Демо Салонный', 'type' => 'Физ. лицо']);
        $named->forceFill(['channel' => 'salon_armada'])->save();
        $other = new Customer(['name' => 'Демо Другой', 'type' => 'Физ. лицо']);
        $other->forceFill(['channel' => 'salon_armada'])->save();
        foreach ([['МО-1', 'office', 'Демо Салонный', '1000'], ['МО-2', 'office', 'Демо Салонный', '500.50'], ['МО-3', 'salon_armada', 'Демо Другой', '700']] as [$num, $channel, $customer, $price]) {
            $order = new Order(['num' => $num, 'channel' => $channel, 'customer' => $customer, 'status' => 'done', 'payment_pct' => 100]);
            $order->save();
            $order->items()->create(['line_no' => 0, 'name' => 'Демо', 'qty' => '1', 'price' => $price, 'progress' => 0, 'status' => 'done', 'attrs' => []]);
        }

        // Legacy renderPersons: office customers and the names of office orders; turnover of those office orders.
        $this->actingAs($director)->get('/customers')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('persons', fn ($persons) => collect($persons)->map(fn ($p) => [$p['name'], $p['orders'], $p['turnover']])->all()
                === [['Демо Салонный', 2, 1500.5], ['ТОО «Демо Офис»', 0, 0]]));
    }
}
