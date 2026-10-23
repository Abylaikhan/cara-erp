<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Финансы» → «📊 P&L» → «Выручка по направлениям» and «Выручка по категориям продукции» (legacy renderRevenueByChannel,
 * renderRevenueByCategory, plChannelForRealization, plFindOrderForRealization, plChannelByCustomerName, plCategoryForProductName,
 * L26502–26630; ADR-0052): the realizations of a year by month, by the channel of their order (or of their customer) and by the
 * category of the product with their name. Amounts are exact; a row is a label and twelve months.
 */
class PlRevenue
{
    public const CHANNEL_ORDER = ['salon_armada', 'salon_nazarbayeva', 'dealer_astana', 'dealer_aktau', 'dealer_atyrau', 'dealer_shymkent', 'dealer_aktobe', 'office'];

    /** @return array{rows: list<array{label: string, data: list<float>}>, matched: int, other: int, samples: list<array{orderNum: string, customer: string}>} */
    public function byChannel(string $year): array
    {
        $labels = $this->labels();
        $channelOf = $this->channelResolver();
        $grid = array_fill_keys(self::CHANNEL_ORDER, array_fill(0, 12, '0'));
        $other = array_fill(0, 12, '0');
        $matched = 0;
        $otherCount = 0;
        $samples = [];
        foreach ($this->realizations($year) as $r) {
            $channel = $channelOf($r);
            $m = (int) substr((string) $r->doc_date, 5, 2) - 1;
            if ($channel !== null && isset($grid[$channel])) {
                $grid[$channel][$m] = bcadd($grid[$channel][$m], (string) ($r->revenue ?? '0'), 2);
                $matched++;
            } else {
                $other[$m] = bcadd($other[$m], (string) ($r->revenue ?? '0'), 2);
                $otherCount++;
                if (count($samples) < 8) {
                    $samples[] = ['orderNum' => $r->order_num ?: '(пусто)', 'customer' => $r->customer ?: '(без имени в реализации)'];
                }
            }
        }
        $rows = [];
        foreach (self::CHANNEL_ORDER as $c) {
            if (array_filter($grid[$c], fn ($v) => bccomp($v, '0', 2) > 0)) {
                $rows[] = ['label' => $labels[$c] ?? $c, 'data' => array_map('floatval', $grid[$c])];
            }
        }
        if (array_filter($other, fn ($v) => bccomp($v, '0', 2) > 0)) {
            $rows[] = ['label' => 'Без канала / прочее', 'data' => array_map('floatval', $other)];
        }

        return ['rows' => $rows, 'matched' => $matched, 'other' => $otherCount, 'samples' => $samples];
    }

    /**
     * Legacy renderRevenueByChannelCategory: the realizations of a year (but those without an amount) by channel, category of
     * product and product; each level by the sum of the year, largest first.
     *
     * @return list<array{key: string, label: string, monthly: list<float>, cats: list<array{key: string, label: string, monthly: list<float>, items: list<array{key: string, label: string, monthly: list<float>}>}>}>
     */
    public function tree(string $year): array
    {
        $labels = $this->labels();
        $channelOf = $this->channelResolver();
        $categoryOf = $this->categoryResolver();
        $tree = [];
        foreach ($this->realizations($year) as $r) {
            $amt = (string) ($r->revenue ?? '0');
            if (bccomp($amt, '0', 2) === 0) {
                continue;
            }
            $m = (int) substr((string) $r->doc_date, 5, 2) - 1;
            $ch = $channelOf($r) ?? 'other';
            $cat = $categoryOf($r->name) ?? 'Без категории';
            $item = $r->name ?: 'Без названия';
            $tree[$ch] ??= ['label' => $labels[$ch] ?? 'Без канала / прочее', 'monthly' => array_fill(0, 12, '0'), 'cats' => []];
            $tree[$ch]['cats'][$cat] ??= ['monthly' => array_fill(0, 12, '0'), 'items' => []];
            $tree[$ch]['cats'][$cat]['items'][$item] ??= array_fill(0, 12, '0');
            $tree[$ch]['monthly'][$m] = bcadd($tree[$ch]['monthly'][$m], $amt, 2);
            $tree[$ch]['cats'][$cat]['monthly'][$m] = bcadd($tree[$ch]['cats'][$cat]['monthly'][$m], $amt, 2);
            $tree[$ch]['cats'][$cat]['items'][$item][$m] = bcadd($tree[$ch]['cats'][$cat]['items'][$item][$m], $amt, 2);
        }
        $sum = fn (array $m) => array_reduce($m, fn ($s, $v) => bcadd($s, $v, 2), '0');
        $sort = function (array $rows) use ($sum): array {
            usort($rows, fn ($a, $b) => bccomp($sum($b['m']), $sum($a['m']), 2));

            return $rows;
        };
        $out = [];
        foreach ($sort(array_map(fn ($code, $c) => ['code' => (string) $code, 'c' => $c, 'm' => $c['monthly']], array_keys($tree), $tree)) as $chRow) {
            $chKey = 'ch:'.$chRow['code'];
            $cats = [];
            foreach ($sort(array_map(fn ($name, $c) => ['name' => (string) $name, 'c' => $c, 'm' => $c['monthly']], array_keys($chRow['c']['cats']), $chRow['c']['cats'])) as $catRow) {
                $catKey = $chKey.'|cat:'.$catRow['name'];
                $items = $sort(array_map(fn ($name, $m) => ['name' => (string) $name, 'm' => $m], array_keys($catRow['c']['items']), $catRow['c']['items']));
                $cats[] = ['key' => $catKey, 'label' => $catRow['name'], 'monthly' => array_map('floatval', $catRow['m']),
                    'items' => array_map(fn ($it) => ['key' => $catKey.'|item:'.$it['name'], 'label' => $it['name'], 'monthly' => array_map('floatval', $it['m'])], $items)];
            }
            $out[] = ['key' => $chKey, 'label' => $chRow['c']['label'], 'monthly' => array_map('floatval', $chRow['m']), 'cats' => $cats];
        }

        return $out;
    }

