<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeChange;
use App\Models\EmployeeSalaryHistory;
use App\Models\StaffDepartment;
use App\Models\StaffPosition;
use App\Models\User;
use App\Services\UserAccountService;
use App\Support\Erp;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M17 «Сотрудники», part 1 — legacy page-hr (L7204–7560), loadStaffFromDB (L720), showPage('hr') (L16986), renderHRKPIs/
 * renderOrgChart/showHRTab/renderHRStaff/setHRView/openEmployeeCard/openStaffChangesHistory (L21627–22600): the staff as legacy
 * loads it (legacy staff, by name), the staffing table, the cards and the table of the employees, the card of an employee
 * with its conditions of work and their history, the salaries and commissions of the salons' staff with their history
 * (renderSalonCommissionsSettings/showSalaryHistory). View only: changing a card, hiring and dismissing, the PIN, the payroll
 * figures and the other tabs come with the payroll rules and the users (ERP-053…057, decisions D-06 and D-13).
 * The head of the salons gets only the employees of the salons (legacy filtered them in the browser after loading all); the
 * commissions and the history of salaries go only to the roles that see «Комиссии салонов» (legacy loaded them for all).
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
        // The commission % and base and the history of salaries are shown by «Комиссии салонов» only (legacy showSalaryHistory:
        // staff_salary_history of the employee, order=valid_from.desc): only the roles that see that tab get them.
        $canSeeCommissions = in_array($user->role, ['director', 'accountant'], true) || ($role['is_manager'] ?? false);
        $history = $canSeeCommissions ? EmployeeSalaryHistory::query()->orderByDesc('valid_from')->orderBy('id')->get()->groupBy('employee_id') : collect();
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
            'canManageUsers' => UserAccountService::canManage($user),
            // «Пользователи» (legacy renderUsersTable): the accounts in the order of the built-in roles, then the others; never a
            // password. Only the director and HR get them.
            'accounts' => UserAccountService::canManage($user) ? $this->accounts() : [],
            'roleTemplates' => UserAccountService::canManage($user)
                ? collect(config('erp.roles'))->map(fn (array $r) => ['label' => $r['label'], 'channel' => $r['channel'] ?? '', 'pages' => $r['pages']])->all() : [],
            'canSeeCommissions' => $canSeeCommissions,
            'canDismiss' => in_array($user->role, ['director', 'hr'], true) || $salonManager,
            'canOpenPayroll' => Erp::canAccess($user, 'payroll'),
            // «Штатное расписание» (ADR-0051): the directory, for those who see the tab
            'canEditStructure' => ! $salonManager,
            'staffDepts' => $salonManager ? [] : StaffDepartment::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (StaffDepartment $d) => ['id' => $d->id, 'name' => $d->name, 'icon' => $d->icon, 'lock_version' => $d->lock_version])->values(),
            'staffPositions' => $salonManager ? [] : StaffPosition::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (StaffPosition $p) => ['id' => $p->id, 'deptId' => $p->department_id, 'title' => $p->title, 'grade' => $p->grade, 'salaryMin' => $p->salary_min,
                    'salaryMax' => $p->salary_max, 'slots' => $p->slots, 'wageType' => $p->wage_type, 'lock_version' => $p->lock_version])->values(),
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
                // «Комиссии салонов» (legacy loadStaffFromDB: commission_pct||0, commission_method||'sales'), for its roles only
                ...($canSeeCommissions ? [
                    'commissionPct' => $num($e, 'commission_pct') ?? 0,
                    'commissionMethod' => $e->getAttribute('commission_method') ?: 'sales',
                    'salaryHistory' => ($history->get($e->id) ?? collect())->map(fn (EmployeeSalaryHistory $h) => [
                        'from' => $h->valid_from->format('Y-m-d'), 'salary' => (float) ($h->getRawOriginal('salary') ?? 0),
                        'pct' => (float) ($h->getRawOriginal('commission_pct') ?? 0), 'note' => $h->note,
                    ])->values(),
                ] : []),
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

    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        $order = array_flip(array_keys(config('erp.roles')));

        return User::query()->orderBy('id')->get()
            ->sortBy(fn (User $u) => [$u->role !== null && isset($order[$u->login]) ? 0 : 1, $order[$u->login] ?? 0, $u->id])
            ->map(fn (User $u) => [
                'id' => $u->id, 'login' => $u->login, 'name' => $u->name, 'label' => $u->sidebarCard()['roleLabel'] === '—' ? '' : $u->sidebarCard()['roleLabel'],
                'channel' => (string) $u->channel, 'pages' => UserAccountService::currentPages($u), 'active' => $u->is_active, 'lock_version' => $u->lock_version,
                // legacy: «код + база» — a built-in role with its record, «база» — a record alone, «встроенный» — the role alone
                'source' => $u->role === null ? 'база' : ($u->pages !== null || $u->role_label !== null ? 'код + база' : 'встроенный'),
            ])->values()->all();
    }
}
