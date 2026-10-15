<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M21 «Отчёт салонов», part 1 — legacy page-salon_report (L8118–8244), initSalonReport/renderSalonReport/showSalonReportTab
 * (L16283–16610): the orders of the salons the account may see (ChannelScope) with their dates, seller, end client, total and
 * paid share; the page counts the month. The seller's salary (commissions and salaries of «Сотрудники»), the pay sheet and the
 * salary history come with M17/M19 (ERP-053, ERP-059, D-13).
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
        ]);
    }
}
