<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M16 «Сверка с офисом», part 1 — legacy page-reconcile (L7138–7199), setReconcilePeriod/getReconcileDateFilter/
 * renderReconcile (L15728–15962): the orders of the account's channel, shipped (main debt) and in work (50 % prepayment), the
 * payments by order number and the money handed to the office. Legacy loaded every money operation of the company into the
 * dealer's browser; here the server sends only the payments of the channel's orders and the account's own transfers.
 * An office account has no channel: legacy shows it no orders, so does this page. Amounts are in tenge.
 */
class ReconcileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Erp::canAccess($user, 'reconcile'), 403);
        $role = Erp::role($user->role) ?? [];
        $channel = Erp::channelOf($user);
        $orders = $channel === null ? collect() : Order::query()->where('orders.channel', $channel)
            ->leftJoinSub(DB::table('order_items')->groupBy('order_id')->selectRaw('order_id, sum(qty * price) AS total'), 'i', 'i.order_id', '=', 'orders.id')
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->get(['orders.num', 'orders.customer', 'orders.status', 'orders.due_date', DB::raw('coalesce(i.total, 0) AS total')]);
        $kzt = "CASE WHEN a.currency = 'USD' THEN coalesce(t.amount_fx, t.amount) ELSE t.amount END";
        $tx = fn () => DB::table('money_transactions as t')->join('money_accounts as a', 'a.id', '=', 't.account_id');
        // Legacy dealerPayments: income operations whose order number is one of the channel's orders.
        $paid = $orders->isEmpty() ? collect() : $tx()->where('t.flow', 'income')->whereIn('t.order_num', $orders->pluck('num'))
            ->groupBy('t.order_num')->selectRaw("t.order_num, sum({$kzt}) AS s")->pluck('s', 'order_num');
        // Legacy transfers: a salon's cash expenses «в офис», otherwise returns of advances in the account's name.
        $cash = isset($role['cash_account']) ? DB::table('legacy_id_map')->where('source_table', 'money_accounts')->where('source_key', $role['cash_account'])->value('target_id') : null;
        $transfers = $cash !== null
            ? $tx()->where('t.account_id', $cash)->where('t.flow', 'expense')->where(fn ($q) => $q->whereRaw("lower(coalesce(t.description, '')) LIKE '%офис%'")->orWhere('t.category', 'transfer'))
            : $tx()->where('t.flow', 'income')->where('t.category', 'advance_return')->where('t.counterparty', $user->name);

        return Inertia::render('Reconcile', [
            'name' => $user->name,
            'dealer' => str_starts_with((string) $channel, 'dealer_'),
            'cash' => $cash !== null,
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            'orders' => $orders->map(fn (Order $o) => ['num' => $o->num, 'customer' => $o->customer, 'status' => $o->status, 'date' => $o->due_date?->format('Y-m-d'),
                'total' => (string) $o->getAttribute('total'), 'paid' => (string) ($paid[$o->num] ?? '0')])->values(),
            'transfers' => $transfers->orderByDesc('t.tx_date')->orderBy('t.id')
                ->get(['t.tx_date', 't.description', DB::raw("{$kzt} AS kzt")])
                ->map(fn ($t) => ['date' => $t->tx_date, 'desc' => $t->description ?? '', 'amount' => (string) $t->kzt])->values(),
        ]);
    }
}