    /**
     * Legacy renderMaterialCostEstimate (L26971–27103): the goods ESF of a year by month and category of material are spread over
     * the categories of product that may use that material (MATCOST_ELIGIBILITY), in proportion to their revenue of the month;
     * tools, consumables and other go to overheads; a material whose category is unknown, or with no revenue of a category
     * that may use it, stays unallocated. Categories of product and their materials by the sum of the year.
     *
     * @return array{rows: list<array{key: string, label: string, monthly: list<float>, mats: list<array{key: string, label: string, monthly: list<float>}>}>, unmatchedCount: int, unmatchedAmount: float, opr: float, unallocated: float}
     */
    public function costEstimate(string $year): array
    {
        $eligibility = $this->eligibility();
        $matCategoryOf = PlReport::materialCategoryResolver();
        $byMonthMat = [];
        $unmatchedCount = 0;
        $unmatched = '0';
        $lines = DB::table('incoming_invoice_items as i')->join('incoming_invoices as v', 'v.id', '=', 'i.invoice_id')->where('v.invoice_type', 'goods')
            ->where('v.date', '>=', "{$year}-01-01")->where('v.date', '<=', "{$year}-12-31")->orderByDesc('v.date')->orderBy('i.id')->get(['v.date', 'i.description', 'i.total', 'i.amount']);
        foreach ($lines as $l) {
            $amt = bccomp((string) $l->total, '0', 2) !== 0 ? (string) $l->total : (string) $l->amount;
            if (bccomp($amt, '0', 2) === 0) {
                continue;
            }
            $m = (int) substr((string) $l->date, 5, 2) - 1;
            $cat = $matCategoryOf((string) $l->description);
            if ($cat === null) {
                $unmatched = bcadd($unmatched, $amt, 2);
                $unmatchedCount++;

                continue;
            }
            $byMonthMat[$m][$cat] = bcadd($byMonthMat[$m][$cat] ?? '0', $amt, 2);
        }
        $categoryOf = $this->categoryResolver();
        $revenue = [];
        foreach ($this->realizations($year) as $r) {
            $cat = $categoryOf($r->name);
            if ($cat !== null) {
                $m = (int) substr((string) $r->doc_date, 5, 2) - 1;
                $revenue[$m][$cat] = bcadd($revenue[$m][$cat] ?? '0', (string) ($r->revenue ?? '0'), 2);
            }
        }
        $tree = [];
        $opr = '0';
        $unallocated = '0';
        ksort($byMonthMat);
        foreach ($byMonthMat as $m => $mats) {
            foreach ($mats as $mat => $amt) {
                if (! array_key_exists($mat, $eligibility)) {
                    $unallocated = bcadd($unallocated, $amt, 12);

                    continue;
                }
                if ($eligibility[$mat] === null) {
                    $opr = bcadd($opr, $amt, 12);

                    continue;
                }
                $with = array_values(array_filter($eligibility[$mat], fn ($c) => bccomp($revenue[$m][$c] ?? '0', '0', 2) > 0));
                if ($with === []) {
                    $unallocated = bcadd($unallocated, $amt, 12);

                    continue;
                }
                $total = array_reduce($with, fn ($s, $c) => bcadd($s, $revenue[$m][$c], 2), '0');
                foreach ($with as $prod) {
                    $alloc = bcdiv(bcmul($amt, $revenue[$m][$prod], 12), $total, 12);
                    $tree[$prod] ??= ['monthly' => array_fill(0, 12, '0'), 'mats' => []];
                    $tree[$prod]['mats'][$mat] ??= array_fill(0, 12, '0');
                    $tree[$prod]['monthly'][$m] = bcadd($tree[$prod]['monthly'][$m], $alloc, 12);
                    $tree[$prod]['mats'][$mat][$m] = bcadd($tree[$prod]['mats'][$mat][$m], $alloc, 12);
                }
            }
        }
        $sum = fn (array $v) => array_reduce($v, fn ($s, $x) => bcadd($s, $x, 12), '0');
        $rows = [];
        foreach ($tree as $prod => $t) {
            $mats = [];
            foreach ($t['mats'] as $mat => $monthly) {
                $mats[] = ['key' => "cat:{$prod}|mat:{$mat}", 'label' => (string) $mat, 'monthly' => $monthly];
            }
            usort($mats, fn ($a, $b) => bccomp($sum($b['monthly']), $sum($a['monthly']), 12));
            $rows[] = ['key' => "cat:{$prod}", 'label' => (string) $prod, 'monthly' => $t['monthly'], 'mats' => $mats];
        }
        usort($rows, fn ($a, $b) => bccomp($sum($b['monthly']), $sum($a['monthly']), 12));
        $f = fn (array $m) => array_map('floatval', $m);

        return [
            'rows' => array_map(fn ($r) => ['key' => $r['key'], 'label' => $r['label'], 'monthly' => $f($r['monthly']),
                'mats' => array_map(fn ($x) => ['key' => $x['key'], 'label' => $x['label'], 'monthly' => $f($x['monthly'])], $r['mats'])], $rows),
            'unmatchedCount' => $unmatchedCount, 'unmatchedAmount' => (float) $unmatched, 'opr' => (float) $opr, 'unallocated' => (float) $unallocated,
        ];
    }

