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
        $labels = DB::table('sales_channels')->pluck('name', 'code')->all();
        $labels['office'] = 'Офис'; // legacy PL_CHANNEL_LABELS
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
        $grid = array_fill_keys(self::CHANNEL_ORDER, array_fill(0, 12, '0'));
        $other = array_fill(0, 12, '0');
        $matched = 0;
        $otherCount = 0;
        $samples = [];
        foreach ($this->realizations($year) as $r) {
            $n = self::normNum($r->order_num);
            $ord = $n === '' ? null : ($byNum[$n] ?? $orders->first(fn ($o) => $o['num'] !== '' && (str_ends_with($o['num'], $n) || str_ends_with($n, $o['num']))));
            $channel = $ord && $ord['channel'] && in_array($ord['channel'], self::CHANNEL_ORDER, true) ? $ord['channel']
                : (($ord ? $byName($ord['customer']) : null) ?? $byName($r->customer) ?? ($ord ? $ord['channel'] : null));
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

    /** @return array{rows: list<array{label: string, data: list<float>}>, matched: int, other: int} */
    public function byCategory(string $year): array
    {
        // legacy catalogData: the active products by category, then name
        $products = DB::table('products')->where('active', true)->orderBy('category')->orderBy('name')->orderBy('id')->get(['name', 'category'])
            ->map(fn ($p) => [mb_strtolower(trim((string) $p->name)), $p->category ?: 'Без категории']);
        $exact = [];
        foreach ($products as [$n, $c]) {
            $exact[$n] ??= $c;
        }
        $cache = [];
        $category = function (?string $name) use ($products, $exact, &$cache): ?string {
            $n = mb_strtolower(trim((string) $name));
            if ($n === '') {
                return null;
            }
            if (! array_key_exists($n, $cache)) {
                $cache[$n] = $exact[$n] ?? ($products->first(fn ($p) => $p[0] !== '' && (str_contains($p[0], $n) || str_contains($n, $p[0])))[1] ?? null);
            }

            return $cache[$n];
        };
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
