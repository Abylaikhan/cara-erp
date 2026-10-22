<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M17 «Сотрудники», part 1: the staff by name, the counts, the history of conditions; the head of the salons sees the salons' people only. */
class HrPageTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $uuid, string $name, ?string $type, string $pos = '', string $comment = ''): int
    {
        return DB::table('employees')->insertGetId(['uuid' => $uuid, 'name' => $name, 'type' => $type, 'position' => $pos, 'comment' => $comment, 'active' => true,
            'salary' => '120000.5', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_staff_with_counts_and_history(): void
    {
        $b = $this->employee('dddddddd-dddd-4ddd-8ddd-aaaaaaaaaaa1', 'Борисов Тест', 'aup', 'Бухгалтер');
        $this->employee('dddddddd-dddd-4ddd-8ddd-aaaaaaaaaaa2', 'Антонов Тест', null, 'Столяр');
        $this->employee('dddddddd-dddd-4ddd-8ddd-aaaaaaaaaaa3', 'Вера Салонова', 'office', 'Продавец салона', 'Армада');
        DB::table('employees')->where('id', $b)->update(['commission_pct' => '1.5', 'commission_method' => 'paid']);
        DB::table('employee_salary_history')->insert([
            ['uuid' => 'ffffffff-ffff-4fff-8fff-aaaaaaaaaaa1', 'employee_id' => $b, 'salary' => 100, 'commission_pct' => 1, 'valid_from' => '2031-01-01', 'note' => 'Первый', 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => 'ffffffff-ffff-4fff-8fff-aaaaaaaaaaa2', 'employee_id' => $b, 'salary' => 200, 'commission_pct' => 1.5, 'valid_from' => '2031-06-01', 'note' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('employee_changes')->insert([
            ['uuid' => 'eeeeeeee-eeee-4eee-8eee-aaaaaaaaaaa1', 'employee_id' => $b, 'change_date' => '2031-01-01', 'change_type' => 'hire', 'base_salary' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => 'eeeeeeee-eeee-4eee-8eee-aaaaaaaaaaa2', 'employee_id' => $b, 'change_date' => '2031-05-01', 'change_type' => 'salary_change', 'base_salary' => 200, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs(User::factory()->role('director')->create())->get('/hr')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Hr')
            ->where('counts', ['total' => 3, 'prod' => 1, 'aup' => 1])
            ->where('employees.0.name', 'Антонов Тест')
            ->where('employees.0.type', 'prod') // legacy: an empty type is «prod»
            ->where('employees.1.salary', 120000.5)
            ->where('employees.1.changes.0.date', '2031-05-01')
            ->where('employees.1.changes.1.type', 'hire')
            ->where('employees.1.commissionPct', 1.5)
            ->where('employees.1.commissionMethod', 'paid')
            ->where('employees.0.commissionMethod', 'sales') // legacy: an empty base is «% от продаж»
            ->where('employees.1.salaryHistory.0', ['from' => '2031-06-01', 'salary' => 200, 'pct' => 1.5, 'note' => null])
            ->where('employees.1.salaryHistory.1.note', 'Первый')
            ->where('canSeePin', true)
            ->where('salonManager', false)
            ->missing('employees.0.pin'));
    }

    public function test_the_head_of_the_salons_gets_the_salons_people_and_the_full_counts(): void
    {
        $this->employee('dddddddd-dddd-4ddd-8ddd-aaaaaaaaaaa1', 'Борисов Тест', 'aup', 'Бухгалтер');
        $this->employee('dddddddd-dddd-4ddd-8ddd-aaaaaaaaaaa3', 'Вера Салонова', 'office', 'Продавец', 'Назарбаева');
        $this->employee('dddddddd-dddd-4ddd-8ddd-aaaaaaaaaaa4', 'Гоша Тестов', 'office', 'Менеджер', '');

        $this->actingAs(User::factory()->role('salon_armada_mgr')->create())->get('/hr')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('salonManager', true)
            ->where('counts.total', 3)
            ->has('employees', 1)
            ->where('employees.0.name', 'Вера Салонова'));
    }

    public function test_roles_without_the_page_are_refused(): void
    {
        $this->get('/hr')->assertRedirect('/login');
        $this->actingAs(User::factory()->role('dealer_astana')->create())->get('/hr')->assertForbidden();
    }
}
