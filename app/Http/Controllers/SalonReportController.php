<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\SalonPayrollRow;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M21 «Отчёт салонов», part 1 — legacy page-salon_report (L8118–8244), initSalonReport/renderSalonReport/showSalonReportTab
 * (L16283–16610): the orders of the salons the account may see (ChannelScope) with their dates, seller, end client, total and
 * paid share; the page counts the month. The tabs «Платёжная ведомость» and «История ЗП» read the lines of the salon payroll
 * (legacy salon_payroll, loadSalonPayroll L16370) the same way for everyone who may open the page; they load with the tab. The
 * seller's salary (commissions and salaries of «Сотрудники») comes with M17 (ERP-053, ERP-059, D-13).
 */
class SalonReportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'salon_report'), 403);
        $filter = ChannelScope::filterFor($request->user());

        return Inertia::render('SalonReport', [
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            // Legacy hides the salon choice from an account of one salon.
            'salonFixed' => $filter !== null && $filter !== ChannelScope::SALONS ? $filter : null,
            'orders' => ChannelScope::apply(Order::query()->where('orders.channel', 'like', 'salon\_%'), $request->user(), 'orders.channel')
                ->leftJoinSub(DB::table('order_items')->groupBy('order_id')->selectRaw('order_id, sum(qty * price) AS total'), 'i', 'i.order_id', '=', 'orders.id')
                ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
                ->get(['orders.num', 'orders.channel', 'orders.customer', 'orders.end_client', 'orders.seller', 'orders.order_date', 'orders.due_date', 'orders.payment_pct', 'orders.status',
                    DB::raw('coalesce(i.total, 0) AS total')])
                ->map(fn (Order $o) => ['num' => $o->num, 'channel' => $o->channel, 'customer' => $o->customer, 'end_client' => $o->end_client, 'seller' => $o->seller,
                    'created' => $o->order_date?->format('Y-m-d'), 'date' => $o->due_date?->format('Y-m-d'), 'payment' => $o->payment_pct, 'status' => $o->status,
                    'total' => (string) $o->getAttribute('total')])->values(),
            // legacy loadSalonPayroll: order=year.desc,month.desc (lines of one month in the order legacy returns them)
            'payroll' => Inertia::optional(fn () => SalonPayrollRow::query()->orderByDesc('year')->orderByDesc('month')->orderBy('legacy_position')->orderBy('id')
                ->get(['employee', 'year', 'month', 'salary', 'commission', 'bonus', 'vacation', 'advance', 'total', 'remainder'])
                ->map(fn (SalonPayrollRow $r) => [
                    'employee' => $r->employee, 'year' => $r->year, 'month' => $r->month,
                    ...array_map(fn (string $f) => (float) $r->getRawOriginal($f), array_combine(SalonPayrollRow::NUMBER_FIELDS, SalonPayrollRow::NUMBER_FIELDS)),
                ])->values()),
        ]);
    }
}
