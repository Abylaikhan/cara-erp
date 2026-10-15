<?php

namespace App\Http\Controllers;

use App\Models\AdvanceReport;
use App\Models\MoneyAccount;
use App\Models\Order;
use App\Models\SalonWorksheetRow;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    /**
     * Legacy renderAdvances: newest first (in the order legacy lists them); «Потрачено» is the sum of the report lines when
     * there are any (advTotalSpent), the issuing operation is shown by its date and amount.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function advances()
    {
        $tx = DB::table('money_transactions')->whereIn('id', AdvanceReport::query()->whereNotNull('transaction_id')->pluck('transaction_id'))->get(['id', 'tx_date', 'amount'])->keyBy('id');

        return AdvanceReport::query()->orderByDesc('report_date')->orderBy('id')->get()->map(function (AdvanceReport $a) use ($tx) {
            $fromLines = collect($a->lines)->reduce(fn ($s, $l) => bcadd($s, is_numeric($l['amount'] ?? null) ? (string) $l['amount'] : '0', 12), '0');
            $spent = bccomp($fromLines, '0', 12) > 0 ? $fromLines : $a->spent;
            $t = $a->transaction_id !== null ? $tx->get($a->transaction_id) : null;

            return [
                'id' => $a->id,
                'doc_num' => $a->doc_num,
                'employee' => $a->employee_name,
                'date' => $a->report_date->format('Y-m-d'),
                'purpose' => $a->purpose,
                'issued' => (float) $a->issued,
                'spent' => (float) $spent,
                'returned' => (float) $a->returned,
                'status' => $a->status,
                'issue_doc' => $t ? ['date' => $t->tx_date, 'amount' => (float) $t->amount] : null,
                'esf' => collect($a->lines)->filter(fn ($l) => ! empty($l['esfInvoiceId']))->count(),
            ];
        })->values();
    }

    /**
     * Legacy renderPayCalendar reads the balance of each account, the orders that are not done or cancelled (expected
     * payments) and the operations dated after today. Amounts are in tenge (a dollar account counts amount_fx); the balance
     * includes the opening balance of the account (legacy left it out).
     *
     * @return array<string, mixed>
     */
    private function calendar(Request $request): array
    {
        $today = now(config('erp.business_timezone'))->format('Y-m-d');
        $kzt = "CASE WHEN a.currency = 'USD' THEN coalesce(t.amount_fx, t.amount) ELSE t.amount END";
        $flows = DB::table('money_transactions as t')->join('money_accounts as a', 'a.id', '=', 't.account_id')->groupBy('t.account_id')
            ->selectRaw("t.account_id, sum(CASE WHEN t.flow = 'income' THEN {$kzt} ELSE -({$kzt}) END) AS s")->pluck('s', 'account_id');

        return [
            'today' => $today,
            'balances' => MoneyAccount::query()->orderBy('sort_order')->get()
                ->mapWithKeys(fn (MoneyAccount $a) => [$a->id => bcadd((string) $a->opening_balance, (string) ($flows[$a->id] ?? '0'), 2)]),
            'orders' => ChannelScope::apply(Order::query()->whereNotIn('orders.status', ['done', 'cancelled']), $request->user(), 'orders.channel')
                ->leftJoinSub(DB::table('order_items')->groupBy('order_id')->selectRaw('order_id, sum(qty * price) AS total'), 'i', 'i.order_id', '=', 'orders.id')
                ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
                ->get(['orders.num', 'orders.channel', 'orders.customer', 'orders.status', 'orders.payment_pct', 'orders.delivery_date', 'orders.due_date', DB::raw('coalesce(i.total, 0) AS total')])
                ->map(fn (Order $o) => ['num' => $o->num, 'channel' => $o->channel, 'customer' => $o->customer, 'status' => $o->status, 'payment' => $o->payment_pct,
                    'delivery' => $o->delivery_date?->format('Y-m-d'), 'date' => $o->due_date?->format('Y-m-d'), 'total' => (string) $o->getAttribute('total')])->values(),
            'future' => DB::table('money_transactions as t')->join('money_accounts as a', 'a.id', '=', 't.account_id')->where('t.tx_date', '>', $today)
                ->orderBy('t.tx_date')->orderBy('t.id')
                ->get(['t.tx_date', 't.account_id', 't.flow', 't.category', 't.counterparty', 't.description', DB::raw("{$kzt} AS kzt")])
                ->map(fn ($t) => ['date' => $t->tx_date, 'account' => $t->account_id, 'flow' => $t->flow, 'category' => $t->category ?? '', 'ctr' => $t->counterparty ?? '',
                    'desc' => $t->description ?? '', 'amount' => (string) $t->kzt]),
        ];
    }

    /** @return array<string, mixed> */
    private function bankRecon(): array
    {
        $keys = ['a1b2c3d4-e5f6-7890-abcd-ef1234567890', '0265fb65-7812-4bee-b794-71306246edb7']; // Kaspi, Halyk (legacy order)
        $ids = DB::table('legacy_id_map')->where('source_table', 'money_accounts')->whereIn('source_key', $keys)->pluck('target_id', 'source_key');
        $accounts = collect($keys)->map(fn ($k) => $ids[$k] ?? null)->filter()->values();

        return [
            'accounts' => $accounts->all(),
            'rows' => DB::table('bank_statement_rows')->whereIn('account_id', $accounts)->orderBy('row_date')->orderBy('id')
                ->get(['id', 'account_id', 'row_date', 'flow', 'amount', 'counterparty', 'bin', 'description', 'transferred_to_db'])
                ->map(fn ($r) => ['id' => $r->id, 'account' => $r->account_id, 'date' => $r->row_date, 'flow' => $r->flow, 'amount' => (string) $r->amount,
                    'counterparty' => $r->counterparty, 'bin' => $r->bin, 'description' => $r->description, 'transferred' => (bool) $r->transferred_to_db]),
            // The journal of the accounts by day (legacy sums the operations of the account as they are).
            'journal' => DB::table('money_transactions')->whereIn('account_id', $accounts)->groupBy('account_id', 'tx_date')
                ->selectRaw("account_id, tx_date, sum(CASE WHEN flow = 'income' THEN amount ELSE 0 END) AS inc, sum(CASE WHEN flow = 'expense' THEN amount ELSE 0 END) AS out")->get()
                ->map(fn ($j) => ['account' => $j->account_id, 'date' => $j->tx_date, 'in' => (string) $j->inc, 'out' => (string) $j->out]),
        ];
    }

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
            // Tab «Авансовые отчёты» (legacy loadAdvancesFromDB/renderAdvances): loaded when the tab is opened.
            'advances' => Inertia::optional(fn () => $this->advances()),
            // Tab «Платёжный календарь» (legacy renderPayCalendar): balances, unpaid orders and dated operations, in tenge.
            'calendar' => Inertia::optional(fn () => $this->calendar($request)),
            // Tab «Сверка с банком» (legacy BANK_RECON_ACCOUNTS, loadBankReconFromDB, renderBankRecon): the statements of Kaspi and
            // Halyk and the journal of those accounts by day, sent when the tab is opened.
            'bankRecon' => Inertia::optional(fn () => $this->bankRecon()),
            // Tab «Рабочий лист салонов» (legacy loadSalonWbFromDB): the rows of both salons, sent when the tab is opened.
            'salonRows' => Inertia::optional(fn () => SalonWorksheetRow::query()->orderBy('order_date')->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (SalonWorksheetRow $r) => ['id' => $r->id, 'uuid' => $r->uuid, 'salon' => $r->salon, 'transferred' => $r->transferred_to_db,
                    'transferred_at' => $r->transferred_at?->toIso8601String()]
                    + collect(SalonWorksheetRow::DATE_FIELDS)->mapWithKeys(fn ($f) => [$f => $r->getAttribute($f)?->format('Y-m-d')])->all()
                    + collect(SalonWorksheetRow::TEXT_FIELDS)->mapWithKeys(fn ($f) => [$f => $r->getAttribute($f)])->all()
                    + collect(SalonWorksheetRow::NUMBER_FIELDS)->mapWithKeys(fn ($f) => [$f => $r->getRawOriginal($f)])->all())->values()),
            'categories' => [
                'income' => config('erp.money_income_categories'),
                'expense' => config('erp.money_expense_categories'),
            ],
        ]);
    }
}
