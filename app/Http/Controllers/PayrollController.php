<?php

namespace App\Http\Controllers;

use App\Models\SalaryAccrual;
use App\Support\Erp;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M19 «Зарплатная ведомость», part 1 — legacy page-payroll (L7707–7770), initSalaryLedgerPage/fillPayrollMonthSelects/
 * onPayrollMonthChange/renderSalaryLedger/togglePayrollCols/openPayrollRowMenu/showPayouts/renderPayouts (L13590–14090): by
 * employee for a month or a period — the opening balance, the accruals by kind, the deductions, what is to be paid, what was
 * paid out and the closing balance; the journal of payouts. Everything is summed from the payroll records as legacy loads
 * them (accruals of type salary, payments of type payment). Accruing and paying come with the payroll rules (ERP-057, D-06).
 */
class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'payroll'), 403);
        $records = fn (string $type) => SalaryAccrual::query()->where('type', $type)->orderBy('accrual_date')->orderBy('emp_name')->orderBy('id');

        return Inertia::render('Payroll', [
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            'accruals' => $records('salary')->get(['uuid', 'emp_name', 'accrual_date', 'amount', 'deducted', 'net', 'details'])
                ->map(fn (SalaryAccrual $a) => [
                    'id' => $a->uuid, 'emp_name' => $a->emp_name, 'date' => $a->accrual_date->format('Y-m-d'),
                    'amount' => (float) $a->getRawOriginal('amount'), 'deducted' => (float) $a->getRawOriginal('deducted'), 'net' => (float) $a->getRawOriginal('net'),
                    'details' => $a->details === null ? null : json_decode($a->details),
                ])->values(),
            'payments' => $records('payment')->get(['emp_name', 'accrual_date', 'amount', 'comment'])
                ->map(fn (SalaryAccrual $p) => ['emp_name' => $p->emp_name, 'date' => $p->accrual_date->format('Y-m-d'), 'amount' => (float) $p->getRawOriginal('amount'), 'comment' => $p->comment])
                ->values(),
        ]);
    }
}
