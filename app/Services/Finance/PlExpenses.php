<?php

namespace App\Services\Finance;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * «Финансы» → «📊 P&L» → «Расходы по категориям и статьям» (legacy renderExpensesByMonth, L27325–27436; ADR-0052): the expenses
 * of a year by month, category and article:
 * - the money journal, but transfers, the issue of advances and dividends; a supplier payment under the article of the supplier
 *   (none — «Поставщику (статья не указана)»; a supplier counted «по ЭСФ» — not here);
 * - the lines of the advance reports (materials and supplier lines in rows of their own);
 * - the service ESF of the suppliers counted «по ЭСФ».
 * Dividends are a row of their own under the total. Categories and articles by the sum of the year, largest first.
 */
class PlExpenses
{
    private const SKIP = ['transfer_in', 'transfer_out', 'transfer', 'advance_out', 'dividend'];

    /** @var array<string, array{name: string, parent: ?string}> */
    private array $cats = [];

    /** @var array<string, array{label: string, monthly: list<string>, subs: array<string, array{label: string, monthly: list<string>}>}> */
    private array $tree = [];

    private string $year = '';

    /** @return array{rows: list<array{code: string, label: string, monthly: list<float>, subs: list<array{code: string, label: string, monthly: list<float>}>}>, dividends: list<float>} */
    public function build(string $year): array
    {
        $this->year = $year;
        $this->tree = [];
        $this->cats = DB::table('expense_categories as c')->leftJoin('expense_categories as p', 'p.id', '=', 'c.parent_id')->where('c.active', true)
            ->get(['c.code', 'c.name', 'p.code as parent'])->mapWithKeys(fn ($c) => [$c->code => ['name' => $c->name, 'parent' => $c->parent]])->all();
        $catById = DB::table('expense_categories')->pluck('code', 'id');
        $suppliers = Supplier::query()->active()->orderBy('name')->orderBy('id')->get(['id', 'name', 'bin', 'expense_category_id', 'expense_source']);
        $codesOf = function (?Supplier $s) use ($catById): array {
            $code = $s?->expense_category_id !== null ? ($catById[$s->expense_category_id] ?? null) : null;
            $parent = $code !== null ? ($this->cats[$code]['parent'] ?? null) : null;

            return $code === null ? [null, null] : ($parent !== null ? [$parent, $code] : [$code, null]);
        };
        $dividends = array_fill(0, 12, '0');

        // the money journal, in the order legacy loads it (date.desc)
        foreach (DB::table('money_transactions')->where('flow', 'expense')->orderByDesc('tx_date')->orderBy('id')->get(['tx_date', 'category', 'expense_category_id', 'counterparty', 'bin', 'amount']) as $t) {
            $amt = (string) $t->amount;
            if ($t->category === 'dividend') {
                if (str_starts_with((string) $t->tx_date, $year)) {
                    $m = (int) substr((string) $t->tx_date, 5, 2) - 1;
                    $dividends[$m] = bcadd($dividends[$m], $amt, 2);
                }
            }
            if (in_array($t->category, self::SKIP, true)) {
                continue;
            }
            if ($t->category === 'supplier') {
                $sup = Supplier::findForName($suppliers, $t->counterparty, $t->bin);
                [$cat, $sub] = $codesOf($sup);
                if ($cat !== null && $sup?->expense_source === 'esf') {
                    continue; // counted by its ESF below
                }
                $cat !== null
                    ? $this->add($cat, $this->label($cat), $sub, $sub !== null ? $this->label($sub) : null, $t->tx_date, $amt)
                    : $this->add('supplier', 'Поставщику (статья не указана)', null, null, $t->tx_date, $amt);

                continue;
            }
            $cat = $t->category ?: 'other';
            $sub = $t->expense_category_id !== null ? ($catById[$t->expense_category_id] ?? null) : null;
            $this->add($cat, $this->label($cat) ?: 'Прочее', $sub, $sub !== null ? $this->label($sub) : null, $t->tx_date, $amt);
        }
        // the advance reports, line by line (legacy: date.desc)
        foreach (DB::table('advance_reports')->orderByDesc('report_date')->orderBy('id')->get(['report_date', 'lines']) as $a) {
            foreach (json_decode((string) $a->lines, true) ?: [] as $l) {
                if (! is_array($l) || ! is_numeric($l['amount'] ?? null)) {
                    continue;
                }
                $amt = (string) $l['amount'];
                match ($l['type'] ?? null) {
                    'material' => $this->add('adv_material', 'Материалы (аванс)', null, null, $a->report_date, $amt),
                    'supplier' => $this->add('adv_supplier', 'Оплата поставщику (аванс)', null, null, $a->report_date, $amt),
                    default => $this->add($c = (string) (($l['category'] ?? '') ?: 'other'), $this->label($c) ?: 'Прочее (аванс)', ($l['subcategory'] ?? '') ?: null,
                        ($l['subcategory'] ?? '') ? $this->label((string) $l['subcategory']) : null, $a->report_date, $amt),
                };
            }
        }
        // the service ESF of the suppliers counted «по ЭСФ» (legacy: date.desc)
        foreach (DB::table('incoming_invoices')->where('invoice_type', 'service')->orderByDesc('date')->orderBy('id')->get(['date', 'supplier_name', 'supplier_bin', 'total_with_nds']) as $inv) {
            $sup = Supplier::findForName($suppliers, $inv->supplier_name, $inv->supplier_bin);
            [$cat, $sub] = $codesOf($sup);
            if ($cat === null || $sup?->expense_source !== 'esf') {
                continue;
            }
            $this->add($cat, $this->label($cat), $sub, $sub !== null ? $this->label($sub) : null, $inv->date, (string) $inv->total_with_nds);
        }

        $sum = fn (array $m) => array_reduce($m, fn ($s, $v) => bcadd($s, $v, 2), '0');
        $byYear = fn (array $a, array $b) => bccomp($sum($b['monthly']), $sum($a['monthly']), 2);
        $rows = [];
        foreach ($this->tree as $code => $c) {
            $subs = [];
            foreach ($c['subs'] as $sc => $s) {
                $subs[] = ['code' => (string) $sc, 'label' => $s['label'], 'monthly' => $s['monthly']];
            }
            usort($subs, $byYear);
            $rows[] = ['code' => (string) $code, 'label' => $c['label'], 'monthly' => $c['monthly'], 'subs' => $subs];
        }
        usort($rows, $byYear);
        $f = fn (array $m) => array_map('floatval', $m);

        return [
            'rows' => array_map(fn ($r) => ['code' => $r['code'], 'label' => $r['label'], 'monthly' => $f($r['monthly']),
                'subs' => array_map(fn ($s) => ['code' => $s['code'], 'label' => $s['label'], 'monthly' => $f($s['monthly'])], $r['subs'])], $rows),
            'dividends' => $f($dividends),
        ];
    }

