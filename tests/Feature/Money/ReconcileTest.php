<?php

namespace Tests\Feature\Money;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M16 «Сверка с офисом»: a dealer gets only its channel's orders, their payments and its own transfers; office roles see none. */
class ReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_gets_its_orders_payments_and_transfers_only(): void
    {
        $dealer = User::factory()->role('dealer_astana')->create(['name' => 'Демо Дилер']);
        foreach ([['МО-1', 'dealer_astana', 'done'], ['МО-2', 'dealer_astana', 'queue'], ['МО-3', 'dealer_aktau', 'done']] as [$num, $channel, $status]) {
            $o = new Order(['num' => $num, 'channel' => $channel, 'customer' => 'Демо', 'status' => $status, 'payment_pct' => 0, 'due_date' => '2026-10-01']);
            $o->save();
            $o->items()->create(['line_no' => 0, 'name' => 'Демо', 'qty' => '2', 'price' => '500.25', 'progress' => 0, 'status' => $status, 'attrs' => []]);
        }
        $acc = DB::table('money_accounts')->insertGetId(['name' => 'Банк Демо', 'type' => 'bank', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
        DB::table('money_transactions')->insert([
            ['account_id' => $acc, 'tx_date' => '2026-10-02', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '300', 'order_num' => 'МО-1', 'counterparty' => null, 'description' => null],
            ['account_id' => $acc, 'tx_date' => '2026-10-03', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '200.50', 'order_num' => 'МО-1', 'counterparty' => null, 'description' => null],
            ['account_id' => $acc, 'tx_date' => '2026-10-04', 'flow' => 'income', 'category' => 'order_payment', 'amount' => '999', 'order_num' => 'МО-3', 'counterparty' => null, 'description' => 'Чужой'],
            ['account_id' => $acc, 'tx_date' => '2026-10-05', 'flow' => 'income', 'category' => 'advance_return', 'amount' => '150', 'order_num' => null, 'counterparty' => 'Демо Дилер', 'description' => 'Возврат'],
            ['account_id' => $acc, 'tx_date' => '2026-10-06', 'flow' => 'income', 'category' => 'advance_return', 'amount' => '50', 'order_num' => null, 'counterparty' => 'Другой', 'description' => 'Чужой'],
        ]);

        $this->actingAs($dealer)->get('/reconcile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reconcile')
            ->where('name', 'Демо Дилер')
            ->where('dealer', true)
            ->where('orders', fn ($o) => collect($o)->map(fn ($x) => [$x['num'], $x['total'], $x['paid']])->sortBy(0)->values()->all() === [['МО-1', '1000.500000', '500.50'], ['МО-2', '1000.500000', '0']])
            ->where('transfers', [['date' => '2026-10-05', 'desc' => 'Возврат', 'amount' => '150.00']]));
        $this->actingAs(User::factory()->role('director')->create())->get('/reconcile')->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders', []));
        $this->actingAs(User::factory()->role('manager')->create())->get('/reconcile')->assertForbidden();
    }
}
