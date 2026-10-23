<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/**
 * «Финансы» → «📋 ОСВ»: the management trial balance of legacy buildOSV/renderOSV/ddOSVAccount (L23427–23812, L33530; ADR-0053),
 * double entries on the chart of accounts of Kazakhstan, with exact sums:
 * - money: a customer payment Дт 50/51 Кт 62, a supplier Дт 60, salary Дт 70, tax Дт 68, an advance Дт 71 (its return back),
 *   loans and other income Кт 91.1, dividends Дт 84; transfers between own accounts make no entry; any other expense Дт 91.2,
 *   income Кт 91.1; the opening balance of a cash desk or bank is its operations before the period;
 * - realizations Дт 62 Кт 90.1; the cost of sales of «📊 P&L» (materials + piece wages) Дт 90.2 Кт 43 on the last day;
 * - the «expense» lines of the advance reports Дт 91.2 Кт 71; goods ESF Дт 10 Кт 60, other ESF Дт 7210 Кт 60;
 * - what legacy reads from its demonstration lists and tech cards (write-offs, payroll of the sample staff, output of done
 *   orders) makes no entry (ADR-0002, item 126);
 * - the difference of the columns goes to 84 «Нераспределённая прибыль», as legacy balances it.
 */
class OsvReport
{
    public const ACCOUNTS = [
        '50' => ['Касса', 'active', '1010'], '51' => ['Расчётные счета', 'active', '1030'], '60' => ['Расчёты с поставщиками', 'passive', '3310'],
        '62' => ['Расчёты с покупателями', 'active', '1210'], '68' => ['Расчёты по налогам', 'passive', '3190'], '70' => ['Расчёты по оплате труда', 'passive', '3350'],
        '71' => ['Расчёты с подотчётными', 'active', '1250'], '10' => ['Материалы / ТМЗ', 'active', '1310'], '20' => ['Основное производство', 'active', '8110'],
        '43' => ['Готовая продукция', 'active', '1320'], '84' => ['Нераспределённая прибыль', 'passive', '5610'], '90.1' => ['Выручка от продаж', 'passive', '6010'],
        '90.2' => ['Себестоимость продаж', 'active', '7010'], '91.1' => ['Прочие доходы', 'passive', '6290'], '91.2' => ['Прочие расходы', 'active', '7480'],
        '7210' => ['Административные расходы', 'active', '7210'],
    ];

    /** @var array<string, array{openDt: string, openKt: string, dtOb: string, ktOb: string}> */
    private array $ledger = [];

