<?php

namespace App\Services\Finance;

use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Финансы» → «📊 P&L»: the statement of legacy buildPL/renderPL (L26064–26330, L27527–27606) for a period, computed here on the
 * migrated data with exact sums (legacy summed floats in the browser and showed whole tenge). Answer of the accountant 23.10,
 * № 36: the formulas as they are — ADR-0052.
 *
 * - Выручка — the realizations of the period (doc_date).
 * - Прямые материалы — the goods lines of the incoming ESF of the period, but for the material categories legacy treats as
 *   overheads (tools, consumables, other), plus the expense articles «Производственные» named as materials. Legacy fell back to
 *   write-offs and tech cards when there were no ESF: there are neither (D-12), so the fallback is 0.
 * - Зарплата сдельная — column «Сдельная» of the salary ledger of the period; legacy's fallbacks were its demonstration lists.
 * - ОПР — the articles «Производственные» named as overheads.
 * - Оклады АУП — Оклад, Премия, Отпускные, Займ, Больничный of the ledger, else the salary payments of the period.
 * - Налоги на сдельщиков — 0: legacy counted them from its demonstration list; taxes are not counted (№ 50).
 * - Коммерческие / Аренда, ком. услуги, прочее — the expenses of the period by the top category of their article
 *   («Коммерческие», «Хозяйственные»), a supplier payment by the article of the supplier, plus the lines of the advance reports.
 */
class PlReport
{
    private const TO_MATERIALS = ['бук', 'лес', 'дуб', 'карагач', 'сосна', 'ясень', 'материалы производства', 'стекло', 'краска', 'матрас', 'сантехника', 'ткань'];

    private const TO_OPR = ['станок', 'оборудован', 'производственные расходы', 'дополнительная работа', 'полировка', 'инструмент', 'запчаст', 'ремонт', 'обслуживан',
        'разгрузка', 'погрузка', 'производство прочее', 'точен', 'реставрация'];

    /** Legacy MATCOST_ELIGIBILITY: the material categories that are overheads, not materials of a product. */
    private const OVERHEAD_MATERIALS = ['Инструмент/Абразивы', 'Расходники', 'Прочее'];

    /** @var array<string, array{name: string, parent: ?string}> code → name and parent code */
    private array $cats = [];

    /** @var array<string, mixed>|null the rows the statement reads, loaded once for any number of periods */
    private ?array $data = null;

    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $this->cats = DB::table('expense_categories as c')->leftJoin('expense_categories as p', 'p.id', '=', 'c.parent_id')->where('c.active', true)
            ->get(['c.code', 'c.name', 'p.code as parent'])->mapWithKeys(fn ($c) => [$c->code => ['name' => $c->name, 'parent' => $c->parent]])->all();

