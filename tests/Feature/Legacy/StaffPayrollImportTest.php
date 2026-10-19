<?php

namespace Tests\Feature\Legacy;

use App\Legacy\Importers\EmployeeImporter;
use App\Legacy\Importers\PieceWorkImporter;
use App\Legacy\Importers\SalaryAccrualImporter;
use App\Legacy\Importers\SalonPayrollImporter;
use App\Legacy\LegacyRejected;
use App\Models\Employee;
use App\Models\PieceWorkItem;
use App\Models\SalaryAccrual;
use App\Models\SalaryAccrualChange;
use App\Models\SalonPayrollRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** legacy staff, salary_accruals, wage_items, salon_payroll (+ their histories) → M17–M21 records (synthetic rows only). */
class StaffPayrollImportTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '20260101T000000Z';

    private const E1 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa01';

    private const E2 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa02';

    private const A1 = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb01';

    private const A2 = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb02';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');
        DB::table('legacy.snapshots')->insert(['id' => self::SNAPSHOT, 'taken_at' => now(), 'manifest' => '{}']);
    }

    /** @param list<array<string, mixed>|string> $rows a string is inserted as raw JSON (numbers PHP would round) */
    private function legacy(string $table, array $rows): void
    {
        DB::table('legacy.rows')->where('table_name', $table)->delete();
        foreach ($rows as $i => $row) {
            DB::table('legacy.rows')->insert(['snapshot_id' => self::SNAPSHOT, 'table_name' => $table, 'row_no' => $i + 1, 'data' => is_string($row) ? $row : json_encode($row)]);
        }
    }

    private function staff(array $override = []): array
    {
        return $override + ['id' => self::E1, 'name' => 'Тестов Тест', 'pos' => 'Сборщик', 'type' => 'prod', 'ws' => 'Цех 1', 'status' => 'active', 'active' => true,
            'hired' => '2031-03-01', 'phone' => '', 'iin' => '', 'pin' => 1234, 'fired' => null, 'fired_date' => null, 'salary' => 151313, 'base_salary' => 151313,
            'commission_method' => 'sales', 'commission_pct' => 0, 'deduction_meal' => 0, 'deduction_opv_fixed' => 0, 'deduction_opv_pct' => 10,
            'deduction_osms_fixed' => 0, 'deduction_osms_pct' => 2, 'deduction_so_pct' => 3.5, 'piece_goal' => 400, 'comment' => null,
            'created_at' => '2031-03-01T08:00:00.123456+00:00'];
    }

    private function seedAll(): void
    {
        $this->legacy('staff', [$this->staff(), $this->staff(['id' => self::E2, 'name' => 'Офисов Офис', 'type' => '', 'hired' => '', 'deduction_opv_pct' => null])]);
        $this->legacy('staff_salary_history', [['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccc01', 'staff_id' => self::E1, 'salary' => 161717, 'commission_pct' => 1.5,
            'valid_from' => '2031-06-01', 'note' => 'Повышение', 'created_at' => '2031-06-01T00:00:00+00:00']]);
        $this->legacy('staff_changes', [['id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddd01', 'staff_id' => self::E2, 'change_date' => '2031-07-01', 'change_type' => 'salary_change',
            'base_salary' => 202929, 'deduction_meal' => 5151, 'deduction_opv_pct' => 10, 'deduction_osms_pct' => 2, 'deduction_so_pct' => 3.5, 'comment' => 'Новый оклад',
            'created_at' => '2031-07-01T00:00:00+00:00']]);
        $this->legacy('salary_accruals', [
            '{"id": "'.self::A1.'", "emp_name": "Тестов Тест", "type": "salary", "date": "2031-06-30", "amount": 123456.123456789012345, "deducted": 12345.67,
              "net": 111110.453456789012345, "details": {"deductions": {"opv": 1.10}, "accruals": [{"sum": 434.99999999999994}]}, "comment": "Начисление ЗП",
              "created_at": "2031-06-30T10:00:00.271828+00:00"}',
            ['id' => self::A2, 'emp_name' => 'Уволенный Человек', 'type' => 'payment', 'date' => '2031-07-05', 'amount' => 53737, 'deducted' => 0, 'net' => 0,
                'details' => null, 'comment' => null, 'created_at' => '2031-07-05T10:00:00+00:00'],
        ]);
        $this->legacy('salary_accruals_history', [['id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeee01', 'accrual_id' => self::A1, 'emp_name' => 'Тестов Тест', 'date' => '2031-06-30',
            'amount' => 121919, 'deducted' => 12121, 'net' => 109798, 'details' => ['deductions' => []], 'comment' => 'до правки', 'changed_by' => 'director',
            'changed_at' => '2031-07-01T09:00:00+00:00']]);
        $this->legacy('wage_items', [
            ['id' => 'ffffffff-ffff-4fff-8fff-ffffffffff01', 'date' => '2031-06-10', 'emp_name' => 'Тестов Тест', 'workshop' => 'Цех 1', 'op' => 'Сборка', 'order_num' => 'T-001',
                'qty' => 3, 'rate' => 1517.25, 'created_at' => '2031-06-10T00:00:00+00:00'],
            ['id' => 'ffffffff-ffff-4fff-8fff-ffffffffff02', 'date' => 'вчера', 'emp_name' => 'Тестов Тест', 'workshop' => 'Цех 1', 'op' => 'Покраска', 'order_num' => 'T-002',
                'qty' => 1, 'rate' => 733, 'created_at' => '2031-06-11T00:00:00+00:00'],
        ]);
        $this->legacy('salon_payroll', [['id' => 'faaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa01', 'employee' => 'Салонов Салон', 'year' => 2031, 'month' => 6, 'salary' => 104141,
            'commission' => 12989, 'bonus' => 0, 'vacation' => 0, 'advance' => 31313, 'total' => 117130, 'remainder' => 85817, 'created_at' => '2031-07-01T00:00:00+00:00']]);
    }

    private function importAll(): array
    {
        return [
            app(EmployeeImporter::class)->import(self::SNAPSHOT),
            app(SalaryAccrualImporter::class)->import(self::SNAPSHOT),
            app(PieceWorkImporter::class)->import(self::SNAPSHOT),
            app(SalonPayrollImporter::class)->import(self::SNAPSHOT),
        ];
    }

    public function test_records_are_copied_exactly_and_without_the_pin(): void
    {
        $this->seedAll();
        [$staff, $accruals, $wages, $salon] = $this->importAll();

        $this->assertSame([2, 2, 0, 1, 1, 1], [$staff['legacy_staff'], $staff['created'], $staff['removed'], $staff['salary_history'], $staff['changes'], $staff['prod']]);
        $this->assertSame([2, 2, 1, 1, 1], [$accruals['legacy_rows'], $accruals['created'], $accruals['history'], $accruals['salary'], $accruals['payment']]);
        $this->assertSame([2, 1], [$wages['created'], $wages['not_a_date']]);
        $this->assertSame([1, 1], [$salon['created'], $salon['months']]);
        $this->assertFalse(Schema::hasColumn('employees', 'pin'));

        $e1 = Employee::where('uuid', self::E1)->first();
        $this->assertSame(['2031-03-01', 'Цех 1', null, null], [$e1->hired->format('Y-m-d'), $e1->workshop, $e1->phone, $e1->iin]);
        $this->assertSame(['3.5', 12], [rtrim($e1->getRawOriginal('deduction_so_pct'), '0'), strlen(strrchr($e1->getRawOriginal('deduction_so_pct'), '.')) - 1]);
        $this->assertSame('161717.000000000000', $e1->salaryHistory()->first()->getRawOriginal('salary'));
        $e2 = Employee::where('uuid', self::E2)->first();
        $this->assertSame([null, null, null], [$e2->type, $e2->hired, $e2->getRawOriginal('deduction_opv_pct')]);
        $this->assertSame('salary_change', $e2->changes()->first()->change_type);

        $a1 = SalaryAccrual::where('uuid', self::A1)->first();
        $this->assertSame(['123456.123456789012345', '111110.453456789012345'], [$a1->getRawOriginal('amount'), $a1->getRawOriginal('net')]);
        $this->assertSame(DB::scalar("SELECT data->>'details' FROM legacy.rows WHERE table_name = 'salary_accruals' AND row_no = 1"), $a1->details);
        $this->assertStringContainsString('434.99999999999994', $a1->details);
        $this->assertSame('271828', $a1->created_at->format('u'));
        $this->assertSame($a1->id, SalaryAccrualChange::first()->salary_accrual_id);
        $this->assertNull(SalaryAccrual::where('uuid', self::A2)->value('details'));

        $this->assertSame(['2031-06-10', 'вчера'], PieceWorkItem::orderBy('order_num')->pluck('work_date')->all());
        $this->assertSame([2031, 6], [SalonPayrollRow::first()->year, SalonPayrollRow::first()->month]);
        $this->assertSame(10, DB::table('legacy_id_map')->whereIn('source_table', ['staff', 'staff_salary_history', 'staff_changes', 'salary_accruals', 'salary_accruals_history', 'wage_items', 'salon_payroll'])->count());
        $this->assertSame(4, DB::table('audit_events')->where('action', 'legacy.imported')->count());
    }

    public function test_a_second_import_changes_nothing_and_deletions_in_legacy_follow(): void
    {
        $this->seedAll();
        $this->importAll();
        [$staff, $accruals, $wages, $salon] = $this->importAll();
        $this->assertSame([0, 0, 0, 0], [$staff['created'] + $staff['updated'], $accruals['created'] + $accruals['updated'], $wages['created'] + $wages['updated'], $salon['created'] + $salon['updated']]);
        $this->assertSame([2, 2, 2, 1], [$staff['unchanged'], $accruals['unchanged'], $wages['unchanged'], $salon['unchanged']]);

        // legacy deletes the corrected accrual (its earlier version stays) and fires nobody but deletes the second employee
        $this->legacy('salary_accruals', [['id' => self::A2, 'emp_name' => 'Уволенный Человек', 'type' => 'payment', 'date' => '2031-07-05', 'amount' => 53737, 'deducted' => 0,
            'net' => 0, 'details' => null, 'comment' => 'исправлено', 'created_at' => '2031-07-05T10:00:00+00:00']]);
        $this->legacy('staff', [$this->staff()]);
        $this->legacy('staff_changes', []);
        $staff = app(EmployeeImporter::class)->import(self::SNAPSHOT);
        $accruals = app(SalaryAccrualImporter::class)->import(self::SNAPSHOT);

        $this->assertSame([2, 1], [$staff['removed'], Employee::count()]);
        $this->assertSame([1, 1], [$accruals['removed'], $accruals['updated']]);
        $this->assertSame('исправлено', SalaryAccrual::sole()->comment);
        $this->assertNull(SalaryAccrualChange::sole()->salary_accrual_id);
        $this->assertSame(3, DB::table('audit_events')->where('action', 'legacy.removed')->count());
        $this->assertSame(0, DB::table('legacy_id_map')->where('source_table', 'salary_accruals')->where('source_key', self::A1)->count());
    }

    public function test_a_bad_set_is_refused_whole(): void
    {
        $this->seedAll();
        $this->legacy('staff', [$this->staff(), $this->staff(['id' => self::E2, 'name' => 'Второй', 'type' => 'boss', 'hired' => '01.02.2031'])]);
        $this->legacy('salary_accruals', [['id' => self::A1, 'emp_name' => 'Тестов Тест', 'type' => 'salary', 'date' => '2031-06-30', 'amount' => 1e20, 'deducted' => 0,
            'net' => 0, 'details' => 'текст', 'created_at' => '2031-06-30T10:00:00+00:00']]);

        try {
            app(EmployeeImporter::class)->import(self::SNAPSHOT);
            $this->fail('staff accepted');
        } catch (LegacyRejected $e) {
            $this->assertSame(['staff row 2: unknown type', 'staff row 2: hired is not YYYY-MM-DD'], $e->problems());
        }
        try {
            app(SalaryAccrualImporter::class)->import(self::SNAPSHOT);
            $this->fail('accruals accepted');
        } catch (LegacyRejected $e) {
            $this->assertSame(['salary_accruals row 1: amount is not a number that fits', 'salary_accruals row 1: details is not an object'], $e->problems());
        }
        $this->assertSame([0, 0], [Employee::count(), SalaryAccrual::count()]);
    }

    public function test_control_totals_reconcile_the_imported_tables(): void
    {
        $this->seedAll();
        $this->importAll();
        $tables = ['staff', 'staff_salary_history', 'staff_changes', 'salary_accruals', 'salary_accruals_history', 'wage_items', 'salon_payroll'];
        $manifest = ['tables' => array_map(fn (string $t) => ['table' => $t, 'rows' => DB::table('legacy.rows')->where('table_name', $t)->count()], $tables)];
        DB::table('legacy.snapshots')->where('id', self::SNAPSHOT)->update(['manifest' => json_encode($manifest)]);
        Artisan::call('legacy:control-totals', ['--snapshot' => self::SNAPSHOT]);
        $out = Artisan::output();
        foreach (['M17 Сотрудники', 'M17 История окладов, изменения условий', 'M20 Начисления и выплаты', 'M20 Прежние версии начислений', 'M18 Сдельная оплата', 'M21 Ведомость салонов'] as $module) {
            $this->assertMatchesRegularExpression('/'.preg_quote($module, '/').'.*совпадает/u', $out);
        }
    }
}