    /** @var list<array{dt: string, kt: string, amount: string, desc: string, doc: string, date: string}> */
    private array $journal = [];

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, float>, journal: list<array<string, mixed>>} */
    public function build(?string $from, ?string $to): array
    {
        $this->ledger = array_fill_keys(array_map('strval', array_keys(self::ACCOUNTS)), ['openDt' => '0', 'openKt' => '0', 'dtOb' => '0', 'ktOb' => '0']);
        $this->journal = [];
        // legacy per(): before the period — opening, in it — the period, after it — nothing
        $per = fn (?string $d) => $from && (string) $d < $from ? 'open' : (! $to || (string) $d <= $to ? 'cur' : null);
        $types = DB::table('money_accounts')->pluck('type', 'id');

        // 1. Money
        $txs = DB::table('money_transactions')->orderByDesc('tx_date')->orderBy('id')->get(['account_id', 'tx_date', 'flow', 'category', 'amount', 'description', 'counterparty', 'order_num']);
        foreach ($txs as $t) {
            $p = $per($t->tx_date);
            if ($p === null) {
                continue;
            }
            $cash = ($types[$t->account_id] ?? null) === 'cash' ? '50' : '51';
            $amt = (string) $t->amount;
            $desc = (string) ($t->description ?? '');
            match ($t->category) {
                'order_payment' => $this->post($cash, '62', $amt, $p, $desc ?: 'Оплата заказа', (string) ($t->order_num ?? ''), $t->tx_date),
                'supplier' => $this->post('60', $cash, $amt, $p, $desc ?: 'Оплата поставщику', (string) ($t->counterparty ?? ''), $t->tx_date),
                'salary' => $this->post('70', $cash, $amt, $p, $desc ?: 'Выплата зарплаты', '', $t->tx_date),
                'tax' => $this->post('68', $cash, $amt, $p),
                'advance_out' => $this->post('71', $cash, $amt, $p),
                'advance_return' => $this->post($cash, '71', $amt, $p),
                'loan_in', 'other_in' => $this->post($cash, '91.1', $amt, $p),
                'dividend' => $this->post('84', $cash, $amt, $p, $desc ?: 'Дивиденды', '', $t->tx_date),
                'transfer', 'transfer_in', 'transfer_out' => null,
                default => $t->flow === 'expense' ? $this->post('91.2', $cash, $amt, $p, $desc, (string) ($t->category ?? ''), $t->tx_date)
                    : ($t->flow === 'income' ? $this->post($cash, '91.1', $amt, $p, $desc, (string) ($t->category ?? ''), $t->tx_date) : null),
            };
        }
        // the opening balance of each cash desk and bank: its operations before the period
        if ($from) {
            foreach (DB::table('money_accounts')->orderBy('sort_order')->get(['id', 'type']) as $a) {
                $bal = (string) DB::table('money_transactions')->where('account_id', $a->id)->where('tx_date', '<', $from)
                    ->selectRaw("coalesce(sum(CASE WHEN flow = 'income' THEN amount WHEN flow = 'expense' THEN -amount ELSE 0 END), 0) AS b")->value('b');
                $acc = $a->type === 'cash' ? '50' : '51';
                if (bccomp($bal, '0', 2) > 0) {
                    $this->ledger[$acc]['openDt'] = bcadd($this->ledger[$acc]['openDt'], $bal, 2);
                } elseif (bccomp($bal, '0', 2) < 0) {
                    $this->ledger[$acc]['openKt'] = bcadd($this->ledger[$acc]['openKt'], ltrim($bal, '-'), 2);
                }
            }
        }
        // 2. Revenue, cost of sales
        foreach (DB::table('realizations')->orderByDesc('doc_date')->orderBy('id')->get(['doc_date', 'revenue', 'order_num']) as $r) {
            if (($p = $per($r->doc_date)) !== null && bccomp((string) ($r->revenue ?? '0'), '0', 2) > 0) {
                $this->post('62', '90.1', (string) $r->revenue, $p, 'Реализация', (string) ($r->order_num ?? ''), $r->doc_date);
            }
        }
        $pl = (new PlReport)->build($from, $to);
        $cogs = bcadd((string) $pl['materialCost'], (string) $pl['wageCost'], 2);
        if (bccomp($cogs, '0', 2) > 0) {
            $this->post('90.2', '43', $cogs, 'cur', 'Себестоимость продаж (по данным P&L)', '', $to ?? '');
        }
        // the «expense» lines of the advance reports
        foreach (DB::table('advance_reports')->orderByDesc('report_date')->orderBy('id')->get(['report_date', 'employee_name', 'lines']) as $a) {
            if (($p = $per($a->report_date)) === null) {
                continue;
            }
            foreach (json_decode((string) $a->lines, true) ?: [] as $l) {
                if (is_array($l) && ($l['type'] ?? null) === 'expense' && is_numeric($l['amount'] ?? null) && bccomp((string) $l['amount'], '0', 12) > 0) {
                    $this->post('91.2', '71', (string) $l['amount'], $p, 'Расход из аванс. отчёта', (string) ($a->employee_name ?? ''), $a->report_date);
                }
            }
        }
        // 3. Purchases by the incoming ESF
        foreach (DB::table('incoming_invoices')->orderByDesc('date')->orderBy('id')->get(['date', 'num', 'invoice_type', 'total_with_nds']) as $inv) {
            if (($p = $per($inv->date)) === null || bccomp((string) $inv->total_with_nds, '0', 2) <= 0) {
                continue;
            }
            $inv->invoice_type === 'goods'
                ? $this->post('10', '60', (string) $inv->total_with_nds, $p, 'Приобретение ТМЗ по ЭСФ', (string) ($inv->num ?? ''), $inv->date)
                : $this->post('7210', '60', (string) $inv->total_with_nds, $p, 'Приобретение услуг по ЭСФ', (string) ($inv->num ?? ''), $inv->date);
        }

        // closing balances; rows with any figure, by account code
        $rows = [];
        foreach ($this->ledger as $code => $l) {
            [$name, $type, $kz] = self::ACCOUNTS[$code] ?? [$code, 'active', $code];
            $open = bcsub($l['openDt'], $l['openKt'], 12);
            $close = bcadd($open, bcsub($l['dtOb'], $l['ktOb'], 12), 12);
            $row = ['code' => (string) $code, 'kz' => $kz, 'name' => $name, 'type' => $type, 'openDt' => self::pos($open), 'openKt' => self::pos(self::neg($open)),
                'dtOb' => $l['dtOb'], 'ktOb' => $l['ktOb'], 'closeDt' => self::pos($close), 'closeKt' => self::pos(self::neg($close))];
            if (array_filter(['openDt', 'openKt', 'dtOb', 'ktOb', 'closeDt', 'closeKt'], fn ($k) => bccomp($row[$k], '0', 12) !== 0)) {
                $rows[] = $row;
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));
        // the difference of the columns is the undistributed profit (84)
        $sum = fn (string $k) => array_reduce($rows, fn ($s, $r) => bcadd($s, $r[$k], 12), '0');
        $ret = ['openKt' => self::pos(bcsub($sum('openDt'), $sum('openKt'), 12)), 'closeKt' => self::pos(bcsub($sum('closeDt'), $sum('closeKt'), 12)),
            'dtOb' => self::pos(bcsub($sum('dtOb'), $sum('ktOb'), 12)), 'ktOb' => self::pos(bcsub($sum('ktOb'), $sum('dtOb'), 12))];
        if (array_filter($ret, fn ($v) => bccomp($v, '0', 12) > 0)) {
            $i = array_search('84', array_column($rows, 'code'), true);
            if ($i === false) {
                $rows[] = ['code' => '84', 'kz' => '5610', 'name' => 'Нераспределённая прибыль', 'type' => 'passive', 'openDt' => '0', 'openKt' => '0', 'dtOb' => '0', 'ktOb' => '0', 'closeDt' => '0', 'closeKt' => '0'];
                usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));
                $i = array_search('84', array_column($rows, 'code'), true);
            }
            foreach ($ret as $k => $v) {
                $rows[$i][$k] = bcadd($rows[$i][$k], $v, 12);
            }
        }
        // the totals after 84 (an arrow function keeps the rows it saw: sum the rows as they are now)
        $totals = [];
        foreach (['openDt', 'openKt', 'dtOb', 'ktOb', 'closeDt', 'closeKt'] as $k) {
            $totals[$k] = (float) array_reduce($rows, fn ($s, $r) => bcadd($s, $r[$k], 12), '0');
        }

        return ['rows' => array_map(fn ($r) => [...$r, ...array_map('floatval', array_intersect_key($r, array_flip(['openDt', 'openKt', 'dtOb', 'ktOb', 'closeDt', 'closeKt'])))], $rows),
            'totals' => $totals,
            'journal' => array_map(fn ($e) => [...$e, 'amount' => (float) $e['amount']], $this->journal)];
    }

    /** Legacy post(): an entry Дт / Кт; before the period — the opening balance, in it — the turnover (and the journal). */
    private function post(string $dt, string $kt, string $amount, string $period, string $desc = '', string $doc = '', ?string $date = ''): void
    {
        if (bccomp($amount, '0', 12) <= 0) {
            return;
        }
        foreach ([$dt, $kt] as $acc) {
            $this->ledger[$acc] ??= ['openDt' => '0', 'openKt' => '0', 'dtOb' => '0', 'ktOb' => '0'];
        }
        if ($period === 'open') {
            $this->ledger[$dt]['openDt'] = bcadd($this->ledger[$dt]['openDt'], $amount, 12);
            $this->ledger[$kt]['openKt'] = bcadd($this->ledger[$kt]['openKt'], $amount, 12);
        } else {
            $this->ledger[$dt]['dtOb'] = bcadd($this->ledger[$dt]['dtOb'], $amount, 12);
            $this->ledger[$kt]['ktOb'] = bcadd($this->ledger[$kt]['ktOb'], $amount, 12);
            $this->journal[] = ['dt' => $dt, 'kt' => $kt, 'amount' => $amount, 'desc' => $desc, 'doc' => $doc, 'date' => (string) $date];
        }
    }

    private static function pos(string $v): string
    {
        return bccomp($v, '0', 12) > 0 ? $v : '0';
    }

    private static function neg(string $v): string
    {
        return bcmul($v, '-1', 12);
    }
}
