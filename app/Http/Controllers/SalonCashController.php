<?php

namespace App\Http\Controllers;

use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M15 «Касса салона», part 1 — legacy page-money_salon (L7064–7137), renderSalonMoney/reloadSalonMoney/smSort
 * (L15576–15725): the operations of the salon's own cash (the head of the salons sees both salons), the balance, the period
 * totals and the money handed to the office. View only: «📥 Приход» posts money (ERP-031…033, ERP-051, D-03, D-11).
 */
class SalonCashController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'money_salon'), 403);
        $role = Erp::role($request->user()->role) ?? [];
        // Legacy: a salon account sees its cash, an account without a channel (the head of the salons) both salons.
        $keys = isset($role['cash_account']) ? [$role['cash_account']]
            : collect(config('erp.roles'))->pluck('cash_account')->filter()->values()->all();
        $ids = DB::table('legacy_id_map')->where('source_table', 'money_accounts')->whereIn('source_key', $keys)->pluck('target_id', 'source_key');
        $accounts = collect($keys)->map(fn ($k) => $ids[$k] ?? null)->filter()->values()->all();
        $channel = Erp::channelOf($request->user());
        $orderPayments = DB::table('order_payments')->orderBy('id')->get(['transaction_id', 'order_num'])->groupBy('transaction_id');
        $esf = DB::table('esf_payment_links')->where('source_type', 'money_tx')
            ->join('legacy_id_map', fn ($j) => $j->on('legacy_id_map.source_key', '=', 'esf_payment_links.source_legacy_id')->where('legacy_id_map.source_table', 'money_tx'))
            ->groupBy('legacy_id_map.target_id')->havingRaw('sum(esf_payment_links.amount) > 0')
            ->selectRaw('legacy_id_map.target_id, sum(esf_payment_links.amount) AS linked')->pluck('linked', 'target_id');

        return Inertia::render('MoneySalon', [
            'title' => $channel === 'salon_armada' ? 'Касса — Армада' : ($channel === 'salon_nazarbayeva' ? 'Касса — Назарбаева' : 'Касса — Армада + Назарбаева'),
            'canCashIn' => (bool) ($role['flags']['can_cash_in'] ?? false),
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            // Legacy loads each cash by date descending (the import keeps the legacy order inside a day) and puts the cashes one
            // after the other: Армада, then Назарбаева.
            'transactions' => DB::table('money_transactions')->whereIn('account_id', $accounts)
                ->orderByRaw('array_position(?::bigint[], account_id)', ['{'.implode(',', $accounts).'}'])->orderByDesc('tx_date')->orderBy('id')->get()
                ->map(function ($t) use ($orderPayments, $esf, $accounts) {
                    $orders = $t->order_num !== null ? [$t->order_num] : [];
                    foreach ($orderPayments->get($t->id, collect()) as $p) {
                        if (! in_array($p->order_num, $orders, true)) {
                            $orders[] = $p->order_num;
                        }
                    }

                    return ['id' => $t->id, 'cash' => array_search($t->account_id, $accounts), 'date' => $t->tx_date, 'flow' => $t->flow, 'category' => $t->category ?? '', 'amount' => (string) $t->amount,
                        'desc' => $t->description ?: ($t->counterparty ?? ''), 'orders' => $orders, 'esf' => isset($esf[$t->id]) ? (string) $esf[$t->id] : null];
                })->values(),
            'categories' => ['income' => config('erp.money_income_categories'), 'expense' => config('erp.money_expense_categories')],
        ]);
    }
}
