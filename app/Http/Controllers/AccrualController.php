<?php

namespace App\Http\Controllers;

use App\Models\SalaryAccrual;
use App\Support\Erp;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M20 «Начисления ЗП», part 1 — legacy page-accruals (L7768–7996), initAccrualsPage/initSalaryLedgerPage/renderAccrualsList
 * (L13214–13258, L13381–13410): the journal of the salary accruals (type salary, by date and employee) with the breakdown that
 * legacy reads from `details`. Payments are not on this page. Adding, changing, the salary by the timesheet, vacation and sick
 * pay come with the payroll rules (ERP-056, D-06).
 */
class AccrualController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'accruals'), 403);

        return Inertia::render('Accruals', [
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            'accruals' => SalaryAccrual::query()->where('type', 'salary')->orderBy('accrual_date')->orderBy('emp_name')->orderBy('id')
                ->get(['uuid', 'emp_name', 'accrual_date', 'amount', 'deducted', 'net', 'details'])
                ->map(fn (SalaryAccrual $a) => [
                    'id' => $a->uuid, 'emp_name' => $a->emp_name, 'date' => $a->accrual_date->format('Y-m-d'),
                    'amount' => (float) $a->getRawOriginal('amount'), 'deducted' => (float) $a->getRawOriginal('deducted'), 'net' => (float) $a->getRawOriginal('net'),
                    'details' => $a->details === null ? null : json_decode($a->details),
                ])->values(),
        ]);
    }
}
