<?php

namespace Tests\Feature\Payroll;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M19 «Зарплатная ведомость», part 1: the accruals and the payments the ledger sums (synthetic rows). */
class PayrollPageTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $type, string $name, string $date, string $amount, ?string $comment = null): void
    {
        DB::table('salary_accruals')->insert(['uuid' => fake()->uuid(), 'emp_name' => $name, 'type' => $type, 'accrual_date' => $date, 'amount' => $amount, 'deducted' => '1.5',
            'net' => '7.25', 'comment' => $comment, 'details' => $type === 'salary' ? '{"accruals": [{"type": "Оклад", "amount": 5}]}' : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_ledger_gets_the_accruals_and_the_payments_apart(): void
    {
        $this->record('salary', 'Борис Тест', '2031-02-28', '10.125');
        $this->record('salary', 'Анна Тест', '2031-02-28', '20');
        $this->record('payment', 'Анна Тест', '2031-03-05', '3.5', 'Выдача');

        $this->actingAs(User::factory()->role('director')->create())->get('/payroll')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payroll')
            ->has('accruals', 2)
            ->where('accruals.0.emp_name', 'Анна Тест')
            ->where('accruals.1.amount', 10.125)
            ->where('accruals.0.details.accruals.0.type', 'Оклад')
            ->has('payments', 1)
            ->where('payments.0', ['emp_name' => 'Анна Тест', 'date' => '2031-03-05', 'amount' => 3.5, 'comment' => 'Выдача']));
    }

    public function test_only_roles_with_the_page_open_it(): void
    {
        $this->get('/payroll')->assertRedirect('/login');
        $this->actingAs(User::factory()->role('hr')->create())->get('/payroll')->assertForbidden();
    }
}