        return $this->data = [
            'suppliers' => Supplier::query()->active()->orderBy('name')->orderBy('id')->get(['id', 'name', 'bin', 'expense_category_id']),
            'catById' => DB::table('expense_categories')->pluck('code', 'id'),
            'realizations' => DB::table('realizations')->get(['doc_date', 'revenue']),
            'invType' => DB::table('legacy_id_map as m')->join('incoming_invoices as i', 'i.id', '=', 'm.target_id')->where('m.source_table', 'incoming_invoices')->pluck('i.invoice_type', 'm.source_key'),
            'advances' => DB::table('advance_reports')->get(['report_date', 'lines'])->map(fn ($a) => [$a->report_date, json_decode((string) $a->lines, true) ?: []]),
            'txs' => DB::table('money_transactions')->where('flow', 'expense')->get(['tx_date', 'category', 'expense_category_id', 'counterparty', 'bin', 'amount']),
            'esf' => $this->esfLines(),
            'accruals' => DB::table('salary_accruals')->where('type', 'salary')->get(['accrual_date', 'amount', 'details']),
        ];
    }

    /** The years of the realizations, newest first (legacy plBuildRevenueYearOptions). @return list<string> */
    public function years(): array
    {
        return $this->load()['realizations']->map(fn ($r) => substr((string) $r->doc_date, 0, 4))->filter()->unique()->sortDesc()->values()->all();
    }

    /**
     * Legacy renderMonthlyPL: the statement of each month of the year; a month that has not come yet is not counted (null).
     *
     * @return list<array<string, float|bool>|null>
     */
    public function monthly(string $year, string $todayYm): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%s-%02d', $year, $m);
            $out[] = $ym > $todayYm ? null : $this->build("{$ym}-01", $ym.'-'.date('t', (int) strtotime("{$ym}-01")));
        }

        return $out;
    }

    /** @return array<string, float|bool> */
    public function build(?string $from, ?string $to): array
    {
        $data = $this->load();
        $in = fn (?string $d) => ! $d || ((! $from || $d >= $from) && (! $to || $d <= $to));
        $suppliers = $data['suppliers'];
        $catById = $data['catById'];

        // ── Выручка ──
        $revenue = $data['realizations']->filter(fn ($r) => $in($r->doc_date))->reduce(fn ($s, $r) => bcadd($s, (string) ($r->revenue ?? '0'), 2), '0');

        // ── Advance reports, line by line (legacy: the issue of an advance is not an expense) ──
        $invType = $data['invType'];
        $adv = ['commercial' => '0', 'other' => '0', 'undetermined' => '0'];
        $prod = ['materials' => '0', 'opr' => '0', 'unclassified' => '0'];
        foreach ($data['advances'] as [$date, $lines]) {
            $a = (object) ['report_date' => $date, 'lines' => $lines];
            if (! $in($a->report_date)) {
                continue;
            }
            foreach ($a->lines as $l) {
                $amt = is_numeric($l['amount'] ?? null) ? (string) $l['amount'] : '0';
                if (! is_array($l) || bccomp($amt, '0', 12) === 0 || ($l['type'] ?? '') === 'material') {
                    continue;
                }
                if (($l['type'] ?? '') === 'supplier') {
                    if (($l['esfInvoiceId'] ?? null) && ($invType[(string) $l['esfInvoiceId']] ?? null) === 'goods') {
                        continue; // a payment for goods: the goods are counted by their ESF
                    }
                    $sup = Supplier::findForName($suppliers, $l['supplier'] ?? null, null);
                    [$cat, $sub] = $this->supplierCodes($sup, $catById);
                    $top = $cat !== null ? $this->top($cat) : null;
                    if ($top === 'production') {
                        $this->classify($this->cats[$sub ?? '']['name'] ?? $this->cats[$cat]['name'] ?? null, $amt, $prod);
                    } elseif ($top === 'commercial') {
                        $adv['commercial'] = bcadd($adv['commercial'], $amt, 12);
                    } elseif ($top !== null && $this->isHousehold($top)) {
                        $adv['other'] = bcadd($adv['other'], $amt, 12);
                    } elseif ($top === null) {
                        $adv['undetermined'] = bcadd($adv['undetermined'], $amt, 12);
                    }

                    continue;
                }
                $top = $this->top($l['category'] ?? null);
                if ($top === 'production') {
                    $this->classify($this->cats[(string) ($l['subcategory'] ?? '')]['name'] ?? $this->cats[(string) ($l['category'] ?? '')]['name'] ?? null, $amt, $prod);
                } elseif ($top === 'commercial') {
                    $adv['commercial'] = bcadd($adv['commercial'], $amt, 12);
                } elseif ($top !== null && $this->isHousehold($top)) {
                    $adv['other'] = bcadd($adv['other'], $amt, 12);
                }
            }
        }

        // ── Expenses of the money journal ──
        $commercial = '0';
        $other = '0';
        $salaryTx = '0';
        $anySalaryTx = false;
        $txs = $data['txs']->filter(fn ($t) => $in($t->tx_date));
        foreach ($txs as $t) {
            $amt = (string) $t->amount;
            $sub = $t->expense_category_id !== null ? ($catById[$t->expense_category_id] ?? null) : null;
            if ($t->category === 'salary') {
                $salaryTx = bcadd($salaryTx, $amt, 2);
                $anySalaryTx = true;
            }
            $catCode = $t->category;
            if ($catCode === 'supplier') {
                [$catCode] = $this->supplierCodes(Supplier::findForName($suppliers, $t->counterparty, $t->bin), $catById);
                if ($catCode === null) {
                    continue;
                }
            }
            // Коммерческие и «Хозяйственные» (legacy: the category, else the article; salary aside; «Производственные» below)
            if ($catCode !== 'salary') {
                $match = isset($this->cats[$catCode]) ? $catCode : ($sub !== null && isset($this->cats[$sub]) ? $sub : null);
                if ($match !== null) {
                    $top = $this->top($match);
                    if ($top === 'commercial') {
                        $commercial = bcadd($commercial, $amt, 2);
                    } elseif ($top !== 'production' && $this->isHousehold($top)) {
                        $other = bcadd($other, $amt, 2);
                    }
                }
            }
            // «Производственные» by article: materials or overheads
            $topOfSub = $sub !== null ? $this->top($sub) : null;
            if ($this->top($catCode) === 'production' || $topOfSub === 'production') {
                $article = $topOfSub === 'production' ? $sub : $catCode;
                $this->classify($this->cats[$article]['name'] ?? $article, $amt, $prod);
            }
        }

        // ── Materials by the ESF of the period ──
        $esf = array_reduce($data['esf'], fn ($s, $l) => $in($l[0]) ? bcadd($s, $l[1], 2) : $s, '0');
        // ── Salary ledger ──
        $ledger = $this->ledger($data['accruals'], $in);

        $materialCost = bcadd($esf, $prod['materials'], 12);
        $wageCost = $ledger['sdelnaya'];
        $oprCost = $prod['opr'];
        $ledgerAup = array_reduce(['oklad', 'premiya', 'otpusk', 'zaim', 'bolnichn'], fn ($s, $k) => bcadd($s, $ledger[$k], 12), '0');
        $aupSalary = bccomp($ledgerAup, '0', 12) > 0 ? $ledgerAup : ($anySalaryTx ? $salaryTx : '0');
        $anyFixedTx = bccomp(bcadd($commercial, $other, 2), '0', 2) > 0;
        $otherFixed = bcadd($anyFixedTx ? $other : '0', $adv['other'], 12);
        $commercialCost = bcadd($commercial, $adv['commercial'], 12);
        $grossMargin = bcsub(bcsub(bcsub($revenue, $materialCost, 12), $wageCost, 12), $oprCost, 12);
        $fixedTotal = bcadd(bcadd($aupSalary, $commercialCost, 12), $otherFixed, 12);
        $ebit = bcsub($grossMargin, $fixedTotal, 12);
        $f = fn (string $v) => (float) $v;

        return [
            'revenue' => $f($revenue), 'materialCost' => $f($materialCost), 'esfMaterialEstimate' => $f($esf), 'wageCost' => $f($wageCost), 'oprCost' => $f($oprCost),
            'grossMargin' => $f($grossMargin), 'grossMarginPct' => bccomp($revenue, '0', 2) > 0 ? $f(bcdiv($grossMargin, $revenue, 12)) : 0.0,
            'aupSalary' => $f($aupSalary), 'prodTaxes' => 0.0, 'commercialCost' => $f($commercialCost), 'otherFixed' => $f($otherFixed), 'fixedTotal' => $f($fixedTotal),
            'ebit' => $f($ebit), 'ebitPct' => bccomp($revenue, '0', 2) > 0 ? $f(bcdiv($ebit, $revenue, 12)) : 0.0,
            'prodToMaterials' => $f($prod['materials']), 'prodToOpr' => $f($prod['opr']), 'prodUnclassified' => $f($prod['unclassified']),
            'advCommercialCost' => $f($adv['commercial']), 'advOtherExpense' => $f($adv['other']), 'advUndetermined' => $f($adv['undetermined']),
            'payrollLedgerSdelnaya' => $f($ledger['sdelnaya']), 'payrollLedgerAup' => $f($ledgerAup),
        ];
    }

    /** Legacy classifyProdStatya into materials, overheads or neither. @param array<string, string> $prod */
    private function classify(?string $name, string $amt, array &$prod): void
    {
        $n = mb_strtolower((string) $name);
        $bucket = array_filter(self::TO_MATERIALS, fn ($k) => str_contains($n, $k)) ? 'materials' : (array_filter(self::TO_OPR, fn ($k) => str_contains($n, $k)) ? 'opr' : 'unclassified');
        $prod[$bucket] = bcadd($prod[$bucket], $amt, 12);
    }

    /** Legacy topLevelCategoryOf: up the parents; an unknown code is itself. */
    private function top(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return $code === '' ? null : $code;
        }
        for ($i = 0; isset($this->cats[$code]) && $this->cats[$code]['parent'] !== null && isset($this->cats[$this->cats[$code]['parent']]) && $i < 10; $i++) {
            $code = $this->cats[$code]['parent'];
        }

        return $code;
    }

    private function isHousehold(?string $top): bool
    {
        return $top !== null && mb_strtolower($this->cats[$top]['name'] ?? '') === 'хозяйственные';
    }

    /**
     * The legacy pair (expense_category, expense_subcategory) of a supplier: the import kept the article, else the category.
     *
     * @param  Collection<int, string>  $catById
     * @return array{?string, ?string}
     */
    private function supplierCodes(?Supplier $sup, Collection $catById): array
    {
        $code = $sup?->expense_category_id !== null ? ($catById[$sup->expense_category_id] ?? null) : null;
        if ($code === null) {
            return [null, null];
        }
        $parent = $this->cats[$code]['parent'] ?? null;

        return $parent !== null ? [$parent, $code] : [$code, null];
    }

    /**
     * Legacy estimateMaterialCostForPeriod: the goods ESF lines that count as materials (not those of overhead material
     * categories), with the date of their invoice.
     *
     * @return list<array{?string, string}>
     */
    private function esfLines(): array
    {
        $category = self::materialCategoryResolver();
        $out = [];
        $lines = DB::table('incoming_invoice_items as i')->join('incoming_invoices as v', 'v.id', '=', 'i.invoice_id')->where('v.invoice_type', 'goods')
            ->get(['v.date', 'i.description', 'i.total', 'i.amount']);
        foreach ($lines as $l) {
            $amt = bccomp((string) $l->total, '0', 2) !== 0 ? (string) $l->total : (string) $l->amount;
            if (bccomp($amt, '0', 2) === 0 || in_array($category((string) $l->description), self::OVERHEAD_MATERIALS, true)) {
                continue;
            }
            $out[] = [$l->date, $amt];
        }

        return $out;
    }

    /**
     * Legacy materialCategoryForName: the category of the material with this name in a material warehouse — exactly (without
     * case), else the first whose name contains it or is contained.
     */
    public static function materialCategoryResolver(): \Closure
    {
        $materials = DB::table('materials as m')->join('warehouses as w', 'w.code', '=', 'm.warehouse_code')->where('w.type', 'material')->where('m.active', true)
            ->orderBy('m.name')->orderBy('m.id')->get(['m.name', 'm.category'])->map(fn ($m) => [mb_strtolower(trim($m->name)), $m->category]);
        $exact = [];
        foreach ($materials as [$n, $c]) {
            $exact[$n] ??= $c;
        }
        $cache = [];

        return function (string $name) use ($materials, $exact, &$cache): ?string {
            $n = mb_strtolower(trim($name));
            if ($n === '') {
                return null;
            }
            if (! array_key_exists($n, $cache)) {
                $cache[$n] = $exact[$n] ?? ($materials->first(fn ($m) => $m[0] !== '' && (str_contains($m[0], $n) || str_contains($n, $m[0])))[1] ?? null);
            }

            return $cache[$n];
        };
    }

    /**
     * Legacy sumPayrollLedgerColumns: the accruals of the period by the kinds of their details; an accrual without details is
     * «Оклад».
     *
     * @param  Collection<int, object>  $accruals
     * @return array<string, string>
     */
    private function ledger(Collection $accruals, callable $in): array
    {
        $res = ['sdelnaya' => '0', 'oklad' => '0', 'premiya' => '0', 'otpusk' => '0', 'bolnichn' => '0', 'zaim' => '0'];
        foreach ($accruals as $a) {
            if (! $in($a->accrual_date)) {
                continue;
            }
            $acc = json_decode((string) $a->details, true)['accruals'] ?? [];
            $acc = is_array($acc) ? $acc : [];
            $sum = function (string $kind) use ($acc): string {
                $s = '0';
                foreach ($acc as $x) {
                    if (is_array($x) && str_contains((string) ($x['type'] ?? ''), $kind) && is_numeric($x['amount'] ?? null)) {
                        $s = bcadd($s, (string) $x['amount'], 12);
                    }
                }

                return $s;
            };
            $oklad = $sum('Оклад');
            $res['oklad'] = bcadd($res['oklad'], bccomp($oklad, '0', 12) !== 0 ? $oklad : ($acc === [] ? (string) $a->amount : '0'), 12);
            if ($acc !== []) {
                $res['sdelnaya'] = bcadd($res['sdelnaya'], $sum('Сдельная'), 12);
            }
            foreach (['premiya' => 'Премия', 'otpusk' => 'Отпускные', 'bolnichn' => 'Больничный', 'zaim' => 'Займ'] as $k => $kind) {
                $res[$k] = bcadd($res[$k], $sum($kind), 12);
            }
        }

        return $res;
    }
}
