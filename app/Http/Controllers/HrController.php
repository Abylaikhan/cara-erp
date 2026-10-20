<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeChange;
use App\Support\Erp;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M17 «Сотрудники», part 1 — legacy page-hr (L7204–7560), loadStaffFromDB (L720), showPage('hr') (L16986), renderHRKPIs/
 * renderOrgChart/showHRTab/renderHRStaff/setHRView/openEmployeeCard/openStaffChangesHistory (L21627–22600): the staff as legacy
 * loads it (legacy staff, by name), the staffing table, the cards and the table of the employees, the card of an employee
 * with its conditions of work and their history. View only: changing a card, hiring and dismissing, the PIN, the payroll
 * figures and the other tabs come with the payroll rules and the users (ERP-053…057, decisions D-06 and D-13).
 * The head of the salons gets only the employees of the salons (legacy filtered them in the browser after loading all).
 */
class HrController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Erp::canAccess($user, 'hr'), 403);
        $role = Erp::role($user->role) ?? [];
        $salonManager = ($role['is_manager'] ?? false) && ($role['channel'] ?? null) === null;
        $all = Employee::query()->orderBy('name')->orderBy('id')->get();
        $changes = EmployeeChange::query()->orderByDesc('change_date')->orderBy('id')->get()->groupBy('employee_id');
        // Legacy: an empty type is «prod» (loadStaffFromDB s.type||'prod').
        $type = fn (Employee $e) => $e->getAttribute('type') ?: 'prod';
        $shown = $salonManager ? $all->filter(function (Employee $e) {
            $comment = (string) $e->getAttribute('comment');
            $pos = mb_strtolower((string) $e->getAttribute('position'));

            return str_contains($comment, 'Армада') || str_contains($comment, 'Назарбаева') || str_contains($pos, 'салон') || (str_contains($pos, 'менеджер') && $comment !== '');
        }) : $all;
        $num = fn (Employee $e, string $f) => $e->getRawOriginal($f) === null ? null : (float) $e->getRawOriginal($f);

        return Inertia::render('Hr', [
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            // KPIs of legacy count the whole staff, whatever the head of the salons is shown
            'counts' => ['total' => $all->count(), 'prod' => $all->filter(fn ($e) => $type($e) === 'prod')->count(), 'aup' => $all->filter(fn ($e) => $type($e) === 'aup')->count()],
            'salonManager' => $salonManager,
            'canSeePin' => in_array($user->role, ['director', 'hr'], true),
            'canManageUsers' => in_array($user->role, ['director', 'hr'], true),
            'canSeeCommissions' => in_array($user->role, ['director', 'accountant'], true) || ($role['is_manager'] ?? false),
            'canDismiss' => in_array($user->role, ['director', 'hr'], true) || $salonManager,
            'employees' => $shown->map(fn (Employee $e) => [
                'id' => $e->uuid,
                'name' => $e->name,
                'pos' => $e->getAttribute('position') ?? '',
                'type' => $type($e),
                'ws' => $e->getAttribute('workshop') ?? '',
                'salary' => $num($e, 'salary') ?? 0,
                'phone' => $e->getAttribute('phone') ?? '',
                'iin' => $e->getAttribute('iin') ?? '',
                'hired' => $e->hired?->format('Y-m-d') ?? '',
                'comment' => $e->getAttribute('comment') ?? '',
                'base_salary' => $num($e, 'base_salary') ?? 0,
                'deduction_meal' => $num($e, 'deduction_meal') ?? 0,
                'deduction_opv_pct' => $num($e, 'deduction_opv_pct'),
                'deduction_osms_pct' => $num($e, 'deduction_osms_pct'),
                'deduction_so_pct' => $num($e, 'deduction_so_pct'),
                'status' => $e->getAttribute('status') ?: 'active',
                'fired_date' => $e->fired_date?->format('Y-m-d'),
                // legacy openStaffChangesHistory: staff_changes of the employee, newest first
                'changes' => ($changes->get($e->id) ?? collect())->map(fn (EmployeeChange $c) => [
                    'date' => $c->change_date->format('Y-m-d'), 'type' => $c->change_type,
                    'base_salary' => $c->getRawOriginal('base_salary') === null ? null : (float) $c->getRawOriginal('base_salary'),
                    'deduction_meal' => $c->getRawOriginal('deduction_meal') === null ? null : (float) $c->getRawOriginal('deduction_meal'),
                    'opv' => $c->getRawOriginal('deduction_opv_pct') === null ? null : (float) $c->getRawOriginal('deduction_opv_pct'),
                    'osms' => $c->getRawOriginal('deduction_osms_pct') === null ? null : (float) $c->getRawOriginal('deduction_osms_pct'),
                    'so' => $c->getRawOriginal('deduction_so_pct') === null ? null : (float) $c->getRawOriginal('deduction_so_pct'),
                    'comment' => $c->comment,
                ])->values(),
            ])->values(),
        ]);
    }
}
