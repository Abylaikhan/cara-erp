<?php

namespace App\Http\Controllers;

use App\Models\AdvanceReport;
use App\Models\MoneyAccount;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M12 «Деньги», part 1 — legacy page-money (L6002–6320): showPage('money') (L16522), renderMoneyKPIs/calcAccountStats
 * (L8671–8712), setMoneyPeriod/moneyFilteredTx/renderCashTx/renderBankAccounts/renderBankTx/renderAllTx (L9498–10330).
 * The operations are shown as legacy shows them; the page counts the totals of the chosen period as legacy does. View only:
 * new operations, editing, transfers, statement import, advances, the salon worksheet, bank reconciliation and the payment
 * calendar come with ERP-031…035 (decisions D-03, D-11).
 */
class MoneyController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'money'), 403);
        $accounts = MoneyAccount::query()->orderBy('sort_order')->get();
        // Order numbers of an operation: its own and those of order_payments (legacy txOrderNums).
        $orderPayments = DB::table('order_payments')->orderBy('id')->get(['transaction_id', 'order_num'])->groupBy('transaction_id');
        // ESF links point at the legacy id of the operation (legacy esfLinkedToSource('money_tx', id)).
        $esf = DB::table('esf_payment_links')->where('source_type', 'money_tx')
            ->join('legacy_id_map', fn ($j) => $j->on('legacy_id_map.source_key', '=', 'esf_payment_links.source_legacy_id')->where('legacy_id_map.source_table', 'money_tx'))
            ->groupBy('legacy_id_map.target_id')->havingRaw('sum(esf_payment_links.amount) > 0')
            ->selectRaw('legacy_id_map.target_id, sum(esf_payment_links.amount) AS linked')->pluck('linked', 'target_id');
        $items = DB::table('expense_categories')->pluck('name', 'id');
        // Legacy loads operations by date descending, then by id; the import keeps that order inside a day.
        $tx = DB::table('money_transactions')->orderByDesc('tx_date')->orderBy('id')->get();

        return Inertia::render('Money', [
            'accounts' => $accounts->map(fn (MoneyAccount $a) => [
                'id' => $a->id, 'name' => $a->name, 'bank' => $a->bank ?? '', 'currency' => $a->currency, 'type' => $a->type, 'icon' => $a->icon,
                'opening' => (float) $a->opening_balance,
            ])->values(),
            'transactions' => $tx->map(function ($t) use ($orderPayments, $esf, $items) {
                $orders = $t->order_num !== null ? [$t->order_num] : [];
                foreach ($orderPayments->get($t->id, collect()) as $p) {
                    if (! in_array($p->order_num, $orders, true)) {
                        $orders[] = $p->order_num;
                    }
                }

                return [
                    'id' => $t->id, 'account' => $t->account_id, 'date' => $t->tx_date, 'flow' => $t->flow, 'category' => $t->category ?? '',
                    'item' => $t->expense_category_id !== null ? ($items[$t->expense_category_id] ?? null) : null,
                    'amount' => (float) $t->amount, 'fx' => $t->amount_fx !== null ? (float) $t->amount_fx : null,
                    'desc' => $t->description ?? '', 'ctr' => $t->counterparty ?? '', 'order' => $t->order_num ?? '', 'orders' => $orders, 'esf' => isset($esf[$t->id]) ? (float) $esf[$t->id] : 0,
                ];
            })->values(),
            // Legacy «Долг по авансам»: issued minus spent of the reports that are not closed.
            'advanceDebt' => (float) AdvanceReport::query()->where('status', '!=', 'closed')->get()
                ->reduce(fn ($s, AdvanceReport $a) => bcadd($s, bccomp($a->issued, $a->spent, 12) > 0 ? bcsub($a->issued, $a->spent, 12) : '0', 12), '0'),
            'categories' => [
                'income' => config('erp.money_income_categories'),
                'expense' => config('erp.money_expense_categories'),
            ],
        ]);
    }
}
