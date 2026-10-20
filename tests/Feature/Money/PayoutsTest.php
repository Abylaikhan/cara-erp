<?php

namespace Tests\Feature\Money;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** «👥 Кому выдано» (reference c54cf25): the payroll payments linked to a salary expense — by the operation, the document number, the sum. */
class PayoutsTest extends TestCase
{
    use RefreshDatabase;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cash = DB::table('money_accounts')->insertGetId(['name' => 'Касса ЗП Демо', 'type' => 'cash', 'currency' => 'KZT', 'opening_balance' => 0, 'sort_order' => 0]);
    }

    private function tx(string $date, string $amount, string $desc, ?string $legacy = null): int
    {
        $id = DB::table('money_transactions')->insertGetId(['account_id' => $this->cash, 'tx_date' => $date, 'flow' => 'expense', 'category' => 'salary', 'amount' => $amount, 'description' => $desc]);
        if ($legacy) {
            DB::table('legacy_id_map')->insert(['source_table' => 'money_tx', 'source_key' => $legacy, 'target_table' => 'money_transactions', 'target_id' => $id, 'snapshot_id' => '20260101T000000Z']);
        }

        return $id;
    }

    private function payment(string $name, string $date, string $amount, ?string $comment = null, ?string $txId = null): void
    {
        DB::table('salary_accruals')->insert(['uuid' => fake()->uuid(), 'emp_name' => $name, 'type' => 'payment', 'accrual_date' => $date, 'amount' => $amount, 'deducted' => 0, 'net' => 0,
            'comment' => $comment, 'details' => $txId ? json_encode(['tx_id' => $txId]) : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_payments_are_linked_by_the_operation_the_number_or_the_sum(): void
    {
        $byId = $this->tx('2031-03-01', '300', 'Сотрудники', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc');
        $this->payment('Борис', '2031-03-01', '100', null, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc');
        $this->payment('Анна', '2031-03-01', '250', 'Расход из кассы 77');
        $byNum = $this->tx('2031-03-01', '250', 'Расход из кассы 77');
        $bySum = $this->tx('2031-03-02', '150.5', 'Сотрудники');
        $this->payment('Вера', '2031-03-02', '100.25');
        $this->payment('Глеб', '2031-03-02', '50.25');
        $none = $this->tx('2031-03-03', '999', 'Сотрудники');
        $this->payment('Дина', '2031-03-03', '10');
        $this->payment('Ева', '2031-03-01', '5', null, null);
        DB::table('salary_accruals')->insert(['uuid' => fake()->uuid(), 'emp_name' => 'Начисление', 'type' => 'salary', 'accrual_date' => '2031-03-02', 'amount' => 1, 'deducted' => 0, 'net' => 0,
            'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->role('director')->create());

        $get = fn (int $id) => $this->getJson("/money/payouts/{$id}")->assertOk()->json();
        $this->assertEquals(['how' => 'id', 'total' => 100, 'rows' => [['emp_name' => 'Борис', 'comment' => '', 'amount' => 100]]], $get($byId));
        $this->assertSame(['num', ['Анна']], [$get($byNum)['how'], array_column($get($byNum)['rows'], 'emp_name')]);
        $this->assertSame(['sum', ['Вера', 'Глеб']], [$get($bySum)['how'], array_column($get($bySum)['rows'], 'emp_name')]);
        $this->assertSame('date', $get($none)['how']);
        $this->getJson('/money/payouts/999999')->assertNotFound();
    }

    public function test_roles_without_the_money_page_get_nothing(): void
    {
        $id = $this->tx('2031-03-01', '1', 'Сотрудники');
        $this->actingAs(User::factory()->role('salon_armada')->create())->getJson("/money/payouts/{$id}")->assertForbidden();
    }
}
