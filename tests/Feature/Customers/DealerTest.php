<?php

namespace Tests\Feature\Customers;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\DealerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M08 «Покупатели», tab «Дилеры»: dealers are sales channels; changes persist (QA-042), lock version, delete guards, rights. */
class DealerTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = ['dealer_astana', 'dealer_aktau', 'dealer_shymkent', 'dealer_atyrau', 'dealer_aktobe'];

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->role('manager')->create(['name' => 'Демо Продажи']);
    }

    private function dealer(string $name = 'Дилер Тест', ?string $region = 'Тестград'): SalesChannel
    {
        return app(DealerService::class)->create($name, $region, $this->manager);
    }

    public function test_tab_lists_the_legacy_dealers_and_puts_new_ones_first(): void
    {
        $this->actingAs($this->manager)->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->where('dealers', fn ($list) => collect($list)->pluck('code')->all() === self::LEGACY)
            ->where('dealers.0.name', 'Дилер Астана')
            ->where('dealers.0.region', 'Астана'));

        $new = $this->dealer();
        $this->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->where('dealers', fn ($list) => collect($list)->pluck('code')->all() === [$new->code, ...self::LEGACY]));
    }

    public function test_modal_and_new_row_create_dealer_channels(): void
    {
        $this->actingAs($this->manager)->from('/customers');
        $this->post('/customers/dealers', ['name' => ' Дилер Караганда ', 'region' => 'Караганда'])->assertRedirect('/customers')->assertSessionHasNoErrors();
        $this->post('/customers/dealers', ['name' => 'Новый дилер', 'region' => '—'])->assertSessionHasNoErrors();

        $first = SalesChannel::find('dealer_1');
        $this->assertSame(['Дилер Караганда', 'Караганда', 'dealer', '📍', 9, 'Демо Продажи'],
            [$first->name, $first->region, $first->channel_group, $first->icon, $first->sort_order, $first->created_by_name]);
        $this->assertNull(SalesChannel::find('dealer_2')->region);
        $this->assertDatabaseHas('audit_events', ['action' => 'channel.created', 'subject_id' => 'dealer_1', 'user_id' => $this->manager->id]);

        $this->post('/customers/dealers', ['name' => '  '])->assertSessionHasErrors(['name' => 'Укажите название дилера']);
        $this->assertSame(10, SalesChannel::count());
    }

    public function test_inline_cells_save_name_and_region(): void
    {
        $this->actingAs($this->manager)->from('/customers');
        $this->put('/customers/dealers/dealer_astana', ['name' => 'Дилер Астана-Центр', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->put('/customers/dealers/dealer_astana', ['region' => '—', 'lock_version' => 2])->assertSessionHasNoErrors();

        $d = SalesChannel::find('dealer_astana');
        $this->assertSame(['Дилер Астана-Центр', null, 3, 'Демо Продажи'], [$d->name, $d->region, $d->lock_version, $d->updated_by_name]);
        $this->get('/customers')->assertInertia(fn (Assert $page) => $page->where('dealers.0.name', 'Дилер Астана-Центр')->where('dealers.0.region', null));
        $this->assertSame(2, AuditEvent::where('action', 'channel.updated')->count());

        $this->put('/customers/dealers/dealer_astana', ['name' => '', 'lock_version' => 3])->assertSessionHasErrors(['name' => 'Укажите название дилера']);
        $this->put('/customers/dealers/dealer_astana', ['name' => str_repeat('Д', 101), 'lock_version' => 3])->assertSessionHasErrors(['name' => 'Название дилера — не длиннее 100 символов']);
    }

    public function test_second_editor_gets_a_conflict(): void
    {
        app(DealerService::class)->update('dealer_aktau', ['name' => 'Изменён первым'], 1, $this->manager);

        $this->actingAs($this->manager)->from('/customers')
            ->put('/customers/dealers/dealer_aktau', ['name' => 'Изменён вторым', 'lock_version' => 1])
            ->assertSessionHasErrors(['lock_version' => DealerService::STALE]);
        $this->assertSame('Изменён первым', SalesChannel::find('dealer_aktau')->name);
    }

    public function test_only_dealer_channels_are_managed_here(): void
    {
        $this->actingAs($this->manager)->from('/customers')
            ->put('/customers/dealers/office', ['name' => 'Не офис', 'lock_version' => 1])
            ->assertSessionHasErrors(['dealer' => DealerService::NOT_FOUND]);
        $this->delete('/customers/dealers/salon_armada')->assertSessionHasErrors(['dealer' => DealerService::NOT_FOUND]);
        $this->assertSame(['Офис (прямые продажи)', true], [SalesChannel::find('office')->name, SalesChannel::find('salon_armada')->active]);
    }

    public function test_only_an_unused_dealer_can_be_deleted(): void
    {
        $withCustomer = $this->dealer('Дилер С Покупателем');
        (new Customer(['name' => 'Покупатель дилера', 'type' => 'client']))->forceFill(['channel' => $withCustomer->code])->save();
        $withOrder = $this->dealer('Дилер С Заказом');
        (new Order(['num' => 'МО-1', 'channel' => $withOrder->code, 'status' => 'queue', 'payment_pct' => 0]))->save();
        $unused = $this->dealer('Дилер Ошибочный');

        $this->actingAs($this->manager)->from('/customers');
        $this->delete('/customers/dealers/dealer_astana')->assertSessionHasErrors(['dealer' => DealerService::IN_USE]);
        $this->delete("/customers/dealers/{$withCustomer->code}")->assertSessionHasErrors(['dealer' => DealerService::IN_USE]);
        $this->delete("/customers/dealers/{$withOrder->code}")->assertSessionHasErrors(['dealer' => DealerService::IN_USE]);
        $this->delete("/customers/dealers/{$unused->code}")->assertSessionHasNoErrors();

        $this->assertFalse($unused->fresh()->active);
        $this->assertNotNull($unused->fresh()->deactivated_at);
        $this->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->where('dealers', fn ($list) => ! collect($list)->contains('code', $unused->code) && collect($list)->contains('code', 'dealer_astana')));
        $this->assertDatabaseHas('audit_events', ['action' => 'channel.deactivated', 'subject_id' => $unused->code]);
    }

    public function test_roles_without_the_page_cannot_manage_dealers(): void
    {
        $storekeeper = User::factory()->role('storekeeper')->create();
        $this->actingAs($storekeeper);
        $this->post('/customers/dealers', ['name' => 'Чужой'])->assertForbidden();
        $this->put('/customers/dealers/dealer_astana', ['name' => 'Чужой', 'lock_version' => 1])->assertForbidden();
        $this->delete('/customers/dealers/dealer_astana')->assertForbidden();
        $this->post('/customers/dealers/import', [])->assertForbidden();
        $this->assertSame('Дилер Астана', SalesChannel::find('dealer_astana')->name);
    }

    public function test_dealer_account_sees_only_its_channel_and_adds_no_channels(): void
    {
        config(['erp.roles.dealer_astana.pages' => ['catalog', 'sales', 'reconcile', 'customers']]);
        $dealer = User::factory()->role('dealer_astana')->create();

        $this->actingAs($dealer)->get('/customers')->assertInertia(fn (Assert $page) => $page->has('dealers', 1)->where('dealers.0.code', 'dealer_astana'));
        $this->post('/customers/dealers', ['name' => 'Ещё канал'])->assertForbidden();
        $this->from('/customers')->put('/customers/dealers/dealer_aktau', ['name' => 'Чужой', 'lock_version' => 1])
            ->assertSessionHasErrors(['dealer' => DealerService::NOT_FOUND]);
        $this->assertSame(8, SalesChannel::count());
    }

    public function test_dealer_orders_are_given_for_the_counts_of_the_tab(): void
    {
        foreach ([['МО-1', 'dealer_astana', '2026-10-05', '1000', 50], ['МО-2', 'dealer_astana', null, '300', 100], ['МО-3', 'office', '2026-10-05', '700', 0]] as [$num, $channel, $date, $price, $pct]) {
            $order = new Order(['num' => $num, 'channel' => $channel, 'customer' => 'Демо', 'status' => 'queue', 'payment_pct' => $pct, 'due_date' => $date]);
            $order->save();
            $order->items()->create(['line_no' => 0, 'name' => 'Демо', 'qty' => '2', 'price' => $price, 'progress' => 0, 'status' => 'queue', 'attrs' => []]);
        }

        $this->actingAs($this->manager)->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->where('dealerOrders', fn ($orders) => collect($orders)->sortBy('total')->values()->all() === [
                ['channel' => 'dealer_astana', 'date' => null, 'total' => 600, 'payment' => 100],
                ['channel' => 'dealer_astana', 'date' => '2026-10-05', 'total' => 2000, 'payment' => 50],
            ])
            ->has('today'));
    }
}
