<?php

namespace Tests\Feature\Money;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M15 «Касса салона»: a salon sees its own cash, the head of the salons both (Армада first), other roles have no page. */
class SalonCashTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_salon_sees_its_cash_and_the_head_of_the_salons_both(): void
    {
        $cash = [];
        foreach (['armada' => 'c854b1e4-c0f7-436b-a274-5e8a59c4ef78', 'nazarbayeva' => '701d7d6d-3dda-4403-ac3c-e4a3987836ea', 'office' => '6dfefc6b-586c-4bb7-8134-1dd537573d15'] as $name => $key) {
            $cash[$name] = DB::table('money_accounts')->insertGetId(['name' => 'Касса '.$name, 'type' => 'cash', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => count($cash)]);
            DB::table('legacy_id_map')->insert(['source_table' => 'money_accounts', 'source_key' => $key, 'target_table' => 'money_accounts', 'target_id' => $cash[$name], 'snapshot_id' => '20260101T000000Z']);
        }
        DB::table('money_transactions')->insert([
            ['account_id' => $cash['nazarbayeva'], 'tx_date' => '2026-10-05', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '500', 'description' => 'Демо Н', 'counterparty' => null, 'order_num' => 'МО-1'],
            ['account_id' => $cash['armada'], 'tx_date' => '2026-10-01', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '1000.50', 'description' => null, 'counterparty' => 'Демо клиент', 'order_num' => null],
            ['account_id' => $cash['armada'], 'tx_date' => '2026-10-02', 'flow' => 'expense', 'category' => 'transfer', 'amount' => '300', 'description' => 'В офис', 'counterparty' => null, 'order_num' => null],
            ['account_id' => $cash['office'], 'tx_date' => '2026-10-03', 'flow' => 'income', 'category' => 'transfer_in', 'amount' => '300', 'description' => 'Из салона', 'counterparty' => null, 'order_num' => null],
        ]);
        $descs = fn (string $role) => collect($this->actingAs(User::factory()->role($role)->create())->get('/money_salon')->assertOk()->viewData('page')['props']['transactions'])
            ->map(fn ($t) => [$t['cash'], $t['desc']])->all();

        $this->assertSame([[0, 'В офис'], [0, 'Демо клиент']], $descs('salon_armada'));
        $this->assertSame([[0, 'Демо Н']], $descs('salon_nazarbayeva'));
        $this->assertSame([[0, 'В офис'], [0, 'Демо клиент'], [1, 'Демо Н']], $descs('salon_armada_mgr'));
        $this->actingAs(User::factory()->role('salon_armada')->create())->get('/money_salon')->assertInertia(fn (Assert $page) => $page
            ->component('MoneySalon')
            ->where('title', 'Касса — Армада')
            ->where('canCashIn', true)
            ->where('transactions.1.amount', '1000.50'));
        $this->actingAs(User::factory()->role('director')->create())->get('/money_salon')->assertForbidden();
    }
}