    /** Legacy addToCat: an amount of the year to its category and, when named, its article. */
    private function add(string $cat, string $catLabel, ?string $sub, ?string $subLabel, ?string $date, string $amt): void
    {
        if (bccomp($amt, '0', 12) === 0 || ! str_starts_with((string) $date, $this->year)) {
            return;
        }
        $m = (int) substr((string) $date, 5, 2) - 1;
        if ($m < 0 || $m > 11) {
            return;
        }
        $this->tree[$cat] ??= ['label' => $catLabel, 'monthly' => array_fill(0, 12, '0'), 'subs' => []];
        $this->tree[$cat]['monthly'][$m] = bcadd($this->tree[$cat]['monthly'][$m], $amt, 12);
        if ($sub !== null) {
            $this->tree[$cat]['subs'][$sub] ??= ['label' => (string) $subLabel, 'monthly' => array_fill(0, 12, '0')];
            $this->tree[$cat]['subs'][$sub]['monthly'][$m] = bcadd($this->tree[$cat]['subs'][$sub]['monthly'][$m], $amt, 12);
        }
    }

    /** Legacy catLabelFor: the name in the directory, else the label of the kind of operation, else the code. */
    private function label(string $code): string
    {
        return $this->cats[$code]['name'] ?? (config('erp.money_expense_categories')[$code][1] ?? $code);
    }
}
