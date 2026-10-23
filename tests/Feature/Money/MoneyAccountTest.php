<?php

namespace Tests\Feature\Money;

use App\Models\MoneyAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** «Деньги» → the window «Расчётный счёт» saves (№ 37, ADR-0047): opening balances, new accounts, deletion of an empty one. */
class MoneyAccountTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $attrs = []): MoneyAccount
    {
        return MoneyAccount::query()->create(['name' => 'Касса Демо', 'type' => 'cash', 'currency' => 'KZT', 'icon' => '💵', 'opening_balance' => '0', 'sort_order' => 0, ...$attrs]);
    }

    public function test_the_opening_balance_is_saved_to_the_tiyn_under_the_version_of_the_account(): void
    {
        $acc = $this->account();
        $this->actingAs(User::factory()->role('accountant')->create())->from('/money');
        $this->put("/money/accounts/{$acc->id}", ['type' => 'cash', 'name' => ' ', 'opening' => '1', 'currency' => 'KZT', 'lock_version' => 1])->assertSessionHasErrors(['name' => 'Укажите название счёта']);
        $this->put("/money/accounts/{$acc->id}", ['type' => 'cash', 'name' => 'Касса Демо', 'opening' => '-5', 'currency' => 'KZT', 'lock_version' => 1])->assertSessionHasErrors('opening');
        $this->put("/money/accounts/{$acc->id}", ['type' => 'cash', 'name' => 'Касса Демо', 'opening' => '1.234', 'currency' => 'KZT', 'lock_version' => 1])->assertSessionHasErrors('opening');
        $this->put("/money/accounts/{$acc->id}", ['type' => 'cash', 'name' => 'Касса Демо', 'bank' => '', 'iik' => 'KZ00DEMO', 'opening' => '73211,5', 'currency' => 'KZT', 'lock_version' => 1])
            ->assertRedirect('/money')->assertSessionHasNoErrors();

        $acc->refresh();
        $this->assertSame(['73211.50', 'KZ00DEMO', null, 2], [$acc->opening_balance, $acc->iik, $acc->bank, $acc->lock_version]);
        $this->assertDatabaseHas('audit_events', ['action' => 'money_account.updated', 'subject_id' => (string) $acc->id]);
        $this->put("/money/accounts/{$acc->id}", ['type' => 'cash', 'name' => 'Другая', 'opening' => '0', 'currency' => 'KZT', 'lock_version' => 1])->assertSessionHasErrors('lock_version');

        $this->get('/money')->assertInertia(fn (Assert $page) => $page->where('accounts.0.opening', 73211.5)->where('accounts.0.openingText', '73211.50')->where('accounts.0.lock_version', 2));
    }

    public function test_an_account_with_operations_keeps_its_type_and_currency_and_is_not_deleted(): void
    {
        $acc = $this->account(['type' => 'bank', 'name' => 'Банк Демо', 'icon' => '🏦']);
        DB::table('money_transactions')->insert(['account_id' => $acc->id, 'tx_date' => '2031-01-01', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '10']);
        $this->actingAs(User::factory()->role('director')->create())->from('/money');
        $this->put("/money/accounts/{$acc->id}", ['type' => 'bank', 'name' => 'Банк Демо', 'opening' => '0', 'currency' => 'USD', 'lock_version' => 1])->assertSessionHasErrors(['type' => 'У счёта есть операции — тип и валюту не изменить']);
        $this->put("/money/accounts/{$acc->id}", ['type' => 'cash', 'name' => 'Банк Демо', 'opening' => '0', 'currency' => 'KZT', 'lock_version' => 1])->assertSessionHasErrors('type');
        $this->delete("/money/accounts/{$acc->id}", ['lock_version' => 1])->assertSessionHasErrors(['type' => 'У счёта есть операции — его нельзя удалить']);
        $this->assertModelExists($acc);
    }

    public function test_a_new_account_and_the_deletion_of_an_empty_one(): void
    {
        $this->account();
        $this->actingAs(User::factory()->role('director')->create())->from('/money');
        $this->post('/money/accounts', ['type' => 'bank', 'name' => 'Новый Банк', 'opening' => '', 'currency' => 'USD'])->assertRedirect('/money')->assertSessionHasNoErrors();
        $new = MoneyAccount::query()->where('name', 'Новый Банк')->sole();
        $this->assertSame(['bank', 'USD', '🏦', '0.00', 1], [$new->type, $new->currency, $new->icon, $new->opening_balance, $new->sort_order]);

        $this->delete("/money/accounts/{$new->id}", ['lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertModelMissing($new);
        $this->assertDatabaseHas('audit_events', ['action' => 'money_account.deleted', 'subject_id' => (string) $new->id]);
    }

    public function test_only_the_roles_with_the_page_change_accounts(): void
    {
        $acc = $this->account();
        $this->actingAs(User::factory()->role('salon_armada')->create());
        $this->post('/money/accounts', ['type' => 'cash', 'name' => 'X', 'opening' => '0', 'currency' => 'KZT'])->assertForbidden();
        $this->put("/money/accounts/{$acc->id}", ['type' => 'cash', 'name' => 'X', 'opening' => '9', 'currency' => 'KZT', 'lock_version' => 1])->assertForbidden();
        $this->delete("/money/accounts/{$acc->id}", ['lock_version' => 1])->assertForbidden();
        $this->assertSame('0.00', $acc->fresh()->opening_balance);
    }
}
