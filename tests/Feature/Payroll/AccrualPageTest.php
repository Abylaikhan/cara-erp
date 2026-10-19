<?php

namespace Tests\Feature\Payroll;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M20 «Начисления ЗП», part 1: the journal of the salary accruals (synthetic rows). */
class AccrualPageTest extends TestCase
{
    use RefreshDatabase;

    private function accrual(string $uuid, string $name, string $type, string $date, string $amount, ?string $details = null): void
    {
        DB::table('salary_accruals')->insert(['uuid' => $uuid, 'emp_name' => $name, 'type' => $type, 'accrual_date' => $date, 'amount' => $amount, 'deducted' => '100.5',
            'net' => '1.25', 'details' => $details, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_journal_lists_the_accruals_by_date_and_name_without_payments(): void
    {
        $this->accrual('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'Борис Тестов', 'salary', '2031-02-28', '2000');
        $this->accrual('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'Анна Тестова', 'salary', '2031-02-28', '1000.125', '{"accruals": [{"type": "Оклад", "amount": 900}], "deductions": []}');
        $this->accrual('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3', 'Анна Тестова', 'salary', '2031-01-31', '500');
        $this->accrual('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa4', 'Анна Тестова', 'payment', '2031-02-05', '700');

        $director = User::factory()->role('director')->create();
        $this->actingAs($director)->get('/accruals')->assertInertia(fn (Assert $page) => $page
            ->component('Accruals')
            ->has('accruals', 3)
            ->where('accruals.0.date', '2031-01-31')
            ->where('accruals.1.emp_name', 'Анна Тестова')
            ->where('accruals.2.emp_name', 'Борис Тестов')
            ->where('accruals.1.amount', 1000.125)
            ->where('accruals.1.details.accruals.0.type', 'Оклад')
            ->where('accruals.2.details', null)
            ->has('today'));
    }

    public function test_only_roles_with_the_page_open_it(): void
    {
        $this->get('/accruals')->assertRedirect('/login');
        $this->actingAs(User::factory()->role('hr')->create())->get('/accruals')->assertForbidden();
        $this->actingAs(User::factory()->role('director')->create())->get('/accruals')->assertOk();
    }
}
