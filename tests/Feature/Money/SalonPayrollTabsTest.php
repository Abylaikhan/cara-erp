<?php

namespace Tests\Feature\Money;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M21 «Отчёт салонов», tabs «Платёжная ведомость» and «История ЗП»: the lines of the salon payroll, loaded with the tab (synthetic rows). */
class SalonPayrollTabsTest extends TestCase
{
    use RefreshDatabase;

    private function line(string $employee, int $year, int $month, string $total, ?int $position = null): void
    {
        DB::table('salon_payroll_rows')->insert(['uuid' => fake()->uuid(), 'employee' => $employee, 'year' => $year, 'month' => $month, 'salary' => '1000.5', 'commission' => '10',
            'bonus' => '0', 'vacation' => '0', 'advance' => '5', 'total' => $total, 'remainder' => '1', 'legacy_position' => $position, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function inertiaGet(string $only): TestResponse
    {
        $page = $this->get('/salon_report');
        $version = $page->viewData('page')['version'];

        return $this->get('/salon_report', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'SalonReport', 'X-Inertia-Partial-Data' => $only]);
    }

    public function test_the_payroll_is_not_sent_with_the_page_but_with_its_tab_in_the_order_legacy_lists_it(): void
    {
        $this->line('Тест Один', 2031, 1, '100');
        $this->line('Тест Один', 2031, 2, '200', 2);
        $this->line('Тест Один', 2031, 2, '300', 1);
        $this->line('Тест Два', 2030, 12, '50');
        $this->actingAs(User::factory()->role('salon_armada_mgr')->create());

        $this->get('/salon_report')->assertInertia(fn (Assert $page) => $page->missing('payroll'));
        $lines = $this->inertiaGet('payroll')->assertOk()->json('props.payroll');

        // year.desc, month.desc, then the order legacy returns the lines of one month in
        $this->assertEquals([[2031, 2, 300], [2031, 2, 200], [2031, 1, 100], [2030, 12, 50]], array_map(fn ($l) => [$l['year'], $l['month'], $l['total']], $lines));
        $this->assertEquals([1000.5, 10, 0, 0, 5, 1], [$lines[0]['salary'], $lines[0]['commission'], $lines[0]['bonus'], $lines[0]['vacation'], $lines[0]['advance'], $lines[0]['remainder']]);
    }

    public function test_the_director_has_no_page_and_no_payroll(): void
    {
        $this->actingAs(User::factory()->role('director')->create());
        $this->get('/salon_report')->assertForbidden();
    }
}