    /**
     * Legacy getMatCostConfig (L26783): the categories of product that may use each category of material; null — overheads.
     * The categories of finished products are those of the warehouse of finished goods.
     *
     * @return array<string, list<string>|null>
     */
    private function eligibility(): array
    {
        $all = array_values(array_unique(json_decode((string) DB::table('warehouses')->where('code', 'wh_finished')->value('categories'), true) ?: []));
        $soft = ['Диваны', 'Кресла', 'Пуфики', 'Мягкая мебель'];
        $corpus = ['Шкафы', 'Комоды', 'Тумбы', 'Кухни', 'Шкаф AIR', 'Шкаф NEO', 'Шкаф ANY', 'Шкаф SKY', 'Шкаф АЖУР', 'Шкафы и ящики', 'Коллекция Вельвет', 'Коллекция НЕО БЕНТ'];
        $glass = ['Витрины', 'Зеркала', 'Консоли'];
        $mixed = ['Коллекция ГРЕЙС', 'Коллекция ANY', 'Коллекция SKY', 'Коллекция AIR', 'Кабинет', 'Эксклюзив', 'Гостиные DECO', 'Гостиные эконом'];
        $exceptChairs = array_values(array_filter($all, fn ($c) => $c !== 'Стулья'));

        return [
            'Ткань/Обивочные' => [...$soft, ...$mixed],
            'Поролон/Набивка' => [...$soft, ...$mixed],
            'Мягкая фурнитура' => [...$soft, ...$mixed],
            'ЛДСП/МДФ' => [...$corpus, ...$mixed, 'Столы', 'Стулья'],
            'Шпон' => [...$corpus, ...$mixed, 'Столы', 'Стулья'],
            'Пиломатериалы' => ['Столы', 'Стулья', 'Кровати', ...$soft, ...$mixed],
            'Стекло' => $glass,
            'Фурнитура/Крепёж' => $exceptChairs,
            'Крепёж Novus' => $exceptChairs,
            'ЛКМ/Химия' => $all,
            'Металл/Профиль' => ['Шкафы', 'Кухни', 'Витрины'],
            'Инструмент/Абразивы' => null,
            'Расходники' => null,
            'Прочее' => null,
        ];
    }

    /** Legacy PL_CHANNEL_LABELS: the names of the channels, the office «Офис». @return array<string, string> */
    private function labels(): array
    {
        $labels = DB::table('sales_channels')->pluck('name', 'code')->all();
        $labels['office'] = 'Офис';

        return array_intersect_key($labels, array_flip(self::CHANNEL_ORDER));
    }

