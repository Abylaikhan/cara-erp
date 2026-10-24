<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Финансы» → «Точка безубыточности» → «Постоянные затраты — план и факт» (legacy loadFixedCostsPeriod/getFCFact/
 * saveAllFixedCosts, L21084–21300; ADR-0055). The plan of a month: its own rows, else those of the month before (not saved,
 * not «проведено»), else the articles of expenses marked «постоянная» with a plan of 0. Legacy fell back to its sample list
 * when there were none: here the list is empty (ADR-0002, item 126). The fact of an article is the expenses of the month in
 * the money journal with it as the category or the article, and the lines of the advance reports of the month with it as the
 * category. Legacy saved a month by deleting it and writing it again; here the month is replaced in one transaction, and only
 * if nobody saved it since it was loaded.
 */
class FixedCosts
{
    public const STALE = 'Постоянные затраты месяца изменены другим пользователем — обновите страницу и повторите.';

    /** @return array{period: string, saved: bool, version: string, rows: list<array<string, mixed>>, categories: list<array{code: string, name: string}>, names: array<string, string>, facts: array<string, float>} */
    public function period(string $period): array
    {
        $rows = $this->rows($period);
        $saved = $rows !== [];
        if (! $saved) {
            $rows = array_map(fn ($r) => [...$r, 'id' => null, 'approved' => false], $this->rows(date('Y-m', strtotime("{$period}-01 -1 month"))));
        }
        $categories = DB::table('expense_categories')->where('is_fixed', true)->where('active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['code', 'name'])->map(fn ($c) => ['code' => (string) $c->code, 'name' => (string) $c->name])->all();
        if ($rows === []) {
            $rows = array_map(fn ($c) => ['id' => null, 'name' => $c['name'], 'category' => $c['code'], 'plan' => 0.0, 'approved' => false, 'pay_day' => null], $categories);
        }

        return ['period' => $period, 'saved' => $saved, 'version' => $this->version($period), 'rows' => $rows, 'categories' => $categories,
            'names' => DB::table('expense_categories')->pluck('name', 'code')->map(fn ($n) => (string) $n)->all(), 'facts' => $this->facts($period)];
    }

    /**
     * Replaces the plan of the month.
     *
     * @param  list<array{name: string, category: string, plan: string, approved: bool, pay_day: ?int}>  $rows
     */
    public function save(string $period, string $version, array $rows, User $by): string
    {
        return DB::transaction(function () use ($period, $version, $rows, $by) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["fixed_costs:{$period}"]);
            if (! hash_equals($this->version($period), $version)) {
                throw ValidationException::withMessages(['rows' => self::STALE]);
            }
            DB::table('fixed_costs')->where('period', $period)->delete();
            $now = now();
            foreach ($rows as $r) {
                DB::table('fixed_costs')->insert(['period' => $period, 'name' => $r['name'], 'category' => $r['category'], 'plan' => $r['plan'],
                    'approved' => $r['approved'], 'pay_day' => $r['pay_day'], 'created_at' => $now, 'updated_at' => $now]);
            }
            Audit::record('fixed_costs.saved', 'fixed_costs', $period, ['rows' => count($rows), 'approved' => count(array_filter($rows, fn ($r) => $r['approved']))], $by);

            return $this->version($period);
        });
    }

    /** Legacy getFCFact for every code at once: the money journal and the advance reports of the month. @return array<string, float> */
    public function facts(string $period): array
    {
        $from = "{$period}-01";
        $to = date('Y-m-t', (int) strtotime($from));
        $facts = [];
        $txs = DB::table('money_transactions as t')->leftJoin('expense_categories as c', 'c.id', '=', 't.expense_category_id')
            ->where('t.flow', 'expense')->whereBetween('t.tx_date', [$from, $to])->get(['t.category', 'c.code', 't.amount']);
        foreach ($txs as $t) {
            foreach (array_unique(array_filter([(string) $t->category, (string) $t->code])) as $code) {
                $facts[$code] = bcadd($facts[$code] ?? '0', (string) $t->amount, 2);
            }
        }
        $advances = DB::table('advance_reports')->whereBetween('report_date', [$from, $to])->pluck('lines');
        foreach ($advances as $lines) {
            foreach (json_decode((string) $lines, true) ?: [] as $l) {
                if (is_array($l) && is_string($l['category'] ?? null) && $l['category'] !== '' && is_numeric($l['amount'] ?? null)) {
                    $facts[$l['category']] = bcadd($facts[$l['category']] ?? '0', (string) $l['amount'], 2);
                }
            }
        }

        return array_map('floatval', $facts);
    }

    /** The rows of a month, by name as legacy ordered them. @return list<array<string, mixed>> */
    private function rows(string $period): array
    {
        return DB::table('fixed_costs')->where('period', $period)->orderByRaw('name COLLATE "en-US-x-icu"')->orderBy('id')
            ->get(['id', 'name', 'category', 'plan', 'approved', 'pay_day'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'category' => $r->category, 'plan' => (float) $r->plan, 'approved' => (bool) $r->approved,
                'pay_day' => $r->pay_day === null ? null : (int) $r->pay_day])->all();
    }

    /** What the month holds now: a save is accepted only over the rows it was made from. */
    private function version(string $period): string
    {
        return sha1(json_encode(DB::table('fixed_costs')->where('period', $period)->orderBy('id')
            ->get(['id', 'name', 'category', 'plan', 'approved', 'pay_day', 'updated_at'])) ?: '');
    }
}
