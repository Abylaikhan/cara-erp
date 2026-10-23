<?php

namespace App\Http\Controllers;

use App\Models\MoneyAccount;
use App\Models\Order;
use App\Services\Finance\PlExpenses;
use App\Services\Finance\PlReport;
use App\Services\Finance\PlRevenue;
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
            // «📊 P&L» for the period of the page (ADR-0052): asked again when the period changes
            'pl' => Inertia::optional(fn () => $this->pl($request)),
            // «Отчёт о прибылях и убытках — помесячно» of a year (legacy renderMonthlyPL)
            'plMonthly' => Inertia::optional(fn () => $this->plMonthly($request)),
            // «Выручка по направлениям» / «по категориям продукции» of a year (legacy renderRevenueByChannel/ByCategory)
            'plRevChannel' => Inertia::optional(fn () => ['year' => $y = $this->plYear($request, 'pl_rch_year'), 'years' => $this->plYears(), ...app(PlRevenue::class)->byChannel($y)]),
            'plExpenses' => Inertia::optional(fn () => ['year' => $y = $this->plYear($request, 'pl_exp_year'), 'years' => $this->plYears(), ...app(PlExpenses::class)->build($y)]),
            'plRevCategory' => Inertia::optional(fn () => ['year' => $y = $this->plYear($request, 'pl_rcat_year'), 'years' => $this->plYears(), ...app(PlRevenue::class)->byCategory($y)]),
            // Tab «Закупки» (legacy renderPurchasesDash, openSupplierReconciliation): incoming invoices, payments with a
            // counterparty (in tenge), suppliers and the supplier lines of the advance reports; sent when the tab is opened.
            'purchases' => Inertia::optional(fn () => $this->purchases()),
        ]);
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    private function pl(Request $request): array
    {
        $date = fn (string $k) => is_string($v = $request->query($k)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $from = $date('pl_from');
        $to = $date('pl_to');

        return ['from' => $from, 'to' => $to, ...app(PlReport::class)->build($from, $to)];
    }

    /** @return array<string, mixed> */
    private function plMonthly(Request $request): array
    {
        $year = $this->plYear($request, 'pl_year');

        return ['year' => $year, 'years' => $this->plYears(), 'months' => app(PlReport::class)->monthly($year, now(config('erp.business_timezone'))->format('Y-m'))];
    }

    /** Legacy plBuildRevenueYearOptions: the years of the realizations, newest first; none — the current year. @return list<string> */
    private function plYears(): array
    {
        return app(PlReport::class)->years() ?: [now(config('erp.business_timezone'))->format('Y')];
    }

    /** The year chosen before (the query), else the current year, else the newest with realizations. */
    private function plYear(Request $request, string $param): string
    {
        $years = $this->plYears();
        $current = now(config('erp.business_timezone'))->format('Y');
        $asked = is_string($y = $request->query($param)) ? $y : '';

        return in_array($asked, $years, true) ? $asked : (in_array($current, $years, true) ? $current : $years[0]);
    }

    private function purchases(): array
    {
        return [
            'invoices' => DB::table('incoming_invoices')->orderByDesc('date')->orderBy('id')
                ->get(['num', 'date', 'status', 'supplier_name', 'supplier_bin', 'total_with_nds'])
                ->map(fn ($i) => ['num' => $i->num, 'date' => $i->date, 'status' => $i->status, 'supplier' => $i->supplier_name, 'bin' => $i->supplier_bin, 'total' => (string) $i->total_with_nds]),
            'payments' => DB::table('money_transactions as t')->join('money_accounts as a', 'a.id', '=', 't.account_id')
                ->where('t.flow', 'expense')->where(fn ($q) => $q->whereNotNull('t.counterparty')->orWhereNotNull('t.bin'))
                ->orderByDesc('t.tx_date')->orderBy('t.id')
                ->get(['t.tx_date', 't.counterparty', 't.bin', 't.description', DB::raw("CASE WHEN a.currency = 'USD' THEN coalesce(t.amount_fx, t.amount) ELSE t.amount END AS kzt")])
                ->map(fn ($t) => ['date' => $t->tx_date, 'counterparty' => $t->counterparty, 'bin' => $t->bin, 'amount' => (string) $t->kzt, 'desc' => $t->description]),
            'suppliers' => DB::table('suppliers')->where('active', true)->orderBy('name')->orderBy('id')->get(['name', 'bin']),
            'advanceLines' => DB::table('advance_reports as a')->crossJoin(DB::raw('jsonb_array_elements(a.lines) AS l'))->whereRaw("l->>'type' = 'supplier'")
                ->orderByDesc('a.report_date')->orderBy('a.id')
                ->get(['a.report_date', 'a.employee_name', DB::raw("l->>'supplier' AS supplier"), DB::raw("l->>'desc' AS description"),
                    DB::raw("CASE WHEN l->>'amount' ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (l->>'amount')::numeric ELSE 0 END AS amount")])
                ->map(fn ($l) => ['date' => $l->report_date, 'supplier' => $l->supplier, 'amount' => (string) $l->amount, 'desc' => $l->description, 'employee' => $l->employee_name]),
        ];
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