    /** Legacy plChannelForRealization: the channel of its order, else the customer of the order or of the realization. */
    private function channelResolver(): \Closure
    {
        $orders = DB::table('orders')->orderByDesc('created_at')->orderByDesc('id')->get(['num', 'channel', 'customer'])
            ->map(fn ($o) => ['num' => self::normNum($o->num), 'channel' => $o->channel, 'customer' => $o->customer]);
        $byNum = [];
        foreach ($orders as $o) {
            if ($o['num'] !== '') {
                $byNum[$o['num']] ??= $o;
            }
        }
        $names = DB::table('channel_customer_names')->orderBy('sort_order')->orderBy('id')->pluck('channel', 'name')->all();
        $byName = function (?string $name) use ($names): ?string {
            $n = self::normName($name);
            if ($n === '') {
                return null;
            }
            if (isset($names[$n])) {
                return $names[$n];
            }
            foreach ($names as $key => $channel) {
                if (str_contains($n, (string) $key) || str_contains((string) $key, $n)) {
                    return $channel;
                }
            }

            return null;
        };

        return function (object $r) use ($orders, $byNum, $byName): ?string {
            $n = self::normNum($r->order_num);
            $ord = $n === '' ? null : ($byNum[$n] ?? $orders->first(fn ($o) => $o['num'] !== '' && (str_ends_with($o['num'], $n) || str_ends_with($n, $o['num']))));

            return $ord && $ord['channel'] && in_array($ord['channel'], self::CHANNEL_ORDER, true) ? $ord['channel']
                : (($ord ? $byName($ord['customer']) : null) ?? $byName($r->customer) ?? ($ord ? $ord['channel'] : null));
        };
    }

    /** @return array{rows: list<array{label: string, data: list<float>}>, matched: int, other: int} */
    public function byCategory(string $year): array
    {
        $category = $this->categoryResolver();
        $grid = [];
        $matched = 0;
        $otherCount = 0;
        foreach ($this->realizations($year) as $r) {
            $cat = $category($r->name);
            $cat !== null ? $matched++ : $otherCount++;
            $key = $cat ?? 'Без категории';
            $grid[$key] ??= array_fill(0, 12, '0');
            $m = (int) substr((string) $r->doc_date, 5, 2) - 1;
            $grid[$key][$m] = bcadd($grid[$key][$m], (string) ($r->revenue ?? '0'), 2);
        }
        $rows = array_map(fn ($label, $data) => ['label' => (string) $label, 'data' => $data, 'sum' => array_reduce($data, fn ($s, $v) => bcadd($s, $v, 2), '0')], array_keys($grid), $grid);
        usort($rows, fn ($a, $b) => bccomp($b['sum'], $a['sum'], 2)); // stable: equal sums keep their first appearance

        return ['rows' => array_map(fn ($r) => ['label' => $r['label'], 'data' => array_map('floatval', $r['data'])], $rows), 'matched' => $matched, 'other' => $otherCount];
    }

    /** Legacy plCategoryForProductName over catalogData (the active products by category, then name). */
    private function categoryResolver(): \Closure
    {
        $products = DB::table('products')->where('active', true)->orderBy('category')->orderBy('name')->orderBy('id')->get(['name', 'category'])
            ->map(fn ($p) => [mb_strtolower(trim((string) $p->name)), $p->category ?: 'Без категории']);
        $exact = [];
        foreach ($products as [$n, $c]) {
            $exact[$n] ??= $c;
        }
        $cache = [];

        return function (?string $name) use ($products, $exact, &$cache): ?string {
            $n = mb_strtolower(trim((string) $name));
            if ($n === '') {
                return null;
            }
            if (! array_key_exists($n, $cache)) {
                $cache[$n] = $exact[$n] ?? ($products->first(fn ($p) => $p[0] !== '' && (str_contains($p[0], $n) || str_contains($n, $p[0])))[1] ?? null);
            }

            return $cache[$n];
        };
    }

    /** The realizations of a year, in the order legacy loads them (doc_date.desc). @return Collection<int, object> */
    private function realizations(string $year): Collection
    {
        return DB::table('realizations')->where('doc_date', '>=', "{$year}-01-01")->where('doc_date', '<=', "{$year}-12-31")->orderByDesc('doc_date')->orderBy('id')
            ->get(['doc_date', 'order_num', 'customer', 'name', 'revenue']);
    }

    /** Legacy plNormOrderNum. */
    public static function normNum(?string $s): string
    {
        return (string) preg_replace('/\s+/u', '', mb_strtoupper(trim((string) $s)));
    }

    /** Legacy esfReconNorm. */
    public static function normName(?string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[«»"\']/u', '', mb_strtolower((string) $s))));
    }
}
