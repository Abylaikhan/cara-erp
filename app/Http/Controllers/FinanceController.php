<?php

namespace App\Http\Controllers;

use App\Models\MoneyAccount;
use App\Models\Order;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M11 «Финансы», part 1 — legacy page-finance (L5447–5900): the period filter (setFinancePeriod/fillFinanceMonths,
 * L25925–26035), tab «Дебиторка» (collectAR/renderAR, L25263–25450) and tab «ДДС» (buildCFS/renderCFS, L23444–23625) with its
 * drill-down (ddCFSSection/openDrilldown). The other tabs need salaries, fixed costs and technological cards (ERP-060…062).
 * Money is in tenge: on a dollar account the tenge are in amount_fx (legacy fmtTxAmt).
 */
class FinanceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'finance'), 403);
        $orders = ChannelScope::apply(Order::query(), $request->user(), 'orders.channel')
            ->leftJoinSub(DB::table('order_items')->groupBy('order_id')->selectRaw('order_id, sum(qty * price) AS total'), 'i', 'i.order_id', '=', 'orders.id')
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->get(['orders.*', DB::raw('coalesce(i.total, 0) AS total')]);
        $months = DB::query()->fromSub(
            DB::table('money_transactions')->selectRaw('tx_date AS d')
                ->unionAll(DB::table('realizations')->selectRaw('doc_date'))
                ->unionAll(DB::table('orders')->selectRaw('coalesce(due_date, order_date)')), 'x')
            ->whereBetween('d', ['2000-01-01', '2100-12-31'])->selectRaw("to_char(min(d), 'YYYY-MM') AS lo, to_char(max(d), 'YYYY-MM') AS hi")->first();

        return Inertia::render('Finance', [
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            'dataMonths' => ['lo' => $months->lo, 'hi' => $months->hi],
            'channels' => DB::table('sales_channels')->orderBy('sort_order')->pluck('name', 'code'),
            'orders' => $orders->map(fn (Order $o) => [
                'num' => $o->num,
                'channel' => $o->channel,
                'customer' => $o->customer,
                'seller' => $o->seller,
                'date' => $o->due_date?->format('Y-m-d'),
                'created' => $o->order_date?->format('Y-m-d'),
                'payment_due' => $o->payment_due?->format('Y-m-d'),
                'payment' => $o->payment_pct,
                'status' => $o->status,
                'total' => (string) $o->getAttribute('total'),
            ])->values(),
            'accounts' => MoneyAccount::query()->orderBy('sort_order')->get()
                ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name, 'icon' => $a->icon, 'opening' => $a->opening_balance])->values(),
            'categoryNames' => DB::table('expense_categories')->pluck('name', 'code'),
            'txLabels' => collect(config('erp.money_income_categories'))->merge(config('erp.money_expense_categories'))->map(fn (array $c) => $c[0].' '.$c[1]),
            // Tab «ДДС»: every operation in tenge, in the order legacy loads them; sent when the tab is opened.
            'transactions' => Inertia::optional(fn () => $this->transactions()),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function transactions()
    {
        return DB::table('money_transactions as t')->join('money_accounts as a', 'a.id', '=', 't.account_id')
            ->orderByDesc('t.tx_date')->orderBy('t.id')
            ->get(['t.id', 't.tx_date', 't.account_id', 't.flow', 't.category', 't.description', DB::raw("CASE WHEN a.currency = 'USD' THEN coalesce(t.amount_fx, t.amount) ELSE t.amount END AS kzt")])
            ->map(fn ($t) => ['id' => $t->id, 'date' => $t->tx_date, 'account' => $t->account_id, 'flow' => $t->flow, 'category' => $t->category ?? '', 'amount' => (string) $t->kzt, 'desc' => $t->description])
            ->values();
    }
}
