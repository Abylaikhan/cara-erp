<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Order;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M01 «Дашборд» — legacy page-dashboard (L2226–2296), showPage('dashboard') (L16555), setDashPeriod/renderDashboard
 * (L24546–24770). The server sums the migrated data by month in numeric: revenue of the realizations, supplier payments,
 * salary payments, expenses by category (with the lines of the advance reports instead of the advances themselves), money in
 * and out; the page picks the months of the chosen period. Salary accruals (M20) are not migrated yet, so «ФОТ (месяц)» and
 * the accrued bars wait for them (ADR-0002).
 */
class DashboardController extends Controller
{
    /** Legacy FOT_EXPENSE_CATS: what the payroll sheet shows (salon salaries are not in it). */
    private const FOT_CATEGORIES = ['salary', 'salary_prod_fixed', 'salary_prod_piece', 'salary_office_fixed'];

    /** Transfers between own accounts are neither income nor expense. */
    private const TRANSFERS = ['transfer_in', 'transfer_out', 'transfer'];

    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'dashboard'), 403);
        $orders = ChannelScope::apply(Order::query(), $request->user());
        // On a dollar account the amount is in dollars and amount_fx in tenge (legacy fmtTxAmt); everything here is in tenge.
        $kzt = "CASE WHEN a.currency = 'USD' THEN coalesce(t.amount_fx, t.amount) ELSE t.amount END";
        $tx = fn () => DB::table('money_transactions as t')->join('money_accounts as a', 'a.id', '=', 't.account_id');
        $byMonth = fn ($query, string $date, string $sum) => $query->selectRaw("to_char({$date}, 'YYYY-MM') AS m, sum({$sum}) AS s")->groupByRaw('1')->pluck('s', 'm');

        $materials = Material::query()->active()->orderBy('name')->orderBy('id');
        $critical = (clone $materials)->where('min_qty', '>', 0)->whereRaw('qty < min_qty * 0.6');
        $supplierPaid = $byMonth($tx()->where('t.flow', 'expense')->where('t.category', 'supplier'), 't.tx_date', $kzt);

        return Inertia::render('Dashboard', [
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            // A channel-bound account would see the realizations of its orders only (no such role has the dashboard today).
            'revenue' => $byMonth(ChannelScope::channelsFor($request->user()) === null ? DB::table('realizations')
                : DB::table('realizations')->whereIn('order_id', (clone $orders)->select('id')), 'doc_date', 'revenue'),
            // Legacy counted the statuses «production» and «ready», which the orders do not have; «В работе» is «progress».
            'ordersInWork' => (clone $orders)->where('status', 'progress')->count(),
            'ordersQueue' => (clone $orders)->where('status', 'queue')->count(),
            'stockCount' => (clone $materials)->count(),
            'criticalCount' => (clone $critical)->count(),
            'critical' => (clone $critical)->limit(8)->get(['name', 'qty', 'unit'])->map(fn (Material $m) => ['name' => $m->name, 'qty' => $m->qty, 'unit' => $m->unit]),
            'supplierPaid' => $supplierPaid,
            // Legacy: all incoming invoices with VAT minus all payments to suppliers.
            'supplierDebt' => bcsub((string) DB::table('incoming_invoices')->sum('total_with_nds'), (string) $supplierPaid->reduce(fn ($s, $v) => bcadd($s, (string) $v, 2), '0'), 2),
            'fotPaid' => $byMonth($tx()->where('t.flow', 'expense')->whereIn('t.category', self::FOT_CATEGORIES), 't.tx_date', $kzt),
            'expenses' => $this->expenses($tx(), $kzt),
            'cashIn' => $byMonth($tx()->where('t.flow', 'income')->whereNotIn('t.category', self::TRANSFERS), 't.tx_date', $kzt),
            'cashOut' => $byMonth($tx()->where('t.flow', 'expense')->where(fn ($q) => $q->whereNull('t.category')->orWhereNotIn('t.category', self::TRANSFERS)), 't.tx_date', $kzt),
        ]);
    }

    /**
     * Legacy «Расходы по категориям»: operations by category except the issue of an advance, and instead of it the lines of
     * the advance reports by what was paid. Month → label → sum. The category is named as the money page names it (legacy
     * txCatLabel); legacy printed the code here.
     *
     * @return Collection<string, array<string, string>>
     */
    private function expenses($tx, string $kzt): Collection
    {
        $labels = config('erp.money_expense_categories');
        $result = [];
        $add = function (string $m, string $label, string $sum) use (&$result) {
            $result[$m][$label] = bcadd($result[$m][$label] ?? '0', $sum, 2);
        };
        $tx->where('t.flow', 'expense')->where(fn ($q) => $q->whereNull('t.category')->orWhere('t.category', '!=', 'advance_out'))
            ->selectRaw("to_char(t.tx_date, 'YYYY-MM') AS m, t.category AS c, sum({$kzt}) AS s")->groupByRaw('1, 2')->get()
            ->each(fn ($r) => $add($r->m, $r->c === null || $r->c === '' ? 'Прочее' : (isset($labels[$r->c]) ? $labels[$r->c][0].' '.$labels[$r->c][1] : $r->c), (string) $r->s));

        $names = DB::table('expense_categories')->pluck('name', 'code');
        DB::table('advance_reports as a')->whereNotNull('a.report_date')->crossJoin(DB::raw('jsonb_array_elements(a.lines) AS l'))
            ->selectRaw("to_char(a.report_date, 'YYYY-MM') AS m, l->>'type' AS type, l->>'category' AS c, l->>'subcategory' AS sub, sum(CASE WHEN l->>'amount' ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (l->>'amount')::numeric ELSE 0 END) AS s")
            ->groupByRaw('1, 2, 3, 4')->get()
            ->each(function ($r) use ($add, $names) {
                $cat = $names[$r->c] ?? ($r->c ?: 'Прочее');
                $label = match ($r->type) {
                    'material' => 'Материалы (аванс)',
                    'supplier' => 'Оплата поставщику (аванс)',
                    default => ($r->sub ? $cat.' — '.($names[$r->sub] ?? $r->sub) : $cat).' (аванс)',
                };
                $add($r->m, $label, bcadd((string) $r->s, '0', 2));
            });

        return collect($result);
    }
}
